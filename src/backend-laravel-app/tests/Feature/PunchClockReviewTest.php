<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PunchClockReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 09:00:00+09:00');
        DB::table('attendance_devices')->insert([
            'device_code' => 'CLOCK-TEST', 'name' => '時計検証端末', 'device_secret_hash' => Hash::make('test-only'),
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'CLOCK-TEST', 'name' => '時計検証職員', 'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE', 'joined_on' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_cards')->insert([
            'employee_id' => $employeeId, 'card_uid' => 'CLOCK-CARD', 'is_active' => 1,
            'assigned_at' => '2026-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(string $occurredAt, string $key): array
    {
        return ['deviceCode' => 'CLOCK-TEST', 'deviceSecret' => 'test-only', 'cardUid' => 'CLOCK-CARD',
            'occurredAt' => $occurredAt, 'dedupeKey' => $key];
    }

    public function test_next_day_punch_is_held_once_even_after_server_reaches_the_claimed_time(): void
    {
        $payload = $this->payload('2026-11-26T08:30:00+09:00', 'tomorrow');
        $first = $this->postJson('/api/attendance/punch', $payload)->assertOk()
            ->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.eventType', null)->json('data');
        $this->assertStringContainsString('未確定', $first['resultMessage']);
        $this->assertDatabaseHas('attendance_events', ['id' => $first['attendanceEventId'], 'dedupe_key' => 'tomorrow',
            'receive_status' => 'REJECTED', 'occurred_at' => '2026-11-26 08:30:00', 'rejection_reason' => 'DEVICE_CLOCK_REVIEW_REQUIRED']);
        Carbon::setTestNow('2026-11-26 08:31:00+09:00');
        $this->postJson('/api/attendance/punch', $payload)->assertOk()->assertJsonPath('data', $first);
        $payload['occurredAt'] = '2026-11-26T08:30:01+09:00';
        $this->postJson('/api/attendance/punch', $payload)->assertStatus(409)->assertJsonPath('error.code', 'DEDUPE_CONFLICT');
        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertDatabaseCount('attendance_daily', 0);
        $this->assertDatabaseCount('attendance_monthly_closes', 0);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_same_day_future_and_small_rollover_to_the_next_month_are_not_adopted(): void
    {
        $this->postJson('/api/attendance/punch', $this->payload('2026-11-25T09:02:01+09:00', 'ahead'))
            ->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        Carbon::setTestNow('2026-11-30 23:59:59+09:00');
        $this->postJson('/api/attendance/punch', $this->payload('2026-11-30T15:00:01Z', 'month-boundary'))
            ->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        $this->assertDatabaseCount('attendance_daily', 0);
        $this->assertDatabaseMissing('attendance_monthly_closes', ['target_year_month' => '2026-12']);
    }

    public function test_wrong_clock_at_capture_is_preserved_after_recovery_even_when_claimed_time_is_now_past(): void
    {
        $payload = $this->payload('2026-11-24T08:30:00+09:00', 'queued-wrong-clock') + [
            'clockReferenceAt' => '2026-11-23T08:30:00+09:00', 'clientSentAt' => now()->toIso8601String(),
        ];
        $this->postJson('/api/attendance/punch', $payload)->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        $this->assertDatabaseCount('attendance_daily', 0);
        $audit = json_decode(DB::table('audit_logs')->value('detail_json'), true);
        $this->assertSame($payload['clockReferenceAt'], $audit['clockReferenceAt']);
    }

    public function test_untrusted_recent_clock_samples_do_not_block_older_trusted_offline_originals(): void
    {
        $yesterday = '2026-11-24T09:00:00+09:00';
        $this->postJson('/api/attendance/devices/heartbeat', [
            'deviceCode' => 'CLOCK-TEST', 'deviceSecret' => 'test-only', 'lastSeenAt' => $yesterday,
            'pendingOfflineCount' => 2,
        ])->assertOk()->assertJsonPath('data.clockDriftSeconds', -86400);
        // Old terminal: recent bad heartbeat and matching device-local capture time.
        $this->postJson('/api/attendance/punch', $this->payload($yesterday, 'legacy-bad-clock'))
            ->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        // Another queued capture has a contemporaneous server reference proving its time.
        $opening = $this->payload($yesterday, 'queued-real-in') + ['clockReferenceAt' => $yesterday, 'clientSentAt' => $yesterday];
        $first = $this->postJson('/api/attendance/punch', $opening)->assertOk()->assertJsonPath('data.eventType', 'CLOCK_IN')->json('data');
        $closing = $this->payload('2026-11-24T17:00:00+09:00', 'queued-real-out');
        $this->postJson('/api/attendance/punch', $closing)->assertOk()->assertJsonPath('data.eventType', 'CLOCK_OUT');
        $this->postJson('/api/attendance/punch', $opening)->assertOk()->assertJsonPath('data', $first);
        $this->assertDatabaseCount('attendance_events', 3);
        $this->assertDatabaseHas('attendance_daily', ['target_date' => '2026-11-24',
            'raw_clock_in_at' => '2026-11-24 09:00:00', 'raw_clock_out_at' => '2026-11-24 17:00:00']);
    }

    public function test_small_offline_clock_shift_cannot_move_the_capture_to_a_different_japanese_day(): void
    {
        $payload = $this->payload('2026-11-24T00:00:03+09:00', 'queued-midnight') + [
            'clockReferenceAt' => '2026-11-23T14:59:58Z',
        ];
        $this->postJson('/api/attendance/punch', $payload)->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        $this->assertDatabaseCount('attendance_daily', 0);
    }

    public function test_cold_start_current_scan_with_slow_terminal_clock_is_held_using_send_time(): void
    {
        $payload = $this->payload('2026-11-24T08:59:59+09:00', 'cold-slow') + ['clientSentAt' => '2026-11-24T09:00:00+09:00'];
        $this->postJson('/api/attendance/punch', $payload)->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        $this->assertDatabaseCount('attendance_daily', 0);
    }

    public function test_unsynchronized_queued_scan_uses_elapsed_time_or_is_held_when_it_cannot_be_verified_after_restart(): void
    {
        $correct = $this->payload('2026-11-24T09:00:00+09:00', 'cold-trusted') + ['captureAgeSeconds' => 86400];
        $saved = $this->postJson('/api/attendance/punch', $correct)->assertOk()->assertJsonPath('data.eventType', 'CLOCK_IN')->json('data');
        // A lost reply followed by a restart still returns the committed original.
        unset($correct['captureAgeSeconds']);
        $correct['captureClockUnknown'] = true;
        $this->postJson('/api/attendance/punch', $correct)->assertOk()->assertJsonPath('data', $saved);
        $wrong = $this->payload('2026-11-23T17:00:00+09:00', 'cold-wrong') + ['captureAgeSeconds' => 57600];
        $this->postJson('/api/attendance/punch', $wrong)->assertOk()->assertJsonPath('data.resultType', 'WARNING');
        $noReference = $this->payload('2026-11-24T17:00:00+09:00', 'cold-restarted') + ['captureClockUnknown' => true];
        $this->postJson('/api/attendance/punch', $noReference)->assertOk()
            ->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.eventType', null);
        $this->assertDatabaseCount('attendance_events', 3);
        $this->assertDatabaseCount('attendance_daily', 1);
        $this->assertDatabaseHas('attendance_daily', ['target_date' => '2026-11-24', 'raw_clock_out_at' => null]);
    }

    public function test_valid_pre_midnight_scan_reaching_the_api_just_after_midnight_stays_on_its_day(): void
    {
        Carbon::setTestNow('2026-11-26 00:00:01+09:00');
        $payload = $this->payload('2026-11-25T23:59:59+09:00', 'midnight-transit') + [
            'captureAgeSeconds' => 0, 'clientSentAt' => '2026-11-25T23:59:59+09:00',
        ];
        $this->postJson('/api/attendance/punch', $payload)->assertOk()->assertJsonPath('data.eventType', 'CLOCK_IN');
        $this->assertDatabaseHas('attendance_daily', ['target_date' => '2026-11-25', 'raw_clock_in_at' => '2026-11-25 23:59:59']);
        $this->assertDatabaseMissing('attendance_daily', ['target_date' => '2026-11-26']);
    }
}
