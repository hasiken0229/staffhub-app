<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\ImportHistoryService;
use App\Services\Payroll\PayrollStatementStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Throwable;

final class TransactionalFileContentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = "%PDF-1.4\nSYNTHETIC-ORIGINAL";
    private const REPLACEMENT = "%PDF-1.4\nSYNTHETIC-REPLACEMENT";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function payroll(int $id, string $bytes): array
    {
        return app(PayrollStatementStorageService::class)->upsertBinaryForAdmin($id, 'PAYROLL', '2026-09',
            CarbonImmutable::parse('2026-09-30'), 'synthetic.pdf', 'application/pdf', $bytes,
            new GenericUser(['id' => 1, 'role' => 'ADMIN']));
    }

    private function failure(callable $action): Throwable
    {
        try { $action(); } catch (Throwable $error) { return $error; }
        $this->fail('An incomplete or changed saved file must not be accepted.');
    }

    private function assertReplacementRejected(string $mode, bool $cleanupFails = false): void
    {
        $id = DB::table('employees')->insertGetId(['employee_code' => 'P2-INTEGRITY', 'name' => '合成職員',
            'employment_type' => 'FULL_TIME', 'status' => 'ACTIVE', 'joined_on' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now()]);
        $this->payroll($id, self::ORIGINAL);
        $before = DB::table('payroll_statements')->first();
        $noticeCount = DB::table('notices')->count();
        $disk = Storage::disk('local');
        $disk->put('unrelated-original.pdf', 'SYNTHETIC-KEEP');
        $files = $disk->allFiles();
        $proxy = Mockery::mock($disk);
        if ($mode === 'unreadable') {
            $proxy->shouldReceive('readStream')->once()->andReturn(false);
        } else {
            // The adapter reports success although the bytes it persisted are incorrect.
            $proxy->shouldReceive('put')->once()->andReturnUsing(static function ($path, $bytes) use ($disk, $mode) {
                $saved = $mode === 'short' ? substr($bytes, 0, 5) : str_repeat('X', strlen($bytes));
                $disk->put($path, $saved);
                return true;
            });
        }
        if ($cleanupFails) {
            $proxy->shouldReceive('delete')->once()->andReturn(false);
            Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('synthetic logger unavailable'));
        }
        Storage::set('local', $proxy);
        try {
            $error = $this->failure(fn () => $this->payroll($id, self::REPLACEMENT));
        } finally {
            Storage::set('local', $disk);
        }
        $this->assertInstanceOf(ApiException::class, $error);
        $this->assertSame('FILE_UPLOAD_ERROR', $error->errorCode);
        $this->assertEquals($before, DB::table('payroll_statements')->first());
        $this->assertSame($noticeCount, DB::table('notices')->count());
        $this->assertSame(self::ORIGINAL, $disk->get($before->file_path));
        $this->assertSame('SYNTHETIC-KEEP', $disk->get('unrelated-original.pdf'));
        if ($cleanupFails) {
            $this->assertCount(count($files) + 1, $disk->allFiles());
        } else {
            $this->assertSame($files, $disk->allFiles());
        }
    }

    public function test_successful_adapter_with_short_pdf_retains_the_old_original(): void
    {
        $this->assertReplacementRejected('short');
    }

    public function test_same_size_but_changed_pdf_retains_the_old_original(): void
    {
        $this->assertReplacementRejected('corrupt');
    }

    public function test_unreadable_saved_pdf_retains_the_old_original(): void
    {
        $this->assertReplacementRejected('unreadable');
    }

    public function test_cleanup_and_logging_failure_do_not_replace_the_original_upload_error(): void
    {
        $this->assertReplacementRejected('short', true);
    }

    private function withMemoryHistory(string $mode, callable $action): void
    {
        if (!in_array('staffhubintegrity', stream_get_wrappers(), true)) {
            stream_wrapper_register('staffhubintegrity', IntegrityHistoryStream::class);
        }
        IntegrityHistoryStream::$files = [];
        IntegrityHistoryStream::$directories = [];
        IntegrityHistoryStream::$mode = $mode;
        $storage = storage_path();
        $this->app->useStoragePath('staffhubintegrity://synthetic');
        try { $action(); }
        finally { $this->app->useStoragePath($storage); }
    }

    private function saveHistory(): int
    {
        return app(ImportHistoryService::class)->storeAndRecord('P2_INTEGRITY', 'synthetic.csv', null, null,
            1, 1, 0, [], 'SYNTHETIC-CSV-ORIGINAL', 'synthetic.csv', 'text/csv');
    }

    public function test_short_history_write_rolls_back_row_and_removes_only_the_new_file(): void
    {
        $this->withMemoryHistory('short', function (): void {
            IntegrityHistoryStream::$files['staffhubintegrity://synthetic/unrelated'] = 'SYNTHETIC-KEEP';
            $this->failure(fn () => $this->saveHistory());
            $this->assertDatabaseCount('import_histories', 0);
            $this->assertSame(['staffhubintegrity://synthetic/unrelated' => 'SYNTHETIC-KEEP'], IntegrityHistoryStream::$files);
        });
    }

    public function test_full_count_but_corrupted_history_write_is_not_committed(): void
    {
        $this->withMemoryHistory('corrupt', function (): void {
            $error = $this->failure(fn () => $this->saveHistory());
            $this->assertInstanceOf(ApiException::class, $error);
            $this->assertSame('FILE_EXPORT_ERROR', $error->errorCode);
            $this->assertDatabaseCount('import_histories', 0);
            $this->assertSame([], IntegrityHistoryStream::$files);
        });
    }

    public function test_verified_history_is_recorded_with_exact_original_bytes(): void
    {
        $this->withMemoryHistory('normal', function (): void {
            $historyId = $this->saveHistory();
            $this->assertDatabaseHas('import_histories', ['id' => $historyId, 'success_count' => 1]);
            $this->assertSame(['SYNTHETIC-CSV-ORIGINAL'], array_values(IntegrityHistoryStream::$files));
        });
    }
}

/** In-memory stream; never writes a real file or contacts an external service. */
final class IntegrityHistoryStream
{
    public $context;
    public static array $files = [];
    public static array $directories = [];
    public static string $mode = 'normal';
    private string $path;
    private int $position = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $this->path = $path;
        if (str_starts_with($mode, 'w')) { self::$files[$path] = ''; }
        return array_key_exists($path, self::$files);
    }

    public function stream_write($bytes): int
    {
        if (self::$mode === 'short') {
            if (self::$files[$this->path] !== '') { return 0; }
            self::$files[$this->path] = substr($bytes, 0, 3);
            return 3;
        }
        self::$files[$this->path] .= self::$mode === 'corrupt' ? str_repeat('X', strlen($bytes)) : $bytes;
        return strlen($bytes);
    }

    public function stream_read($count): string
    {
        $bytes = substr(self::$files[$this->path], $this->position, $count);
        $this->position += strlen($bytes);
        return $bytes;
    }

    public function stream_eof(): bool { return $this->position >= strlen(self::$files[$this->path]); }
    public function stream_stat(): array { return ['mode' => 0100666, 'size' => strlen(self::$files[$this->path])]; }
    public function stream_flush(): bool { return true; }
    public function stream_close(): void {}
    public function url_stat($path, $flags): array|false
    {
        if (array_key_exists($path, self::$files)) { return ['mode' => 0100666, 'size' => strlen(self::$files[$path])]; }
        return isset(self::$directories[$path]) ? ['mode' => 0040777, 'size' => 0] : false;
    }
    public function mkdir($path, $mode, $options): bool { self::$directories[$path] = true; return true; }
    public function unlink($path): bool { unset(self::$files[$path]); return true; }
}
