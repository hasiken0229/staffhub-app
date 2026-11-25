<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\EmployeeAdminService;
use App\Services\HarmosMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EmploymentTypeHistoryProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): int
    {
        return DB::table('employees')->insertGetId(['employee_code'=>'P1-HISTORY','name'=>'合成 職員',
            'employment_type'=>'PART_TIME','status'=>'ACTIVE','joined_on'=>'2026-01-01','created_at'=>now(),'updated_at'=>now()]);
    }

    private function payload(string $type): array
    {
        return ['employeeCode'=>'P1-HISTORY','name'=>'合成 職員','employmentType'=>$type,'status'=>'ACTIVE','joinedOn'=>'2026-01-01'];
    }

    private function history(int $employee): void
    {
        DB::table('attendance_daily')->insert(['employee_id'=>$employee,'target_date'=>'2026-09-25',
            'clock_in_at'=>'2026-09-25 08:30:00','clock_out_at'=>'2026-09-25 17:30:00','break_minutes'=>60,
            'work_minutes'=>480,'approval_status'=>'APPROVED','close_status'=>'CLOSED','updated_at'=>now()]);
    }

    public function test_history_blocks_a_type_change_without_changing_employee_or_attendance(): void
    {
        $id=$this->employee();$this->history($id);
        $employee=DB::table('employees')->first();$daily=DB::table('attendance_daily')->first();
        try {
            app(EmployeeAdminService::class)->update($id,$this->payload('FULL_TIME'));
            $this->fail('Historical calculation must be protected.');
        } catch (ApiException $exception) {
            $this->assertSame('EMPLOYMENT_TYPE_CHANGE_REQUIRES_REVIEW',$exception->errorCode);
            $this->assertSame(422,$exception->status);
        }
        $this->assertEquals($employee,DB::table('employees')->first());
        $this->assertEquals($daily,DB::table('attendance_daily')->first());
    }

    public function test_other_edits_with_same_type_are_allowed_and_a_new_employee_type_can_be_corrected(): void
    {
        $id=$this->employee();
        $this->assertSame('FULL_TIME',app(EmployeeAdminService::class)->update($id,$this->payload('FULL_TIME'))['employmentType']);
        $this->history($id);
        $payload=$this->payload('FULL_TIME');$payload['name']='更新 合成職員';
        $this->assertSame('更新 合成職員',app(EmployeeAdminService::class)->update($id,$payload)['name']);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$id,'work_minutes'=>480]);
    }

    public function test_employee_csv_preview_and_commit_both_reject_historical_type_change(): void
    {
        $id=$this->employee();$this->history($id);
        $file=fn()=>UploadedFile::fake()->createWithContent('synthetic.csv',"社員番号,姓,名,雇用区分\nP1-HISTORY,合成,職員,FULL_TIME\n");
        $preview=app(EmployeeAdminService::class)->previewImportFromCsv([],$file());
        $this->assertCount(1,$preview['errors']);
        try {
            app(EmployeeAdminService::class)->importFromCsv([],$file());
            $this->fail('CSV must not bypass historical protection.');
        } catch (ApiException $exception) {
            $this->assertSame('CSV_VALIDATION_ERROR',$exception->errorCode);
        }
        $this->assertDatabaseHas('employees',['id'=>$id,'employment_type'=>'PART_TIME']);
    }

    public function test_harmos_preview_and_commit_keep_historical_type_and_mark_the_row_for_review(): void
    {
        Storage::fake('local');
        $id=$this->employee();$this->history($id);
        $file=fn()=>UploadedFile::fake()->createWithContent('synthetic.csv',"社員番号,氏名,雇用形態,入職日\nP1-HISTORY,変更合成職員,正社員,2026/01/01\n");
        $payload=['importType'=>'HARMOS_EMPLOYEE_CSV'];
        $service=app(HarmosMigrationService::class);
        $preview=$service->preview($payload,$file());
        $this->assertSame(1,$preview['errorCount']);
        $this->assertSame('SKIP',$preview['items'][0]['action']);
        $result=$service->import($payload,$file(),null);
        $this->assertSame(0,$result['successCount']);
        $this->assertSame(1,$result['errorCount']);
        $this->assertDatabaseHas('employees',['id'=>$id,'employment_type'=>'PART_TIME','name'=>'合成 職員']);
        $this->assertDatabaseHas('attendance_daily',['employee_id'=>$id,'work_minutes'=>480]);
    }
}
