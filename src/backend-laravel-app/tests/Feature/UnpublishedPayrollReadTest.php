<?php

namespace Tests\Feature;

use App\Services\Payroll\PayrollStatementAdminQueryService;
use App\Services\Payroll\PayrollStatementEmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UnpublishedPayrollReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpublished_statement_is_readable_by_admin_and_hidden_from_employee_without_jobs(): void
    {
        $employeeId=DB::table('employees')->insertGetId(['employee_code'=>'D901','name'=>'確認 職員','employment_type'=>'FULL_TIME','status'=>'ACTIVE','joined_on'=>'2026-01-01','created_at'=>now(),'updated_at'=>now()]);
        $id=DB::table('payroll_statements')->insertGetId(['employee_id'=>$employeeId,'statement_type'=>'PAYROLL','target_year_month'=>'2026-09','file_path'=>'payroll/draft.pdf','original_file_name'=>'draft.pdf','file_size_bytes'=>1,'content_type'=>'application/pdf','published_at'=>null,'uploaded_by'=>$employeeId,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('payroll_statement_lines')->insert(['payroll_statement_id'=>$id,'section_type'=>'SUMMARY','display_order'=>1,'item_label'=>'総支給額','amount'=>250000,'raw_source_key'=>'総支給額']);
        $beforeJobs=DB::table('jobs')->count();
        $detail=app(PayrollStatementAdminQueryService::class)->detailForAdmin($id);
        $this->assertSame('UNPUBLISHED',$detail['notificationStatus']);
        $this->assertNull($detail['publishedAt']);
        $this->assertSame(250000.0,$detail['grossAmount']);
        $visible=app(PayrollStatementEmployeeService::class)->listForEmployee($employeeId,[]);
        $this->assertSame(0,$visible['meta']['total']);
        $this->assertSame($beforeJobs,DB::table('jobs')->count());
    }
}
