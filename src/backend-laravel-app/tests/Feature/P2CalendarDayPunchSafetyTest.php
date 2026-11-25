<?php

namespace Tests\Feature;

use App\Services\Attendance\DailyEditService;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class P2CalendarDayPunchSafetyTest extends TestCase
{
    use RefreshDatabase;

    private int $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        DB::table('attendance_devices')->insert([
            'device_code' => 'P2-DAY', 'name' => '合成端末', 'device_secret_hash' => Hash::make('synthetic-secret'),
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->employee = DB::table('employees')->insertGetId([
            'employee_code' => 'P2-DAY', 'name' => '合成職員', 'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE', 'joined_on' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_cards')->insert([
            'employee_id' => $this->employee, 'card_uid' => 'P2-DAY-CARD', 'is_active' => 1,
            'assigned_at' => '2026-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[DataProvider('nextDayTimes')]
    public function test_unclosed_previous_day_warns_without_consuming_current_day_arrival(string $first, string $next): void
    {
        $this->punch($first, 'day-1')->assertOk()->assertJsonPath('data.eventType', 'CLOCK_IN');
        $previous = DB::table('attendance_daily')->first();
        $warning = $this->punch($next, 'day-2')->assertOk()->assertJsonPath('data.eventType', 'CLOCK_IN')
            ->assertJsonPath('data.resultType', 'WARNING');
        $this->assertStringContainsString('当日の出勤として保存', $warning->json('data.resultMessage'));
        $this->assertStringContainsString('翌日指定', $warning->json('data.resultMessage'));
        $this->assertEquals($previous, DB::table('attendance_daily')->where('id', $previous->id)->first());
        $this->assertDatabaseHas('attendance_events', ['dedupe_key' => 'day-2', 'event_type' => 'CLOCK_IN',
            'receive_status' => 'ACCEPTED', 'rejection_reason' => 'PREVIOUS_DAY_OPEN_PUNCH']);
        $this->assertDatabaseHas('attendance_daily', ['target_date' => '2026-10-13', 'raw_clock_out_at' => null]);
        $snapshot = [];
        foreach (['attendance_events', 'attendance_daily', 'audit_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->punch($next, 'day-2')->assertOk()->assertJsonPath('data.resultType', 'WARNING')
            ->assertJsonPath('data.attendanceEventId', $warning->json('data.attendanceEventId'));
        foreach ($snapshot as $table => $before) {
            $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public static function nextDayTimes(): array
    {
        return [
            'possible exceptional overnight' => ['2026-10-12T22:00:00+09:00', '2026-10-13T06:00:00+09:00'],
            'ordinary next-day arrival after missed exit' => ['2026-10-12T08:30:00+09:00', '2026-10-13T08:30:00+09:00'],
        ];
    }

    public function test_administrator_can_explicitly_correct_overnight_exit_without_altering_originals(): void
    {
        $this->punch('2026-10-12T22:00:00+09:00', 'day-1')->assertOk();
        $previous = DB::table('attendance_daily')->first();
        $events = DB::table('attendance_events')->get()->toJson();
        app(DailyEditService::class)->updateDaily($previous->id, [
            'clockInTime' => '22:00', 'clockOutTime' => '06:00', 'clockOutNextDay' => true,
            'breaks' => [], 'remark' => '例外の日跨ぎ勤務を原打刻確認のうえ個別修正',
        ], new GenericUser(['id' => $this->employee, 'role' => 'ADMIN', 'employeeId' => $this->employee]));
        $this->assertDatabaseHas('attendance_daily', ['id' => $previous->id, 'target_date' => '2026-10-12',
            'clock_in_at' => '2026-10-12 22:00:00', 'clock_out_at' => '2026-10-13 06:00:00',
            'raw_clock_in_at' => '2026-10-12 22:00:00', 'raw_clock_out_at' => null,
            'work_minutes' => 480, 'is_manually_edited' => 1, 'approval_status' => 'PENDING']);
        $this->assertSame($events, DB::table('attendance_events')->get()->toJson());
        $this->punch('2026-10-13T08:30:00+09:00', 'day-2')->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_IN')->assertJsonPath('data.resultType', 'SUCCESS');
        $this->assertDatabaseCount('attendance_daily', 2);
    }

    public function test_completed_previous_day_has_no_warning(): void
    {
        $this->punch('2026-10-12T08:30:00+09:00', 'day-1-in')->assertOk();
        $this->punch('2026-10-12T17:30:00+09:00', 'day-1-out')->assertOk();
        $this->punch('2026-10-13T08:30:00+09:00', 'day-2-in')->assertOk()
            ->assertJsonPath('data.eventType', 'CLOCK_IN')->assertJsonPath('data.resultType', 'SUCCESS');
    }

    private function punch(string $time, string $key): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/attendance/punch', ['deviceCode' => 'P2-DAY', 'deviceSecret' => 'synthetic-secret',
            'cardUid' => 'P2-DAY-CARD', 'occurredAt' => $time, 'dedupeKey' => $key, 'appVersion' => 'synthetic']);
    }
}
