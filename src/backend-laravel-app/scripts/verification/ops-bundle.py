"""Explicit MySQL inventory and stopped-writer backup/rehearsal restore. No app boot."""
from __future__ import annotations

import argparse
import configparser
from contextlib import nullcontext
import datetime as dt
import gzip
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import time


def utc() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat()


def digest(path: Path) -> str:
    value = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            value.update(chunk)
    return value.hexdigest()


def safe_name(value: str) -> str:
    if not re.fullmatch(r'[A-Za-z0-9_]+', value):
        raise ValueError('Database/table identifier is unsupported.')
    return value


class MySQL:
    def __init__(self, options):
        self.options = options
        self.database = safe_name(options.database)
        raw = Path(options.defaults_file).read_text(encoding='utf-8-sig')
        if re.search(r'^\s*!', raw, flags=re.MULTILINE):
            raise ValueError('Option-file includes are not supported.')
        config = configparser.RawConfigParser()
        config.read_string(raw)
        if config.sections() != ['client']:
            raise ValueError('Use a dedicated [client] option file only.')
        if set(config['client']) - {'host', 'port', 'protocol', 'user', 'password', 'ssl-mode'}:
            raise ValueError('Unsupported client setting; use an explicit TCP connection file.')
        self.host = config['client'].get('host', '')
        self.port = int(config['client'].get('port', '3306'))
        if config['client'].get('protocol', 'TCP').upper() != 'TCP':
            raise ValueError('An explicit TCP connection is required.')
        self.common = ['--defaults-file=' + str(Path(options.defaults_file).absolute()), '--default-character-set=utf8mb4']
        self._dump_supports_mysql_gtid_option = None

    def command(self, program: str, arguments: list[str], data=None) -> bytes:
        result = subprocess.run([program, *self.common, *arguments], input=data,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        if result.returncode:
            raise RuntimeError('MySQL command failed. SQL/data/credentials suppressed.')
        return result.stdout

    def query(self, sql: str) -> list[list[str]]:
        output = self.command(self.options.mysql, ['--batch', '--raw', '--skip-column-names', self.database, '-e', sql])
        return [line.split('\t') for line in output.decode('utf-8').splitlines()]

    def inventory(self) -> dict:
        version, collation, sql_mode = self.query('SELECT VERSION(),@@collation_server,@@sql_mode')[0]
        tables = self.query('SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE=\'BASE TABLE\' ORDER BY TABLE_NAME')
        columns = self.query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'attendance_devices\' ORDER BY ORDINAL_POSITION')
        wanted = ['reader_status', 'client_time_at', 'clock_drift_seconds', 'pending_offline_count']
        object_counts = {}
        for kind, table, schema in [('views', 'VIEWS', 'TABLE_SCHEMA'), ('triggers', 'TRIGGERS', 'TRIGGER_SCHEMA'), ('routines', 'ROUTINES', 'ROUTINE_SCHEMA'), ('events', 'EVENTS', 'EVENT_SCHEMA')]:
            object_counts[kind] = int(self.query(f'SELECT COUNT(*) FROM information_schema.{table} WHERE {schema}=DATABASE()')[0][0])
        return {'capturedAt': utc(), 'version': version, 'serverCollation': collation, 'sqlMode': sql_mode,
                'tables': [{'name': row[0], 'engine': row[1], 'collation': row[2]} for row in tables],
                'attendanceDeviceColumns': [{'name': row[0], 'type': row[1], 'nullable': row[2]} for row in columns],
                'missingDeviceDiagnosticColumns': [name for name in wanted if name not in {row[0] for row in columns}],
                'storedObjects': object_counts, 'credentialsOrRowValuesIncluded': False}

    def dump(self, output: Path | None, table: str | None = None) -> str:
        if self._dump_supports_mysql_gtid_option is None:
            version = self.command(self.options.mysqldump, ['--version']).lower()
            self._dump_supports_mysql_gtid_option = b'mariadb' not in version
        args = ['--single-transaction', '--quick', '--hex-blob', '--skip-lock-tables',
                '--no-tablespaces', '--skip-add-drop-table', '--skip-add-locks', '--skip-disable-keys',
                '--skip-comments', '--skip-extended-insert', '--order-by-primary']
        if self._dump_supports_mysql_gtid_option:
            args.append('--set-gtid-purged=OFF')
        if table:
            args += ['--no-create-info', '--compact']
        args += [self.database] + ([safe_name(table)] if table else [])
        process = subprocess.Popen([self.options.mysqldump, *self.common, *args], stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        hashed = hashlib.sha256()
        with (gzip.open(output, 'xb') if output else nullcontext(None)) as handle:
            for chunk in iter(lambda: process.stdout.read(1024 * 1024), b''):
                hashed.update(chunk)
                if handle:
                    handle.write(chunk)
        process.stderr.read()
        if process.wait():
            raise RuntimeError('mysqldump failed; incomplete output retained, sensitive stderr suppressed.')
        return hashed.hexdigest()

    def fingerprints(self, inventory: dict) -> dict:
        values = {}
        for table in inventory['tables']:
            name = safe_name(table['name'])
            values[name] = {'rows': int(self.query(f'SELECT COUNT(*) FROM `{name}`')[0][0]), 'dataSha256': self.dump(None, name)}
        return values


def tree_inventory(root: Path) -> list[dict]:
    if not root.is_dir() or root.is_symlink() or (hasattr(root, 'is_junction') and root.is_junction()):
        raise ValueError('Each source must be a real directory.')
    files = []
    for path in sorted(root.rglob('*')):
        if path.is_symlink() or (hasattr(path, 'is_junction') and path.is_junction()):
            raise ValueError('Symlinks/junctions are unsupported.')
        if path.is_file():
            info = path.stat()
            files.append({'path': path.relative_to(root).as_posix(), 'bytes': info.st_size, 'sha256': digest(path), 'mtimeNs': info.st_mtime_ns})
    return files


def write_json(path: Path, value: dict) -> None:
    with path.open('x', encoding='utf-8') as handle:
        json.dump(value, handle, ensure_ascii=False, indent=2)
    path.chmod(0o600)


def assert_supported(inventory: dict) -> None:
    if any(inventory['storedObjects'].values()):
        raise ValueError('Views/routines/events/triggers require a separately reviewed backup plan.')
    if any(table['engine'] != 'InnoDB' for table in inventory['tables']):
        raise ValueError('This bundle supports InnoDB tables only.')


def backup(options, db: MySQL) -> dict:
    if not options.writers_stopped:
        raise ValueError('Stop ALL application/card/queue/import/scheduler writers, then supply --writers-stopped.')
    started = utc()
    clock = time.monotonic()
    target = Path(options.output).absolute()
    if target.exists():
        raise ValueError('Backup output must not exist.')
    roots = {'private': Path(options.private_root).absolute(), 'settings': Path(options.settings_root).absolute()}
    for root in roots.values():
        if target == root or root in target.parents or target in root.parents:
            raise ValueError('Output and source roots must be separate.')
    inventory = db.inventory()
    assert_supported(inventory)
    before_files = {name: tree_inventory(root) for name, root in roots.items()}
    before_db = db.fingerprints(inventory)
    target.mkdir(parents=True, mode=0o700, exist_ok=False)
    db.dump(target / 'database.sql.gz')
    for name, root in roots.items():
        (target / name).mkdir(mode=0o700)
        for entry in before_files[name]:
            source = root / entry['path']
            destination = target / name / entry['path']
            destination.parent.mkdir(parents=True, exist_ok=True)
            with source.open('rb') as incoming, destination.open('xb') as outgoing:
                shutil.copyfileobj(incoming, outgoing)
            destination.chmod(0o600)
            if digest(destination) != entry['sha256']:
                raise RuntimeError('A source file changed during backup. Bundle incomplete.')
    after_files = {name: tree_inventory(root) for name, root in roots.items()}
    after_db = db.fingerprints(inventory)
    if before_files != after_files or before_db != after_db:
        raise RuntimeError('Source changed during backup. Bundle incomplete; writers must remain stopped.')
    manifest = {'format': 'staffhub-stopped-bundle-v1', 'startedAt': started, 'completedAt': utc(),
                'seconds': round(time.monotonic() - clock, 3), 'consistency': 'all writers stopped by operator; before/after hashes checked',
                'inventory': inventory, 'database': before_db, 'databaseDumpSha256': digest(target / 'database.sql.gz'),
                'files': before_files}
    write_json(target / 'manifest.json', manifest)
    return {'operation': 'backup', 'startedAt': started, 'completedAt': manifest['completedAt'], 'seconds': manifest['seconds'],
            'tables': len(before_db), 'rows': sum(value['rows'] for value in before_db.values()),
            'privateFiles': len(before_files['private']), 'settingsFiles': len(before_files['settings']),
            'manifestSha256': digest(target / 'manifest.json'), 'databaseDumpSha256': manifest['databaseDumpSha256'],
            'consistency': manifest['consistency'], 'sensitiveContentsIncludedInReport': False}


def checked_path(root: Path, relative: str) -> Path:
    parsed = PurePosixPath(relative)
    if parsed.is_absolute() or not parsed.parts or any(part in ('', '.', '..') or ':' in part or '\\' in part for part in parsed.parts):
        raise ValueError('Unsafe bundle path.')
    path = root.joinpath(*parsed.parts)
    for parent in [path, *path.parents]:
        if parent == root.parent:
            break
        if parent.is_symlink() or (hasattr(parent, 'is_junction') and parent.is_junction()):
            raise ValueError('Bundle path contains a symlink/junction.')
    return path


def restore(options, db: MySQL) -> dict:
    if db.host not in ('127.0.0.1', 'localhost', '::1') or not re.fullmatch(r'staffhub_verification_[a-z0-9_]+', db.database):
        raise ValueError('Restore permits localhost and staffhub_verification_* databases only.')
    if not options.confirm_empty_db:
        raise ValueError('--confirm-empty-db is mandatory for rehearsal restore.')
    target = Path(options.destination).absolute()
    if target.exists():
        raise ValueError('Restore destination must not exist.')
    for parent in target.parents:
        if parent.is_symlink() or (hasattr(parent, 'is_junction') and parent.is_junction()):
            raise ValueError('Restore destination parent is a symlink/junction.')
    source = Path(options.bundle).absolute()
    if source == target or source in target.parents or target in source.parents:
        raise ValueError('Bundle and restore destination must be separate.')
    manifest = json.loads((source / 'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('format') != 'staffhub-stopped-bundle-v1':
        raise ValueError('Unsupported bundle format.')
    assert_supported(manifest['inventory'])
    if set(manifest['files']) != {'private', 'settings'}:
        raise ValueError('Unsupported bundle files.')
    if digest(source / 'database.sql.gz') != manifest['databaseDumpSha256']:
        raise ValueError('Database dump hash mismatch.')
    entries = []
    for group, files in manifest['files'].items():
        seen = set()
        for entry in files:
            if entry['path'].casefold() in seen:
                raise ValueError('Duplicate/colliding bundle path.')
            seen.add(entry['path'].casefold())
            origin = checked_path(source / group, entry['path'])
            if origin.stat().st_size != entry['bytes'] or digest(origin) != entry['sha256']:
                raise ValueError('Private/settings file hash mismatch.')
            entries.append((group, entry, origin))
    empty = db.inventory()
    if empty['tables'] or any(empty['storedObjects'].values()):
        raise ValueError('Restore database must already exist and be completely empty.')
    started = utc()
    clock = time.monotonic()
    target.mkdir(parents=True, mode=0o700, exist_ok=False)
    with gzip.open(source / 'database.sql.gz', 'rb') as sql:
        process = subprocess.Popen([options.mysql, *db.common, '--binary-mode=1', db.database], stdin=subprocess.PIPE,
                                   stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
                                   creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        try:
            shutil.copyfileobj(sql, process.stdin)
            process.stdin.close()
            error = process.stderr.read()
            code = process.wait()
        except Exception:
            process.kill()
            process.wait()
            raise
        if code:
            raise RuntimeError('Restore SQL failed. Fresh rehearsal targets retained; no cleanup/overwrite attempted.')
    for group, entry, origin in entries:
        destination = checked_path(target / group, entry['path'])
        destination.parent.mkdir(parents=True, exist_ok=True)
        with origin.open('rb') as incoming, destination.open('xb') as outgoing:
            shutil.copyfileobj(incoming, outgoing)
        destination.chmod(0o600)
        if digest(destination) != entry['sha256']:
            raise RuntimeError('Restored file hash mismatch.')
    result_db = db.fingerprints(db.inventory())
    if result_db != manifest['database']:
        raise RuntimeError('Restored table counts/data hashes differ.')
    result = {'operation': 'restore-rehearsal', 'startedAt': started, 'completedAt': utc(),
              'seconds': round(time.monotonic() - clock, 3), 'tables': len(result_db),
              'rows': sum(value['rows'] for value in result_db.values()),
              'privateFiles': len(manifest['files']['private']), 'settingsFiles': len(manifest['files']['settings']),
              'tableCountsAndHashesMatched': True, 'fileHashesMatched': True, 'manifestSha256': digest(source / 'manifest.json'),
              'sensitiveContentsIncludedInReport': False, 'productionRestored': False}
    write_json(target / 'restore-verification.json', result)
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['inventory', 'backup', 'restore'])
    parser.add_argument('--mysql', required=True)
    parser.add_argument('--mysqldump', required=True)
    parser.add_argument('--defaults-file', required=True, help='Dedicated [client] file; never put a password on the command line.')
    parser.add_argument('--database', required=True)
    parser.add_argument('--output')
    parser.add_argument('--private-root')
    parser.add_argument('--settings-root')
    parser.add_argument('--writers-stopped', action='store_true')
    parser.add_argument('--bundle')
    parser.add_argument('--destination')
    parser.add_argument('--confirm-empty-db', action='store_true')
    options = parser.parse_args()
    required = {'inventory': ['output'], 'backup': ['output', 'private_root', 'settings_root'], 'restore': ['bundle', 'destination']}[options.mode]
    if any(not getattr(options, key) for key in required):
        parser.error('Missing mode-specific options: ' + ', '.join(required))
    db = MySQL(options)
    if options.mode == 'inventory':
        result = db.inventory()
        write_json(Path(options.output), result)
        result = {'operation': 'inventory', 'tables': len(result['tables']), 'version': result['version'],
                  'missingDeviceDiagnosticColumns': result['missingDeviceDiagnosticColumns'], 'sha256': digest(Path(options.output)),
                  'credentialsOrRowValuesIncluded': False}
    else:
        result = backup(options, db) if options.mode == 'backup' else restore(options, db)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except Exception as exception:
        # Third-party errors can contain row values, SQL, paths or credentials.
        print(json.dumps({'success': False, 'errorType': type(exception).__name__, 'message': 'Operation failed; no source deletion or automatic retry. Check mode guards and prerequisites.'}), file=sys.stderr)
        raise SystemExit(1)
