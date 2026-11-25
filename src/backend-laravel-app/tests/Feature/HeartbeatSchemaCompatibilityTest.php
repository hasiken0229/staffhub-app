<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\AttendanceAdminController;
use App\Services\Attendance\PunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HeartbeatSchemaCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-28 09:00:00'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function device(): int
    {
        return DB::table('attendance_devices')->insertGetId(['device_code'=>'SCHEMA-SYNTHETIC','name'=>'合成端末',
            'device_secret_hash'=>Hash::make('synthetic-only'),'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function heartbeat(): array
    {
        return app(PunchService::class)->heartbeat(['deviceCode'=>'SCHEMA-SYNTHETIC','deviceSecret'=>'synthetic-only',
            'appVersion'=>'synthetic-test','lastSeenAt'=>now()->addSeconds(90)->toIso8601String(),
            'pendingOfflineCount'=>0,'readerStatus'=>'SYNTHETIC reader']);
    }

    private function listed(): array
    {
        return app(AttendanceAdminController::class)->devices()->getData(true)['data'][0];
    }

    public function test_before_first_detail_heartbeat_zero_default_is_unknown_then_reported_zero_is_visible(): void
    {
        $this->device();
        $before=$this->listed();
        $this->assertTrue($before['diagnosticsSchemaReady']);
        $this->assertNull($before['pendingOfflineCount']);
        $this->assertNull($before['clockDriftSeconds']);
        $this->heartbeat();
        $after=$this->listed();
        $this->assertSame(0,$after['pendingOfflineCount']);
        $this->assertSame(90,$after['clockDriftSeconds']);
        $this->assertSame('SYNTHETIC reader',$after['readerStatus']);
    }

    public function test_old_schema_remains_usable_and_reconcile_only_adds_missing_columns_without_changing_existing_values(): void
    {
        $id=$this->device();
        Schema::table('attendance_devices',fn ($table) => $table->dropColumn(['client_time_at','clock_drift_seconds','pending_offline_count','reader_status']));
        $before=DB::table('attendance_devices')->where('id',$id)->first();
        $this->assertFalse($this->listed()['diagnosticsSchemaReady']);
        $this->assertNull($this->listed()['pendingOfflineCount']);
        $response=$this->heartbeat();
        $this->assertTrue($response['success']);
        $this->assertSame(90,$response['clockDriftSeconds']);
        $existing=DB::table('attendance_devices')->where('id',$id)->first();
        $migration=require database_path('migrations/2026_10_28_100001_reconcile_attendance_device_heartbeat_details.php');
        $migration->up();$migration->up();
        $after=DB::table('attendance_devices')->where('id',$id)->first();
        foreach (get_object_vars($existing) as $key=>$value) $this->assertSame($value,$after->$key,$key);
        $this->assertNull($after->pending_offline_count);
        $this->assertTrue($this->listed()['diagnosticsSchemaReady']);
        $this->assertNull($this->listed()['pendingOfflineCount']);
        $this->heartbeat();
        $this->assertSame(0,$this->listed()['pendingOfflineCount']);
        $migration->down();
        $this->assertTrue(Schema::hasColumn('attendance_devices','pending_offline_count'));
        $this->assertSame(0,$this->listed()['pendingOfflineCount']);
        $this->assertSame($before->device_code,$after->device_code);
    }
}
