<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\SystemMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DeploymentSchemaParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_database_contains_the_production_import_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('employees', ['is_management_supervisor', 'is_dependent_within']));
        $this->assertTrue(Schema::hasColumns('attendance_daily', [
            'harmos_total_work_minutes', 'harmos_work_type_id', 'harmos_leave_summary', 'paid_leave_credit_minutes',
        ]));
        $this->assertTrue(Schema::hasTable('harmos_monthly_attendance_summaries'));
        $this->assertTrue(Schema::hasColumns('attendance_devices', [
            'client_time_at', 'clock_drift_seconds', 'pending_offline_count', 'reader_status',
        ]));
    }

    public function test_forward_reconciliation_preserves_existing_master_and_device_rows(): void
    {
        $masters = DB::table('employment_type_settings');
        $masters->where('code', 'PART_TIME')->update(['label' => '園の非常勤', 'standard_day_minutes' => 360]);
        $masters->insert(['code' => 'LEGACY', 'label' => '旧登録']);
        $deviceId = DB::table('attendance_devices')->insertGetId([
            'device_code' => 'QA-SCHEMA', 'name' => '検証用',
            'pending_offline_count' => 5, 'reader_status' => 'READY',
        ]);
        foreach ([
            '2026_07_17_000002_normalize_employee_employment_categories',
            '2026_07_17_000003_add_harmos_leave_import_fields_to_attendance_daily',
            '2026_07_18_000004_create_harmos_monthly_attendance_summaries_table',
            '2026_08_30_000001_add_heartbeat_status_to_attendance_devices_table',
        ] as $name) {
            (require database_path("migrations/$name.php"))->up();
        }
        $this->assertDatabaseHas('employment_type_settings', ['code' => 'PART_TIME', 'label' => '園の非常勤', 'standard_day_minutes' => 360]);
        $this->assertDatabaseHas('employment_type_settings', ['code' => 'LEGACY', 'label' => '旧登録']);
        $this->assertDatabaseHas('attendance_devices', ['id' => $deviceId, 'pending_offline_count' => 5, 'reader_status' => 'READY']);
        $this->assertSame(['FULL_TIME', 'PART_TIME'], array_column(app(SystemMasterService::class)->overview()['employmentTypes'], 'code'));
        $this->expectException(ApiException::class);
        app(SystemMasterService::class)->storeEmploymentType(['code' => 'TEMPORARY', 'label' => '臨時']);
    }
}
