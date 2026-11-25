<?php

namespace App\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/** Tracks only newly generated private files, including files in committed savepoints. */
final class TransactionFileGuard
{
    private array $files = [];

    public function __construct(Dispatcher $events)
    {
        $events->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            $id = spl_object_id($event->connection);
            $level = $event->connection->transactionLevel();
            foreach ($this->files as $key => $file) {
                if ($file['connection'] !== $id) { continue; }
                if ($level === 0) { unset($this->files[$key]); }
                elseif ($file['level'] > $level) { $this->files[$key]['level'] = $level; }
            }
        });
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $id = spl_object_id($event->connection);
            $level = $event->connection->transactionLevel();
            foreach ($this->files as $key => $file) {
                if ($file['connection'] !== $id || $file['level'] <= $level) { continue; }
                unset($this->files[$key]);
                try {
                    // Also protects a committed file if a commit callback failed after
                    // the database had already committed. Never delete on an uncertain lookup.
                    if (!$event->connection->table($file['table'])->where('file_path', $file['path'])->exists()
                        && !($file['delete'])()) {
                        self::logCleanupWarning('Private file rollback cleanup requires review.', ['reference' => hash('sha256', $file['path'])]);
                    }
                } catch (Throwable $error) {
                    self::logCleanupWarning('Private file rollback cleanup deferred.', ['reference' => hash('sha256', $file['path']), 'errorType' => get_class($error)]);
                }
            }
        });
    }

    public function trackLocal(string $path): void
    {
        $disk = Storage::disk('local');
        if ($disk->exists($path)) { throw new LogicException('A new file must not replace an existing path.'); }
        $this->track($path, 'payroll_statements', static fn () => !$disk->exists($path) || $disk->delete($path));
    }

    public function trackHistory(string $path, string $absolutePath): void
    {
        if (file_exists($absolutePath)) { throw new LogicException('A new history must not replace an existing file.'); }
        $this->track($path, 'import_histories', static fn () => !file_exists($absolutePath) || unlink($absolutePath));
    }

    /** A failed diagnostic must never replace a committed result or the original rollback error. */
    public static function logCleanupWarning(string $message, array $context): void
    {
        try { Log::warning($message, $context); }
        catch (Throwable) { /* Keep the original database result when logging is also unavailable. */ }
    }

    /** Read the stored bytes, not only the adapter's success flag or reported file size. */
    public static function streamMatches($stream, string $expected): bool
    {
        if (!is_resource($stream)) { return false; }
        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            return $bytes === strlen($expected) && hash_equals(hash('sha256', $expected), hash_final($hash));
        } finally {
            fclose($stream);
        }
    }

    private function track(string $path, string $table, callable $delete): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) { throw new LogicException('Private files require an active transaction.'); }
        $this->files[] = ['connection' => spl_object_id($connection), 'level' => $connection->transactionLevel(),
            'table' => $table, 'path' => $path, 'delete' => $delete];
    }
}
