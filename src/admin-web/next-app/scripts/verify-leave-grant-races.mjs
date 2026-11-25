import fs from 'node:fs/promises';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
const root = process.argv[2] && path.resolve(process.argv[2]);
if (!root) throw new Error('Provide the isolated verification evidence directory.');
const saved = JSON.parse(await fs.readFile(path.join(root, 'test-environment.json'), 'utf8'));
if (saved.STAFFHUB_E2E !== 'true' || saved.DB_CONNECTION !== 'mysql' || path.resolve(saved.STAFFHUB_VERIFICATION_ROOT) !== root
    || !saved.DB_DATABASE.startsWith('staffhub_verification_') || !['127.0.0.1', 'localhost', '::1'].includes(saved.DB_HOST)) {
  throw new Error('Only the isolated local verification environment is accepted.');
}
const evidence = await fs.mkdtemp(path.join(root, 'leave-grant-race-'));
const backend = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../backend-laravel-app');
const php = process.env.STAFFHUB_PHP ?? (process.platform === 'win32' ? 'C:/php/php.exe' : 'php');
const phpArgs = process.platform === 'win32' ? ['-d', 'extension=pdo_mysql', '-d', 'extension=fileinfo'] : [];
function start(args) {
  return new Promise((resolve, reject) => {
    const child = spawn(php, [...phpArgs, 'scripts/verification/leave-grant-race.php', ...args], { cwd: backend,
      env: { ...process.env, ...saved, LEAVE_RACE_EVIDENCE: evidence }, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let log = ''; child.stdout.on('data', b => log += b); child.stderr.on('data', b => log += b);
    child.on('error', reject); child.on('exit', code => code === 0 ? resolve() : reject(new Error(log)));
  });
}
async function waitFor(file) {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try { await fs.access(file); return; } catch { await new Promise(resolve => setTimeout(resolve, 50)); }
  }
  throw new Error('Leave race barrier was not acquired.');
}
await start(['--setup']);
const results = [];
for (const name of ['confirm-first', 'review-before-import']) {
  const first = start([`--case=${name}`, '--role=first']);
  await Promise.race([waitFor(path.join(evidence, `${name}.locked`)), first.then(() => { throw new Error('First writer exited before the barrier.'); })]);
  const second = start([`--case=${name}`, '--role=second']);
  await Promise.race([waitFor(path.join(evidence, `${name}.second.ready`)), second.then(() => { throw new Error('Second writer exited before starting its operation.'); })]);
  await new Promise(resolve => setTimeout(resolve, 600));
  await assert.rejects(fs.access(path.join(evidence, `${name}.second.json`)));
  await fs.writeFile(path.join(evidence, `${name}.release`), '1');
  await Promise.all([first, second]);
  const one = JSON.parse(await fs.readFile(path.join(evidence, `${name}.first.json`), 'utf8'));
  const two = JSON.parse(await fs.readFile(path.join(evidence, `${name}.second.json`), 'utf8'));
  assert.equal(one.status, 'COMMITTED');
  if (name === 'confirm-first') { assert.equal(two.status, 'REJECTED'); assert.ok(['NOT_FOUND', 'STALE_LEAVE_PREVIEW'].includes(two.code)); }
  else { assert.equal(two.status, 'COMMITTED'); assert.equal(two.successCount, 0); assert.equal(two.errorCount, 1); }
  for (const value of [one, two]) {
    assert.equal(value.activeCount, 2); assert.equal(value.activeDays, 10); assert.equal(value.originalDays, 10); assert.equal(value.reviewAuditCount, 1);
  }
  results.push({ case: name, first: one, second: two });
}
await fs.writeFile(path.join(evidence, 'results.json'), JSON.stringify(results, null, 2));
console.log(`Both MySQL leave grant review races passed. Evidence: ${evidence}`);
