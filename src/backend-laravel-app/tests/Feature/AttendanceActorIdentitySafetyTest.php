<?php

namespace Tests\Feature;

use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Attendance\DailyEditService;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendanceActorIdentitySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_history_uses_the_real_user_and_keeps_snapshot_after_rename(): void
    {
        $unrelated = $this->employee('FIRST', '先頭の別職員');
        $subject = $this->employee('TARGET', '勤怠対象職員');
        $admin = $this->admin();
        $id = $this->day($subject);
        $service = app(DailyEditService::class);
        $detail = $service->updateDaily($id, ['clockInTime' => '09:15', 'remark' => '管理者による確認', 'approvalStatus' => 'APPROVED'], $admin);
        $this->assertSame('操作時の管理者名', $detail['manualEditedByName']);
        $this->assertNull($detail['manualEditedByCode']);

        $row = DB::table('attendance_daily_histories')->where('attendance_daily_id', $id)->where('field_key', 'remark')->first();
        $this->assertSame('ADMIN', $row->actor_type);
        $this->assertSame((int) $admin->id, (int) $row->actor_id);
        $this->assertNull($row->actor_employee_id);
        $this->assertNull($row->actor_employee_code);
        $this->assertSame('操作時の管理者名', $row->actor_name);
        $this->assertNull(DB::table('attendance_daily')->where('id', $id)->value('approved_by'));

        DB::table('users')->where('id', $admin->id)->update(['name' => '改姓後の管理者名']);
        DB::table('employees')->where('id', $unrelated)->update(['name' => '別職員の改姓', 'status' => 'RETIRED']);
        $history = collect($service->histories($id))->firstWhere('fieldKey', 'remark');
        $this->assertSame('操作時の管理者名', $history['actorName']);
        $this->assertSame('RECORDED_SNAPSHOT', $history['actorNameSource']);
        $this->assertSame((int) $admin->id, $history['actorId']);
        $detail = app(\App\Services\AttendanceService::class)->detail($id);
        $this->assertSame('操作時の管理者名', $detail['manualEditedByName']);
    }

    public function test_admin_approval_without_employee_link_uses_nullable_fk_and_real_actor_history(): void
    {
        $subject = $this->employee('TARGET', '勤怠対象職員');
        $id = $this->day($subject);
        $admin = $this->admin();
        app(AttendanceApprovalService::class)->decideApproval($id, 'APPROVED', '確認済み', $admin);
        $this->assertDatabaseHas('attendance_daily', ['id' => $id, 'approval_status' => 'APPROVED', 'approved_by' => null]);
        $this->assertDatabaseHas('attendance_daily_histories', ['attendance_daily_id' => $id, 'actor_type' => 'ADMIN', 'actor_id' => $admin->id, 'actor_employee_id' => null, 'actor_name' => '操作時の管理者名']);
    }

    public function test_explicit_admin_employee_link_is_used_only_for_legacy_fk(): void
    {
        $this->employee('FIRST', '先頭の別職員');
        $linked = $this->employee('LINK', '管理者本人の職員名');
        $subject = $this->employee('TARGET', '勤怠対象職員');
        $id = $this->day($subject);
        $admin = $this->admin($linked);
        app(AttendanceApprovalService::class)->decideApproval($id, 'APPROVED', null, $admin);
        $this->assertDatabaseHas('attendance_daily', ['id' => $id, 'approved_by' => $linked]);
        $this->assertDatabaseHas('attendance_daily_histories', ['attendance_daily_id' => $id, 'actor_type' => 'ADMIN', 'actor_employee_id' => null, 'actor_name' => '操作時の管理者名']);
    }

    public function test_employee_history_retains_the_original_name_and_code_after_retirement(): void
    {
        $subject = $this->employee('E100', '操作時の職員名');
        $id = $this->day($subject);
        $actor = new GenericUser(['id' => $subject, 'role' => 'EMPLOYEE', 'name' => '操作時の職員名']);
        app(DailyEditService::class)->updateDaily($id, ['remark' => '本人による確認'], $actor);
        DB::table('employees')->where('id', $subject)->update(['name' => '改姓後の職員名', 'status' => 'RETIRED', 'retired_on' => '2026-10-01']);
        $history = collect(app(DailyEditService::class)->histories($id))->firstWhere('fieldKey', 'remark');
        $this->assertSame('操作時の職員名', $history['actorName']);
        $this->assertSame('E100', $history['actorEmployeeCode']);
        $this->assertSame('EMPLOYEE', $history['actorType']);
        $this->assertSame($subject, $history['actorId']);
    }

    public function test_legacy_wrong_employee_name_is_displayed_using_admin_id_without_rewriting_evidence(): void
    {
        $wrong = $this->employee('WRONG', '旧誤記の別職員');
        $subject = $this->employee('TARGET', '勤怠対象職員');
        $id = $this->day($subject);
        $admin = $this->admin();
        $historyId = DB::table('attendance_daily_histories')->insertGetId([
            'attendance_daily_id' => $id, 'action_type' => 'MANUAL_EDITED', 'field_key' => 'remark',
            'old_value' => '修正前', 'new_value' => '修正後', 'actor_type' => 'ADMIN', 'actor_id' => $admin->id,
            'actor_employee_id' => $wrong, 'actor_role' => 'ADMIN', 'actor_employee_code' => 'WRONG',
            'actor_name' => '旧誤記の別職員', 'comment' => '当時の証跡', 'acted_at' => '2026-09-03 18:00:00',
        ]);
        $before = DB::table('attendance_daily_histories')->where('id', $historyId)->first();
        DB::table('attendance_daily')->where('id', $id)->update(['manual_edited_by' => $wrong, 'manual_edited_at' => '2026-09-03 18:00:00', 'is_manually_edited' => true]);
        $history = app(DailyEditService::class)->histories($id)[0];
        $this->assertSame('操作時の管理者名', $history['actorName']);
        $this->assertNull($history['actorEmployeeCode']);
        $this->assertSame('CURRENT_ADMIN_RECORD', $history['actorNameSource']);
        $this->assertSame('旧誤記の別職員', $history['recordedActorName']);
        $this->assertSame('WRONG', $history['recordedActorEmployeeCode']);
        $this->assertSame('操作時の管理者名', app(\App\Services\AttendanceService::class)->detail($id)['manualEditedByName']);
        $this->assertEquals($before, DB::table('attendance_daily_histories')->where('id', $historyId)->first());

        DB::table('users')->where('id', $admin->id)->delete();
        $history = app(DailyEditService::class)->histories($id)[0];
        $this->assertSame('管理者 (ID:'.$admin->id.')', $history['actorName']);
        $this->assertSame('ACTOR_ID_ONLY', $history['actorNameSource']);
        $this->assertSame('管理者 (ID:'.$admin->id.')', app(\App\Services\AttendanceService::class)->detail($id)['manualEditedByName']);
        $this->assertEquals($before, DB::table('attendance_daily_histories')->where('id', $historyId)->first());
    }

    private function employee(string $code, string $name): int
    {
        return DB::table('employees')->insertGetId(['employee_code' => $code, 'name' => $name, 'kana' => 'テスト', 'employment_type' => 'FULL_TIME', 'status' => 'ACTIVE', 'joined_on' => '2026-01-01']);
    }

    private function admin(?int $linked = null): GenericUser
    {
        $id = DB::table('users')->insertGetId(['name' => '操作時の管理者名', 'email' => 'actor@example.test', 'password' => Hash::make('TestPassword123!')]);
        return new GenericUser(['id' => $id, 'role' => 'ADMIN', 'name' => '操作時の管理者名', 'employeeId' => $linked]);
    }

    private function day(int $employee): int
    {
        return DB::table('attendance_daily')->insertGetId(['employee_id' => $employee, 'target_date' => '2026-09-03', 'schedule_name' => '通常勤務', 'clock_in_at' => '2026-09-03 09:00:00', 'clock_out_at' => '2026-09-03 18:00:00', 'break_minutes' => 60, 'work_minutes' => 480, 'approval_status' => 'PENDING', 'close_status' => 'OPEN', 'updated_at' => now()]);
    }
}
