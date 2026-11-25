<?php

namespace Tests\Feature;

use App\Services\HarmosMigrationService;
use App\Services\ImportHistoryService;
use App\Services\Payroll\PayrollStatementStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

final class TransactionalFileSafetyTest extends TestCase
{
    use RefreshDatabase;

    private bool $waitingForWriteFailure = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function employee(): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>'P2-FILES','name'=>'合成職員',
            'employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-01-01','created_at'=>now(),'updated_at'=>now()]);
    }

    private function payroll(int $id, string $bytes="%PDF-1.4\nSYNTHETIC-ORIGINAL"): array
    {
        return app(PayrollStatementStorageService::class)->upsertBinaryForAdmin($id,'PAYROLL','2026-09',
            CarbonImmutable::parse('2026-09-30'),'synthetic.pdf','application/pdf',$bytes,new GenericUser(['id'=>1,'role'=>'ADMIN']));
    }

    private function failWrite(string $table, string $verb='INSERT'): void
    {
        $connection = DB::connection();
        $quotedTable = $connection->getQueryGrammar()->wrapTable($table);
        $prefix = strtolower(($verb === 'UPDATE' ? 'update ' : 'insert into ').$quotedTable.' ');
        $this->waitingForWriteFailure = true;
        $connection->beforeExecuting(function (string $sql) use ($connection, $prefix, $quotedTable) {
            if ($this->waitingForWriteFailure && str_starts_with(strtolower(ltrim($sql)), $prefix)) {
                // Fail once on the same PDO connection while the real transaction is open.
                // DDL in this callback would implicitly commit the transaction on MySQL.
                $this->waitingForWriteFailure = false;
                $connection->getPdo()->query("select staffhub_synthetic_missing_column from $quotedTable");
            }
        });
    }

    private function failure(callable $action): Throwable
    {
        try { $action(); } catch (Throwable $error) {
            $this->assertFalse($this->waitingForWriteFailure, 'The operation failed before the target database write.');
            return $error;
        }
        $this->fail('The injected storage/database failure must be surfaced.');
    }

    private function nativeHistoryFiles(): array
    {
        $root=storage_path('app/private/file-history');
        if (!is_dir($root)) { return []; }
        $result=[];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) { $result[$file->getPathname()]=hash_file('sha256',$file->getPathname()); }
        }
        ksort($result);return $result;
    }

    public function test_payroll_insert_failure_removes_only_the_new_pdf(): void
    {
        $id=$this->employee();
        Storage::disk('local')->put('unrelated-original.pdf','SYNTHETIC-KEEP');
        $this->failWrite('payroll_statements');
        $this->failure(fn()=> $this->payroll($id));
        $this->assertSame(['unrelated-original.pdf'],Storage::disk('local')->allFiles());
        $this->assertSame('SYNTHETIC-KEEP',Storage::disk('local')->get('unrelated-original.pdf'));
        $this->assertDatabaseCount('payroll_statements',0);
        $this->assertDatabaseCount('notices',0);
    }

    public function test_replacement_update_failure_retains_old_bytes_and_cleans_new_pdf(): void
    {
        $id=$this->employee();$this->payroll($id);
        $before=DB::table('payroll_statements')->first();
        $files=Storage::disk('local')->allFiles();
        $original=Storage::disk('local')->get($before->file_path);
        $this->failWrite('payroll_statements','UPDATE');
        $this->failure(fn()=> $this->payroll($id,"%PDF-1.4\nSYNTHETIC-REPLACEMENT"));
        $this->assertEquals($before,DB::table('payroll_statements')->first());
        $this->assertSame($files,Storage::disk('local')->allFiles());
        $this->assertSame($original,Storage::disk('local')->get($before->file_path));
    }

    public function test_parent_rollback_after_inner_commit_removes_new_pdf_and_preserves_previous_version(): void
    {
        $id=$this->employee();$this->payroll($id);
        $before=DB::table('payroll_statements')->first();$files=Storage::disk('local')->allFiles();
        DB::beginTransaction();
        $this->payroll($id,"%PDF-1.4\nSYNTHETIC-ROLLBACK");
        $this->assertCount(2,Storage::disk('local')->allFiles());
        DB::rollBack();
        $this->assertEquals($before,DB::table('payroll_statements')->first());
        $this->assertSame($files,Storage::disk('local')->allFiles());
        $this->assertSame("%PDF-1.4\nSYNTHETIC-ORIGINAL",Storage::disk('local')->get($before->file_path));
    }

    public function test_history_database_failure_cleans_new_native_file(): void
    {
        $files=$this->nativeHistoryFiles();$this->failWrite('import_histories');
        $this->failure(fn()=>app(ImportHistoryService::class)->storeAndRecord('P2_SYNTHETIC','synthetic.csv',null,null,1,1,0,[],
            'synthetic-only','synthetic.csv','text/csv'));
        $this->assertSame($files,$this->nativeHistoryFiles());
        $this->assertDatabaseCount('import_histories',0);
    }

    public function test_harmos_history_failure_rolls_back_employee_and_file_then_retry_succeeds_once(): void
    {
        $files=$this->nativeHistoryFiles();$this->failWrite('import_histories');
        $csv=fn()=>UploadedFile::fake()->createWithContent('synthetic.csv',"社員番号,氏名,入職日\nP2-NEW,合成職員,2026/01/01\n");
        $service=app(HarmosMigrationService::class);$payload=['importType'=>'HARMOS_EMPLOYEE_CSV'];
        $this->failure(fn()=> $service->import($payload,$csv(),null));
        $this->assertDatabaseCount('employees',0);$this->assertDatabaseCount('import_histories',0);
        $this->assertSame($files,$this->nativeHistoryFiles());
        $result=$service->import($payload,$csv(),null);
        $this->assertSame(1,$result['successCount']);
        $this->assertDatabaseCount('employees',1);$this->assertDatabaseCount('import_histories',1);
        $this->assertCount(count($files)+1,$this->nativeHistoryFiles());
    }

    public function test_harmos_file_failure_rolls_back_employee_and_does_not_remove_the_blocking_original(): void
    {
        $originalStorage=storage_path();
        $this->app->useStoragePath($originalStorage.'/p2-failure-'.bin2hex(random_bytes(6)));
        $parent=storage_path('app/private/file-history');
        if (!is_dir($parent)) { mkdir($parent,0775,true); }
        $blocker=$parent.'/harmos_employee_csv';
        file_put_contents($blocker,'SYNTHETIC-BLOCKING-ORIGINAL');
        try {
            $csv=UploadedFile::fake()->createWithContent('synthetic.csv',"社員番号,氏名,入職日\nP2-NEW,合成職員,2026/01/01\n");
            $this->failure(fn()=>app(HarmosMigrationService::class)->import(['importType'=>'HARMOS_EMPLOYEE_CSV'],$csv,null));
            $this->assertDatabaseCount('employees',0);$this->assertDatabaseCount('import_histories',0);
            $this->assertSame('SYNTHETIC-BLOCKING-ORIGINAL',file_get_contents($blocker));
        } finally { unlink($blocker); $this->app->useStoragePath($originalStorage); }
    }

    public function test_rollback_of_a_nested_history_after_inner_commit_cleans_its_file(): void
    {
        $before=$this->nativeHistoryFiles();
        DB::beginTransaction();
        app(ImportHistoryService::class)->storeAndRecord('P2_NESTED','synthetic.csv',null,null,1,1,0,[],
            'synthetic-only','synthetic.csv','text/csv');
        $this->assertCount(count($before)+1,$this->nativeHistoryFiles());
        DB::rollBack();
        $this->assertSame($before,$this->nativeHistoryFiles());
        $this->assertDatabaseCount('import_histories',0);
    }

    public function test_harmos_previews_do_not_create_rows_or_files(): void
    {
        $before=$this->nativeHistoryFiles();
        $csv=UploadedFile::fake()->createWithContent('synthetic.csv',"社員番号,氏名,入職日\nP2-PREVIEW,合成職員,2026/01/01\n");
        $result=app(HarmosMigrationService::class)->preview(['importType'=>'HARMOS_EMPLOYEE_CSV'],$csv);
        $this->assertTrue($result['dryRun']);
        $this->assertDatabaseCount('employees',0);$this->assertDatabaseCount('import_histories',0);
        $this->assertSame($before,$this->nativeHistoryFiles());
    }
}
