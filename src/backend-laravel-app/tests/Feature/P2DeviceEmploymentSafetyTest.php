<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class P2DeviceEmploymentSafetyTest extends TestCase
{
    use RefreshDatabase;

    private int $employee;
    private int $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = DB::table('attendance_devices')->insertGetId([
            'device_code' => 'P2-SYNTHETIC', 'name' => '合成端末',
            'device_secret_hash' => Hash::make('synthetic-secret'), 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->employee = $this->employee('P2-SYNTHETIC');
        DB::table('employee_cards')->insert([
            'employee_id' => $this->employee, 'card_uid' => 'P2-CARD', 'is_active' => 1,
            'assigned_at' => '2026-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[DataProvider('emptySecrets')]
    public function test_unconfigured_active_device_is_denied_on_all_device_operations(?string $hash): void
    {
        // Seed a genuine stored punch first to prove a retry cannot disclose it.
        $payload = $this->payload();
        $this->postJson('/api/attendance/punch', $payload)->assertOk();
        DB::table('attendance_devices')->where('id', $this->device)->update(['device_secret_hash' => $hash]);
        $beforeEvents = DB::table('attendance_events')->get()->toJson();
        $beforeCards = DB::table('employee_cards')->get()->toJson();
        $beforeDevice = DB::table('attendance_devices')->first();
        $operations = [
            ['/api/attendance/punch', $payload],
            ['/api/attendance/punch', $this->payload('2026-10-12T17:30:00+09:00', 'P2-new')],
            ['/api/attendance/devices/heartbeat', [...$this->credentials(), 'lastSeenAt' => '2026-10-12T08:30:00+09:00', 'pendingOfflineCount' => 1, 'appVersion' => 'synthetic']],
            ['/api/attendance/card-registration/employees', $this->credentials()],
            ['/api/attendance/card-registration/assign', [...$this->credentials(), 'employeeId' => $this->employee, 'cardUid' => 'P2-OTHER']],
        ];
        foreach ($operations as [$url, $body]) {
            $response = $this->postJson($url, $body)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
            $this->assertArrayNotHasKey('data', $response->json());
            $this->assertStringNotContainsString('合成職員', $response->getContent());
        }
        $this->assertSame($beforeEvents, DB::table('attendance_events')->get()->toJson());
        $this->assertSame($beforeCards, DB::table('employee_cards')->get()->toJson());
        $this->assertEquals($beforeDevice, DB::table('attendance_devices')->first());
    }

    public static function emptySecrets(): array { return [[null], [''], ['   ']]; }

    #[DataProvider('outsideEmployment')]
    public function test_outside_employment_original_is_preserved_and_acknowledged_without_attendance_adoption(array $employee, string $time): void
    {
        DB::table('employees')->where('id', $this->employee)->update($employee);
        $payload = $this->payload($time);
        $first = $this->postJson('/api/attendance/punch', $payload)->assertOk()
            ->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.eventType', null);
        $original = DB::table('attendance_events')->first();
        $auditCount = DB::table('audit_logs')->count();
        $this->postJson('/api/attendance/punch', $payload)->assertOk()
            ->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.eventType', null)
            ->assertJsonPath('data.attendanceEventId', $first->json('data.attendanceEventId'));
        $this->assertEquals($original, DB::table('attendance_events')->first());
        $this->assertDatabaseHas('attendance_events', ['employee_id' => $this->employee, 'dedupe_key' => 'P2-original',
            'receive_status' => 'REJECTED', 'rejection_reason' => 'EMPLOYEE_NOT_EMPLOYED', 'event_type' => null]);
        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertDatabaseCount('attendance_daily', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public static function outsideEmployment(): array
    {
        return [
            'before start' => [['joined_on' => '2026-10-13'], '2026-10-12T23:59:59+09:00'],
            'after retirement even with stale ACTIVE status' => [['status' => 'ACTIVE', 'retired_on' => '2026-10-11'], '2026-10-12T00:00:00+09:00'],
            'retired with date' => [['status' => 'RETIRED', 'retired_on' => '2026-10-11'], '2026-10-12T08:30:00+09:00'],
            'inactive with no historical end date' => [['status' => 'RETIRED', 'retired_on' => null], '2026-10-12T08:30:00+09:00'],
        ];
    }

    public function test_archived_employment_rejection_does_not_prevent_next_employees_punch(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['retired_on' => '2026-10-11']);
        $first = $this->postJson('/api/attendance/punch', $this->payload())->assertOk()
            ->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.eventType', null);
        $next = $this->employee('P2-NEXT');
        DB::table('employee_cards')->insert([
            'employee_id' => $next, 'card_uid' => 'P2-NEXT-CARD', 'is_active' => 1,
            'assigned_at' => '2026-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/attendance/punch', [
            ...$this->payload('2026-10-12T08:31:00+09:00', 'P2-next'), 'cardUid' => 'P2-NEXT-CARD',
        ])->assertOk()->assertJsonPath('data.resultType', 'SUCCESS')->assertJsonPath('data.eventType', 'CLOCK_IN');
        $this->postJson('/api/attendance/punch', $this->payload())->assertOk()
            ->assertJsonPath('data.attendanceEventId', $first->json('data.attendanceEventId'));
        $this->assertDatabaseCount('attendance_events', 2);
        $this->assertDatabaseHas('attendance_events', ['employee_id' => $this->employee, 'receive_status' => 'REJECTED', 'event_type' => null]);
        $this->assertDatabaseMissing('attendance_daily', ['employee_id' => $this->employee]);
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $next, 'target_date' => '2026-10-12']);
    }

    public function test_retirement_day_is_inclusive_and_delayed_retired_original_is_saved_for_review(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['status' => 'RETIRED', 'retired_on' => '2026-10-12']);
        $payload = $this->payload('2026-10-12T23:59:59+09:00');
        $first = $this->postJson('/api/attendance/punch', $payload)->assertOk()
            ->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.eventType', null);
        $this->postJson('/api/attendance/punch', $payload)->assertOk()
            ->assertJsonPath('data.attendanceEventId', $first->json('data.attendanceEventId'));
        $this->assertDatabaseHas('attendance_events', ['employee_id' => $this->employee, 'occurred_at' => '2026-10-12 23:59:59',
            'receive_status' => 'REJECTED', 'rejection_reason' => 'HISTORICAL_EMPLOYEE_PUNCH']);
        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertDatabaseCount('attendance_daily', 0);
    }

    public function test_new_punch_after_retirement_is_archived_without_attendance_when_old_card_was_revoked(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['status' => 'RETIRED', 'retired_on' => '2026-10-11']);
        DB::table('employee_cards')->update(['is_active' => 0, 'revoked_at' => '2026-10-12 00:00:00']);
        $this->postJson('/api/attendance/punch', $this->payload())->assertOk()
            ->assertJsonPath('data.resultType', 'WARNING')->assertJsonPath('data.eventType', null);
        $this->assertDatabaseHas('attendance_events', ['employee_id' => $this->employee, 'occurred_at' => '2026-10-12 08:30:00',
            'receive_status' => 'REJECTED', 'rejection_reason' => 'EMPLOYEE_NOT_EMPLOYED']);
        $this->assertDatabaseCount('attendance_daily', 0);
    }

    public function test_active_employee_joining_day_and_retirement_day_are_inclusive(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['joined_on' => '2026-10-12', 'retired_on' => '2026-10-12']);
        $this->postJson('/api/attendance/punch', $this->payload('2026-10-12T00:00:00+09:00'))->assertOk()->assertJsonPath('data.eventType', 'CLOCK_IN');
        $this->postJson('/api/attendance/punch', $this->payload('2026-10-12T23:59:59+09:00', 'P2-last'))
            ->assertOk()->assertJsonPath('data.eventType', 'CLOCK_OUT');
        $this->assertDatabaseCount('attendance_daily', 1);
    }

    public function test_reassigned_card_delayed_original_never_uses_replacement_employee(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['status' => 'RETIRED', 'retired_on' => '2026-10-12']);
        DB::table('employee_cards')->where('employee_id', $this->employee)->update(['is_active' => 0, 'revoked_at' => '2026-10-13 00:00:00']);
        $replacement = $this->employee('P2-REPLACEMENT');
        DB::table('employee_cards')->insert([
            'employee_id' => $replacement, 'card_uid' => 'P2-CARD', 'is_active' => 1,
            'assigned_at' => '2026-10-13 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/attendance/punch', $this->payload())->assertOk()
            ->assertJsonPath('data.employee.id', $this->employee)->assertJsonPath('data.resultType', 'WARNING');
        $this->assertDatabaseCount('attendance_daily', 0);
        $this->postJson('/api/attendance/punch', $this->payload('2026-10-13T08:30:00+09:00', 'P2-replacement'))
            ->assertOk()->assertJsonPath('data.employee.id', $replacement)->assertJsonPath('data.eventType', 'CLOCK_IN');
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $replacement]);
        $this->assertDatabaseMissing('attendance_daily', ['employee_id' => $this->employee]);
    }

    #[DataProvider('uncertainAssignments')]
    public function test_uncertain_assignment_preserves_anonymous_original_without_attendance_adoption(string $mode): void
    {
        if ($mode === 'before assignment') {
            DB::table('employee_cards')->update(['assigned_at' => '2026-10-13 00:00:00']);
        } else {
            DB::table('employee_cards')->insert([
                'employee_id' => $this->employee('P2-OVERLAP'), 'card_uid' => 'P2-CARD', 'is_active' => 1,
                'assigned_at' => '2026-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->postJson('/api/attendance/punch', $this->payload())->assertOk()->assertJsonPath('data.resultType', 'WARNING')
            ->assertJsonPath('data.employee', null)->assertJsonPath('data.eventType', null);
        $this->assertDatabaseHas('attendance_events', ['employee_id' => null, 'card_uid' => 'P2-CARD', 'dedupe_key' => 'P2-original',
            'rejection_reason' => 'CARD_ASSIGNMENT_REVIEW_REQUIRED', 'occurred_at' => '2026-10-12 08:30:00']);
        $this->assertDatabaseCount('attendance_daily', 0);
    }

    public static function uncertainAssignments(): array { return [['before assignment'], ['overlapping assignments']]; }

    public function test_existing_genuine_accepted_retry_is_unchanged_after_retirement_and_card_reassignment(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/api/attendance/punch', $payload)->assertOk();
        DB::table('employees')->where('id', $this->employee)->update(['status' => 'RETIRED', 'retired_on' => '2026-10-12']);
        DB::table('employee_cards')->update(['is_active' => 0, 'revoked_at' => '2026-10-13 00:00:00']);
        $before = [];
        foreach (['attendance_events', 'attendance_daily', 'audit_logs'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->postJson('/api/attendance/punch', $payload)->assertOk()->assertJsonPath('data.attendanceEventId', $first->json('data.attendanceEventId'));
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_device_cannot_assign_a_card_to_a_retired_employee(): void
    {
        DB::table('employees')->where('id', $this->employee)->update(['status' => 'RETIRED', 'retired_on' => '2026-10-12']);
        $before = DB::table('employee_cards')->get()->toJson();
        $this->postJson('/api/attendance/card-registration/assign', [...$this->credentials(), 'employeeId' => $this->employee, 'cardUid' => 'P2-NEW'])
            ->assertStatus(403)->assertJsonPath('error.code', 'EMPLOYEE_NOT_EMPLOYED');
        $this->assertSame($before, DB::table('employee_cards')->get()->toJson());
    }

    private function employee(string $code): int
    {
        return DB::table('employees')->insertGetId([
            'employee_code' => $code, 'name' => '合成職員', 'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE', 'joined_on' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function credentials(): array { return ['deviceCode' => 'P2-SYNTHETIC', 'deviceSecret' => 'synthetic-secret']; }

    private function payload(string $time = '2026-10-12T08:30:00+09:00', string $key = 'P2-original'): array
    {
        return [...$this->credentials(), 'cardUid' => 'P2-CARD', 'occurredAt' => $time, 'dedupeKey' => $key, 'appVersion' => 'synthetic'];
    }
}
