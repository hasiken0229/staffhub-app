<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AttendancePunchLimitApiTest extends TestCase
{
    use RefreshDatabase;

    private int $deviceId;

    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceId = (int) DB::table('attendance_devices')->insertGetId([
            'device_code' => 'PC-TEST-01',
            'name' => 'テスト端末',
            'location_name' => '玄関',
            'device_secret_hash' => Hash::make('secret'),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->employeeId = (int) DB::table('employees')->insertGetId([
            'employee_code' => 'E7001',
            'name' => '打刻 太郎',
            'kana' => null,
            'department_name' => '保育',
            'location_name' => '本園',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'joined_on' => '2026-04-01',
            'retired_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_cards')->insert([
            'employee_id' => $this->employeeId,
            'card_uid' => 'ABCDEF1234567890',
            'is_active' => 1,
            'assigned_at' => '2026-04-01 00:00:00',
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_third_punch_on_same_day_is_rejected(): void
    {
        $this->postPunch('2026-06-18T08:30:00+09:00', 'punch-1')
            ->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_IN');
        $this->postPunch('2026-06-18T17:30:00+09:00', 'punch-2')
            ->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_OUT');

        $this->postPunch('2026-06-18T18:00:00+09:00', 'punch-3')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'TOO_MANY_PUNCHES');

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $this->employeeId,
            'device_id' => $this->deviceId,
            'receive_status' => 'REJECTED',
            'rejection_reason' => 'TOO_MANY_PUNCHES',
            'dedupe_key' => 'punch-3',
        ]);

        $this->postPunch('2026-06-18T18:00:00+09:00', 'punch-3')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'TOO_MANY_PUNCHES');

        $this->assertSame(3, DB::table('attendance_events')->count());
    }

    public function test_punch_within_three_minutes_is_rejected(): void
    {
        $this->postPunch('2026-06-18T08:30:00+09:00', 'punch-1')
            ->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_IN');

        $this->postPunch('2026-06-18T08:33:00+09:00', 'punch-2')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'SHORT_INTERVAL_PUNCH')
            ->assertJsonPath('error.message', '直前の打刻から3分以内のため、打刻を受け付けできません。');

        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $this->employeeId,
            'device_id' => $this->deviceId,
            'receive_status' => 'REJECTED',
            'rejection_reason' => 'SHORT_INTERVAL_PUNCH',
            'dedupe_key' => 'punch-2',
        ]);

        $this->assertDatabaseCount('attendance_daily', 1);
        $this->assertSame(1, DB::table('attendance_events')->where('employee_id', $this->employeeId)->where('receive_status', 'ACCEPTED')->count());
    }

    public function test_repeated_short_taps_stay_pending_and_an_evening_clock_out_still_works_across_devices(): void
    {
        DB::table('attendance_devices')->insert([
            'device_code' => 'PC-TEST-02', 'name' => '別の端末', 'device_secret_hash' => Hash::make('secret'),
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postPunch('2026-06-18T08:00:00+09:00', 'in')->assertOk()->assertJsonPath('data.eventType', 'CLOCK_IN');
        $candidate = $this->postPunch('2026-06-18T08:03:01+09:00', 'tap')
            ->assertOk()->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.eventType', null)
            ->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_CONFIRMATION_REQUIRED')->json('data.attendanceEventId');
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap')->assertOk()->assertJsonPath('data.attendanceEventId', $candidate);
        $this->postPunch('2026-06-18T08:03:01+09:00', 'other-at-181s', 'PC-TEST-02')
            ->assertOk()->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_CONFIRMATION_REQUIRED');
        $this->postPunch('2026-06-18T08:10:00+09:00', 'other', 'PC-TEST-02')->assertOk()
            ->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $this->employeeId, 'raw_clock_out_at' => null]);
        $this->assertSame(1, DB::table('attendance_events')->where('receive_status', 'ACCEPTED')->count());
        $review = app(\App\Services\Attendance\DailyApprovalReview::class);
        $this->assertStringContainsString('短時間で退勤', $review->pending(['from' => '2026-06-18', 'to' => '2026-06-18'])->sole()->review_reason);
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap', 'PC-TEST-02', confirm: true)
            ->assertConflict()->assertJsonPath('error.code', 'DEDUPE_CONFLICT');

        $this->postPunch('2026-06-18T17:00:00+09:00', 'evening', 'PC-TEST-02')->assertOk()->assertJsonPath('data.eventType', 'CLOCK_OUT');
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap', confirm: true)->assertOk()
            ->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_REVIEW_ONLY');
        $this->postPunch('2026-06-18T08:10:00+09:00', 'other', 'PC-TEST-02')->assertOk()
            ->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_REVIEW_ONLY');
        $this->postPunch('2026-06-18T17:00:00+09:00', 'evening', 'PC-TEST-02')->assertOk()->assertJsonPath('data.eventType', 'CLOCK_OUT');
        $this->assertDatabaseCount('attendance_events', 5);
        $this->assertSame(2, DB::table('attendance_events')->where('receive_status', 'ACCEPTED')->count());
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $this->employeeId, 'raw_clock_out_at' => '2026-06-18 17:00:00']);
        $remaining = $review->pending(['from' => '2026-06-18', 'to' => '2026-06-18'])->first();
        $this->assertStringNotContainsString('出勤直後のタッチ', $remaining->review_reason ?? '');
    }

    public function test_a_real_short_shift_requires_an_existing_candidate_and_confirmation_retries_commit_once(): void
    {
        $this->postPunch('2026-06-18T08:00:00+09:00', 'in')->assertOk();
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap', confirm: true)->assertConflict()
            ->assertJsonPath('error.code', 'SHORT_SHIFT_NOT_PENDING');
        $candidate = $this->postPunch('2026-06-18T08:03:01+09:00', 'tap')->assertOk()->json('data.attendanceEventId');
        foreach ([true, true, false] as $confirm) {
            $this->postPunch('2026-06-18T08:03:01+09:00', 'tap', confirm: $confirm)->assertOk()
                ->assertJsonPath('data.attendanceEventId', $candidate)->assertJsonPath('data.eventType', 'CLOCK_OUT')
                ->assertJsonPath('data.occurredAt', '2026-06-18T08:03:01+09:00')->assertJsonPath('data.resultType', 'SUCCESS');
        }
        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $this->employeeId, 'raw_clock_in_at' => '2026-06-18 08:00:00', 'raw_clock_out_at' => '2026-06-18 08:03:01']);
    }

    public function test_confirmation_after_month_close_does_not_reopen_or_overwrite_attendance(): void
    {
        $this->postPunch('2026-06-18T08:00:00+09:00', 'in')->assertOk();
        $this->postPunch('2026-06-18T08:10:00+09:00', 'tap')->assertOk()->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_CONFIRMATION_REQUIRED');
        DB::table('attendance_monthly_closes')->where('target_year_month', '2026-06')->update(['status' => 'CLOSED']);
        $this->postPunch('2026-06-18T08:10:00+09:00', 'tap', confirm: true)->assertOk()
            ->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_REVIEW_ONLY');
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $this->employeeId, 'raw_clock_out_at' => null]);
        $this->assertDatabaseHas('attendance_monthly_closes', ['target_year_month' => '2026-06', 'status' => 'CLOSED']);
    }

    public function test_above_the_review_window_an_ordinary_tap_clocks_out_once(): void
    {
        $this->postPunch('2026-06-18T08:00:00+09:00', 'in')->assertOk();
        $this->postPunch('2026-06-18T08:10:01+09:00', 'out')->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_OUT')->assertJsonPath('data.resultType', 'SUCCESS');
        $this->assertDatabaseCount('attendance_events', 2);
    }

    public function test_subsecond_terminal_timestamps_use_the_stored_second_at_the_confirmation_boundary(): void
    {
        $this->postPunch('2026-06-18T08:00:00.250+09:00', 'in')->assertOk();
        $this->postPunch('2026-06-18T08:10:00.250+09:00', 'tap')->assertOk()->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_CONFIRMATION_REQUIRED');
        $this->postPunch('2026-06-18T08:10:00.250+09:00', 'tap', confirm: true)->assertOk()->assertJsonPath('data.eventType', 'CLOCK_OUT');
    }

    public function test_a_revoked_card_cannot_promote_its_earlier_candidate(): void
    {
        $this->postPunch('2026-06-18T08:00:00+09:00', 'in')->assertOk();
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap')->assertOk()->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_CONFIRMATION_REQUIRED');
        DB::table('employee_cards')->where('employee_id', $this->employeeId)->update(['is_active' => 0, 'revoked_at' => '2026-06-18 08:04:00']);
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap', confirm: true)->assertOk()
            ->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_REVIEW_ONLY');
        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $this->employeeId, 'raw_clock_out_at' => null]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedDailyChanges')]
    public function test_confirm_cannot_replace_a_later_approval_or_edit(array $changes): void
    {
        $this->postPunch('2026-06-18T08:00:00+09:00', 'in')->assertOk();
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap')->assertOk();
        DB::table('attendance_daily')->where('employee_id', $this->employeeId)->update($changes);
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap', confirm: true)->assertOk()
            ->assertJsonPath('data.reasonCode', 'SHORT_SHIFT_REVIEW_ONLY');
        $this->assertDatabaseHas('attendance_daily', $changes + ['employee_id' => $this->employeeId, 'raw_clock_out_at' => null]);
    }

    public static function protectedDailyChanges(): array
    {
        return [
            'approved' => [['approval_status' => 'APPROVED']],
            'corrected' => [['is_manually_edited' => 1, 'clock_out_at' => '2026-06-18 16:00:00']],
        ];
    }

    public function test_another_employees_card_cannot_confirm_an_existing_punch(): void
    {
        $otherEmployee = DB::table('employees')->insertGetId([
            'employee_code' => 'E7002', 'name' => '別の職員', 'department_name' => '保育', 'location_name' => '本園',
            'employment_type' => 'FULL_TIME', 'status' => 'ACTIVE', 'joined_on' => '2026-04-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_cards')->insert([
            'employee_id' => $otherEmployee, 'card_uid' => 'OTHER-CARD', 'is_active' => 1,
            'assigned_at' => '2026-04-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postPunch('2026-06-18T08:00:00+09:00', 'in')->assertOk();
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap')->assertOk();
        $this->postPunch('2026-06-18T08:03:01+09:00', 'tap', confirm: true, cardUid: 'OTHER-CARD')
            ->assertConflict()->assertJsonPath('error.code', 'DEDUPE_CONFLICT');
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $this->employeeId, 'raw_clock_out_at' => null]);
        $this->assertDatabaseMissing('attendance_daily', ['employee_id' => $otherEmployee]);
    }

    private function postPunch(string $occurredAt, string $dedupeKey, string $deviceCode = 'PC-TEST-01', bool $confirm = false, string $cardUid = 'ABCDEF1234567890')
    {
        return $this->postJson('/api/attendance/punch', [
            'deviceCode' => $deviceCode,
            'deviceSecret' => 'secret',
            'cardUid' => $cardUid,
            'occurredAt' => $occurredAt,
            'dedupeKey' => $dedupeKey,
            'appVersion' => 'test',
            ...($confirm ? ['confirmShortShift' => true] : []),
        ]);
    }
}
