<?php

namespace Tests\Feature;

use App\Services\ApiException;
use App\Services\Leave\LeaveApplyService;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Leave\LeaveAttendanceProjection;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\PaidLeaveGrantReviewService;
use App\Services\Leave\HourlyPaidLeaveService;
use App\Services\HarmosMigrationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LeaveSafetyRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $employeeId;
    private GenericUser $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 12:00:00'));
        config(['staffhub.google_chat.enabled' => false, 'mail.default' => 'array']);
        $this->employeeId = DB::table('employees')->insertGetId(['employee_code' => 'SAF001', 'name' => '検証職員',
            'employment_type' => 'FULL_TIME', 'status' => 'ACTIVE', 'joined_on' => '2026-04-01', 'created_at' => now(), 'updated_at' => now()]);
        $adminId = DB::table('users')->insertGetId(['name' => '実行管理者', 'email' => 'leave-safety@example.test', 'password' => Hash::make('VerifyOnly-2026!')]);
        $this->actor = new GenericUser(['id' => $adminId, 'name' => '実行管理者', 'role' => 'ADMIN', 'is_admin' => true]);
        DB::table('paid_leave_grants')->insert(['employee_id' => $this->employeeId, 'granted_on' => '2026-04-01',
            'granted_days' => 10, 'used_days' => 0, 'expires_on' => '2027-03-31', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(string $date = '2026-10-12'): array
    {
        return ['requestCategory' => 'LEAVE', 'leaveTypeCode' => 'PAID', 'startDate' => $date, 'endDate' => $date, 'dayUnit' => 'FULL'];
    }

    private function insertRequest(array $overrides = []): int
    {
        return DB::table('leave_requests')->insertGetId(array_replace([
            'employee_id' => $this->employeeId, 'leave_type_code' => 'PAID', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12',
            'day_unit' => 'FULL', 'request_category' => 'LEAVE', 'quantity_days' => 1, 'status' => 'PENDING',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function expectCode(string $code, callable $operation): void
    {
        try { $operation(); $this->fail('Expected '.$code); }
        catch (ApiException $e) { $this->assertSame($code, $e->errorCode); }
    }

    public function test_reject_return_and_pending_cancel_preserve_imported_and_manual_values_exactly(): void
    {
        DB::table('attendance_daily')->insert(['employee_id' => $this->employeeId, 'target_date' => '2026-10-12',
            'paid_leave_unit' => .5, 'hour_paid_leave_minutes' => 60, 'child_care_leave_minutes' => 30,
            'source_system' => 'HARMOS', 'is_manually_edited' => 1, 'remark' => '原本', 'updated_at' => now()]);
        $before = DB::table('attendance_daily')->first();
        foreach (['REJECTED', 'RETURNED', 'CANCELLED'] as $decision) {
            $id = $this->insertRequest();
            if ($decision === 'CANCELLED') { app(LeaveApplyService::class)->cancelForEmployee($this->employeeId, $id); }
            else { app(LeaveApprovalService::class)->decide($id, $decision, null, $this->actor); }
            $this->assertEquals($before, DB::table('attendance_daily')->first());
        }
        $this->assertDatabaseCount('attendance_leave_projections', 0);
    }

    public function test_local_approval_and_cancel_restore_baseline_without_changing_manual_time(): void
    {
        DB::table('attendance_daily')->insert(['employee_id' => $this->employeeId, 'target_date' => '2026-10-12',
            'clock_in_at' => '2026-10-12 08:00:00', 'clock_out_at' => '2026-10-12 12:00:00', 'work_minutes' => 240,
            'raw_clock_in_at' => '2026-10-12 08:03:00', 'is_manually_edited' => 1, 'remark' => '時刻確認済み', 'updated_at' => now()]);
        $p = array_replace($this->payload(), ['dayUnit' => 'HALF', 'halfDayType' => 'PM']);
        $id = app(LeaveApplyService::class)->createForEmployee($this->employeeId, $p)['id'];
        app(LeaveApprovalService::class)->decide($id, 'APPROVED', null, $this->actor);
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $this->employeeId, 'paid_leave_unit' => .5, 'work_minutes' => 240, 'is_manually_edited' => 1]);
        app(LeaveApplyService::class)->cancelForEmployee($this->employeeId, $id);
        $this->assertDatabaseHas('attendance_daily', ['employee_id' => $this->employeeId, 'paid_leave_unit' => null,
            'clock_in_at' => '2026-10-12 08:00:00', 'raw_clock_in_at' => '2026-10-12 08:03:00', 'remark' => '時刻確認済み']);
        $this->assertEquals(10, app(LeaveLedgerService::class)->balance($this->employeeId)['currentBalance']);
    }

    public function test_legacy_approved_row_needs_explicit_baseline_then_cancel_preserves_imported_hours(): void
    {
        $id = $this->insertRequest(['status' => 'APPROVED']);
        DB::table('attendance_daily')->insert(['employee_id' => $this->employeeId, 'target_date' => '2026-10-12',
            'paid_leave_unit' => 1, 'child_care_leave_minutes' => 60, 'source_system' => 'HARMOS', 'updated_at' => now()]);
        $this->expectCode('LEAVE_PROVENANCE_REVIEW_REQUIRED', fn () => app(LeaveApplyService::class)->cancelForEmployee($this->employeeId, $id));
        $this->assertDatabaseHas('leave_requests', ['id' => $id, 'status' => 'APPROVED']);
        $service = app(LeaveAttendanceProjection::class);
        $review = $service->review($this->employeeId, '2026-10-12');
        $baseline = $service->fields(null); $baseline['child_care_leave_minutes'] = 60;
        $service->confirmBaseline(['employeeId' => $this->employeeId, 'targetDate' => '2026-10-12', 'baseline' => $baseline,
            'evidenceReference' => '移行CSVと申請原本を照合', 'fingerprint' => $review['fingerprint']], $this->actor);
        app(LeaveApplyService::class)->cancelForEmployee($this->employeeId, $id);
        $this->assertDatabaseHas('attendance_daily', ['paid_leave_unit' => null, 'child_care_leave_minutes' => 60, 'source_system' => 'HARMOS']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'LEAVE_BASELINE_CONFIRMED']);
    }

    public function test_external_leave_change_is_never_overwritten_by_cancel(): void
    {
        $id = app(LeaveApplyService::class)->createForEmployee($this->employeeId, $this->payload())['id'];
        app(LeaveApprovalService::class)->decide($id, 'APPROVED', null, $this->actor);
        DB::table('attendance_daily')->where('employee_id', $this->employeeId)->update(['hour_paid_leave_minutes' => 30]);
        $this->expectCode('LEAVE_PROVENANCE_REVIEW_REQUIRED', fn () => app(LeaveApplyService::class)->cancelForEmployee($this->employeeId, $id));
        $this->assertDatabaseHas('attendance_daily', ['paid_leave_unit' => 1, 'hour_paid_leave_minutes' => 30]);
        $this->assertDatabaseHas('leave_requests', ['id' => $id, 'status' => 'APPROVED']);
    }

    public function test_reviewed_legacy_duplicates_can_be_cancelled_without_guessing_or_overwriting_daily(): void
    {
        $ids = [$this->insertRequest(['status' => 'APPROVED']), $this->insertRequest(['status' => 'APPROVED']), $this->insertRequest(['status' => 'APPROVED'])];
        DB::table('attendance_daily')->insert(['employee_id' => $this->employeeId, 'target_date' => '2026-10-12', 'paid_leave_unit' => 1, 'updated_at' => now()]);
        $service = app(LeaveAttendanceProjection::class);
        $review = $service->review($this->employeeId, '2026-10-12');
        $this->assertTrue($review['requiresDuplicateResolution']);
        $service->confirmBaseline(['employeeId' => $this->employeeId, 'targetDate' => '2026-10-12', 'baseline' => $service->fields(null),
            'evidenceReference' => '原本照合し、3件は同じ休暇の重複と確認', 'fingerprint' => $review['fingerprint']], $this->actor);
        foreach (array_slice($ids, 0, 2) as $id) {
            app(LeaveApplyService::class)->cancelForEmployee($this->employeeId, $id);
            $this->assertDatabaseHas('attendance_daily', ['paid_leave_unit' => 1]);
        }
        app(LeaveApplyService::class)->cancelForEmployee($this->employeeId, $ids[2]);
        $this->assertDatabaseHas('attendance_daily', ['paid_leave_unit' => null]);
    }

    public function test_duplicate_whole_half_and_crossing_periods_are_rejected_at_apply_and_approval(): void
    {
        $service = app(LeaveApplyService::class);
        $service->createForEmployee($this->employeeId, array_replace($this->payload(), ['endDate' => '2026-10-14']));
        $this->expectCode('LEAVE_OVERLAP', fn () => $service->createForEmployee($this->employeeId, $this->payload('2026-10-13')));
        $this->expectCode('LEAVE_OVERLAP', fn () => $service->createForEmployee($this->employeeId,
            array_replace($this->payload('2026-10-14'), ['dayUnit' => 'HALF', 'halfDayType' => 'PM'])));
        $duplicate = $this->insertRequest();
        $this->expectCode('LEAVE_OVERLAP', fn () => app(LeaveApprovalService::class)->decide($duplicate, 'APPROVED', null, $this->actor));
        $this->assertDatabaseMissing('attendance_daily', ['employee_id' => $this->employeeId]);
        $this->assertDatabaseHas('leave_requests', ['id' => $duplicate, 'status' => 'PENDING']);
    }

    public function test_hourly_overlap_and_total_limit_are_checked_but_adjacent_intervals_are_allowed(): void
    {
        $service = app(LeaveApplyService::class);
        $p = ['requestCategory' => 'TIME_LEAVE', 'timeLeaveType' => 'PAID_HOURLY', 'targetDate' => '2026-10-12', 'startTime' => '09:00', 'endTime' => '13:00'];
        $service->createForEmployee($this->employeeId, $p);
        $this->expectCode('LEAVE_OVERLAP', fn () => $service->createForEmployee($this->employeeId, array_replace($p, ['startTime' => '12:00', 'endTime' => '14:00'])));
        $service->createForEmployee($this->employeeId, array_replace($p, ['startTime' => '13:00', 'endTime' => '17:00']));
        $this->expectCode('LEAVE_DAILY_LIMIT', fn () => $service->createForEmployee($this->employeeId, array_replace($p, ['startTime' => '17:00', 'endTime' => '18:00'])));
        $this->expectCode('VALIDATION_ERROR', fn () => $service->createForEmployee($this->employeeId, array_replace($p, ['quantityMinutes' => 1])));
        $this->expectCode('VALIDATION_ERROR', fn () => $service->createForEmployee($this->employeeId, array_replace($p, ['startTime' => '99:99'])));
        $this->insertRequest(['request_category' => 'TIME_LEAVE', 'time_leave_type' => 'PAID_HOURLY',
            'start_date' => '2026-10-13', 'end_date' => '2026-10-13', 'day_unit' => 'HOURLY', 'quantity_minutes' => 60, 'quantity_days' => 0]);
        $this->expectCode('LEAVE_OVERLAP', fn () => $service->createForEmployee($this->employeeId, array_replace($p, ['targetDate' => '2026-10-13'])));
    }

    public function test_imported_leave_is_detected_before_accepting_duplicate_application(): void
    {
        DB::table('attendance_daily')->insert(['employee_id' => $this->employeeId, 'target_date' => '2026-10-12', 'paid_leave_unit' => 1, 'source_system' => 'HARMOS', 'updated_at' => now()]);
        $this->expectCode('LEAVE_OVERLAP_WITH_RECORDED', fn () => app(LeaveApplyService::class)->createForEmployee($this->employeeId, $this->payload()));
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_cross_month_closed_period_is_rejected_and_query_uses_overlapping_dates(): void
    {
        DB::table('attendance_monthly_closes')->insert(['target_year_month' => '2026-11', 'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectCode('CLOSED_PERIOD', fn () => app(LeaveApplyService::class)->createForEmployee($this->employeeId,
            array_replace($this->payload('2026-10-31'), ['endDate' => '2026-11-01'])));
        $id = $this->insertRequest(['start_date' => '2026-09-30', 'end_date' => '2026-10-02', 'quantity_days' => 3]);
        $result = app(LeaveApprovalService::class)->listForAdmin(['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame($id, $result['items'][0]['id']);
    }

    public function test_admin_identity_is_real_in_actions_and_list_without_fake_employee_fallback(): void
    {
        $id = app(LeaveApplyService::class)->createForEmployee($this->employeeId, $this->payload())['id'];
        app(LeaveApprovalService::class)->decide($id, 'APPROVED', null, $this->actor);
        $this->assertDatabaseHas('leave_requests', ['id' => $id, 'approved_by' => null, 'approved_actor_type' => 'ADMIN', 'approved_actor_id' => $this->actor->id]);
        $detail = app(LeaveApprovalService::class)->detailForAdmin($id);
        $this->assertSame('実行管理者', end($detail['actions'])['actionByName']);
        $list = app(LeaveApprovalService::class)->listForAdmin([]);
        $this->assertSame('実行管理者', $list['items'][0]['approvedByName']);
    }

    public function test_grant_review_preserves_total_effective_date_source_and_original_history(): void
    {
        $grantId = (int) DB::table('paid_leave_grants')->value('id');
        DB::table('paid_leave_grants')->where('id', $grantId)->update(['expires_on' => null, 'source_system' => 'HARMOS']);
        $service = app(PaidLeaveGrantReviewService::class);
        $p = ['lots' => [['days' => 4, 'expiresOn' => '2026-12-31', 'originalGrantedOn' => '2025-01-01'],
            ['days' => 6, 'expiresOn' => '2027-03-31']], 'evidenceReference' => '付与管理原本 2026-10-10'];
        $preview = $service->preview($grantId, $p);
        $this->assertEquals(10, $preview['afterBalance']);
        $this->assertDatabaseCount('paid_leave_grants', 1);
        $result = $service->confirm($grantId, [...$p, 'fingerprint' => $preview['fingerprint']], $this->actor);
        $this->assertEquals(10, $result['currentBalance']);
        $this->assertCount(2, $result['grants']);
        $this->assertSame('2026-04-01', $result['grants'][0]['grantedOn']);
        $this->assertEquals(10, array_sum(array_column($result['grants'], 'grantedDays')));
        $this->assertFalse($result['grants'][0]['expiryNeedsReview']);
        $this->assertDatabaseHas('paid_leave_grants', ['id' => $grantId, 'granted_days' => 10]);
        $this->assertNotNull(DB::table('paid_leave_grants')->where('id', $grantId)->value('superseded_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'PAID_LEAVE_GRANT_REVIEWED']);
    }

    public function test_grant_review_rejects_sum_mismatch_and_stale_preview_and_shows_expiry_effect(): void
    {
        $grantId = (int) DB::table('paid_leave_grants')->value('id');
        $service = app(PaidLeaveGrantReviewService::class);
        $p = ['lots' => [['days' => 10, 'expiresOn' => '2026-09-30']], 'evidenceReference' => '原本確認'];
        $this->assertEquals(0, $service->preview($grantId, $p)['afterBalance']);
        $this->expectCode('GRANT_TOTAL_MISMATCH', fn () => $service->preview($grantId, array_replace($p, ['lots' => [['days' => 9, 'expiresOn' => '2027-03-31']]])));
        $preview = $service->preview($grantId, $p);
        DB::table('paid_leave_grants')->where('id', $grantId)->update(['note' => '別の更新']);
        $this->expectCode('STALE_LEAVE_PREVIEW', fn () => $service->confirm($grantId, [...$p, 'fingerprint' => $preview['fingerprint']], $this->actor));
        $this->assertDatabaseCount('paid_leave_grants', 1);
    }

    public function test_source_review_endpoints_require_admin_and_validate_before_mutation(): void
    {
        $grantId = (int) DB::table('paid_leave_grants')->value('id');
        $balanceUrl = '/api/admin/leave/employees/'.$this->employeeId.'/balance';
        $this->getJson($balanceUrl)->assertUnauthorized();
        DB::table('employee_auth')->insert(['employee_id' => $this->employeeId, 'login_id' => 'leave-safe-staff',
            'password_hash' => Hash::make('VerifyOnly-2026!'), 'created_at' => now(), 'updated_at' => now()]);
        $staffToken = $this->postJson('/api/auth/login', ['loginId' => 'leave-safe-staff', 'password' => 'VerifyOnly-2026!', 'audience' => 'EMPLOYEE'])->json('data.accessToken');
        $this->withToken($staffToken)->getJson($balanceUrl)->assertForbidden();
        $this->withToken($staffToken)->postJson('/api/admin/leave/grants/'.$grantId.'/review-preview', [])->assertForbidden();
        $this->withToken($staffToken)->postJson('/api/admin/leave/attendance-baseline', [])->assertForbidden();
        $adminToken = $this->postJson('/api/auth/login', ['loginId' => 'leave-safety@example.test', 'password' => 'VerifyOnly-2026!', 'audience' => 'ADMIN'])->json('data.accessToken');
        $this->withToken($adminToken)->getJson($balanceUrl.'?asOf=2026-10-12')->assertOk()->assertJsonPath('data.asOf', '2026-10-12');
        $this->withToken($adminToken)->postJson('/api/admin/leave/grants/'.$grantId.'/review-preview', [
            'lots' => [['days' => 10, 'expiresOn' => '']], 'evidenceReference' => '付与原本',
        ])->assertUnprocessable()->assertJsonPath('error.details.0.field', 'lots.0.expiresOn');
        $this->assertDatabaseCount('paid_leave_grants', 1);
        $payload = ['lots' => [['days' => 10, 'expiresOn' => '2027-03-31']], 'evidenceReference' => '付与原本'];
        $preview = $this->withToken($adminToken)->postJson('/api/admin/leave/grants/'.$grantId.'/review-preview', $payload)->assertOk();
        $this->assertDatabaseCount('paid_leave_grants', 1);
        $this->withToken($adminToken)->postJson('/api/admin/leave/grants/'.$grantId.'/review', $payload)
            ->assertUnprocessable()->assertJsonPath('error.details.0.field', 'fingerprint');
        $this->withToken($adminToken)->postJson('/api/admin/leave/grants/'.$grantId.'/review', [...$payload, 'fingerprint' => $preview->json('data.fingerprint')])->assertOk();
        $this->assertDatabaseCount('paid_leave_grants', 2);
    }

    public function test_baseline_stale_confirmation_does_not_write_or_change_recorded_values(): void
    {
        $service = app(LeaveAttendanceProjection::class);
        $review = $service->review($this->employeeId, '2026-10-12');
        DB::table('attendance_daily')->insert(['employee_id' => $this->employeeId, 'target_date' => '2026-10-12',
            'child_care_leave_minutes' => 30, 'updated_at' => now()]);
        $this->expectCode('STALE_LEAVE_PREVIEW', fn () => $service->confirmBaseline([
            'employeeId' => $this->employeeId, 'targetDate' => '2026-10-12', 'baseline' => $service->fields(null),
            'evidenceReference' => '原本確認', 'fingerprint' => $review['fingerprint'],
        ], $this->actor));
        $this->assertDatabaseCount('attendance_leave_projections', 0);
        $this->assertDatabaseHas('attendance_daily', ['child_care_leave_minutes' => 30]);
    }

    public function test_hourly_asof_uses_separate_balance_and_never_falls_back_outside_configured_period(): void
    {
        $hourly = app(HourlyPaidLeaveService::class);
        $hourly->setBalance(['employeeId' => $this->employeeId, 'effectiveOn' => '2026-10-10', 'expiresOn' => '2026-10-31',
            'remainingMinutes' => 120, 'totalMinutes' => 120], $this->actor);
        $service = app(LeaveApplyService::class);
        $payload = ['requestCategory' => 'TIME_LEAVE', 'timeLeaveType' => 'PAID_HOURLY', 'targetDate' => '2026-10-12', 'startTime' => '09:00', 'endTime' => '10:00'];
        $id = $service->createForEmployee($this->employeeId, $payload)['id'];
        $this->assertDatabaseHas('leave_requests', ['id' => $id, 'quantity_days' => 0, 'quantity_minutes' => 60]);
        app(LeaveApprovalService::class)->decide($id, 'APPROVED', null, $this->actor);
        $ledger = app(LeaveLedgerService::class);
        $this->assertEquals(10, $ledger->balance($this->employeeId)['currentBalance']);
        $this->assertSame(120, $ledger->balance($this->employeeId, '2026-10-11')['hourlyBalance']['remainingAsOfMinutes']);
        $this->assertSame(60, $ledger->balance($this->employeeId, '2026-10-12')['hourlyBalance']['remainingAsOfMinutes']);
        $outside = $ledger->balance($this->employeeId, '2026-11-01');
        $this->assertTrue($outside['hourlyManagementConfigured']);
        $this->assertNull($outside['hourlyBalance']);
        $this->expectCode('HOURLY_PAID_LEAVE_NOT_CONFIGURED', fn () => $service->createForEmployee($this->employeeId,
            array_replace($payload, ['targetDate' => '2026-11-01'])));
        $this->expectCode('HOURLY_PAID_LEAVE_BEFORE_BASELINE', fn () => $service->createForEmployee($this->employeeId,
            array_replace($payload, ['targetDate' => '2026-10-10'])));
    }

    public function test_hourly_management_change_does_not_double_charge_day_conversion(): void
    {
        $payload = ['requestCategory' => 'TIME_LEAVE', 'timeLeaveType' => 'PAID_HOURLY', 'targetDate' => '2026-10-12', 'startTime' => '09:00', 'endTime' => '10:00'];
        $id = app(LeaveApplyService::class)->createForEmployee($this->employeeId, $payload)['id'];
        $hourly = app(HourlyPaidLeaveService::class);
        $setting = ['employeeId' => $this->employeeId, 'effectiveOn' => '2026-10-10', 'expiresOn' => '2026-10-31',
            'remainingMinutes' => 120, 'totalMinutes' => 120];
        $hourly->setBalance($setting, $this->actor);
        $this->expectCode('HOURLY_BALANCE_CHANGED', fn () => app(LeaveApprovalService::class)->decide($id, 'APPROVED', null, $this->actor));
        $this->assertDatabaseHas('leave_requests', ['id' => $id, 'status' => 'PENDING']);
        DB::table('paid_leave_hourly_balances')->delete();
        app(LeaveApprovalService::class)->decide($id, 'APPROVED', null, $this->actor);
        $this->expectCode('HOURLY_BALANCE_CHANGED', fn () => $hourly->setBalance($setting, $this->actor));
        $this->assertDatabaseCount('paid_leave_hourly_balances', 0);
    }

    public function test_harmos_residual_reimport_cannot_overwrite_source_reviewed_lots(): void
    {
        $grantId = (int) DB::table('paid_leave_grants')->value('id');
        DB::table('paid_leave_grants')->where('id', $grantId)->update(['source_system' => 'HARMOS']);
        $service = app(PaidLeaveGrantReviewService::class);
        $payload = ['lots' => [['days' => 10, 'expiresOn' => '2027-03-31']], 'evidenceReference' => '付与原本'];
        $preview = $service->preview($grantId, $payload);
        $service->confirm($grantId, [...$payload, 'fingerprint' => $preview['fingerprint']], $this->actor);
        $before = DB::table('paid_leave_grants')->orderBy('id')->get()->all();
        $file = UploadedFile::fake()->createWithContent('verify-residual.csv', "\xEF\xBB\xBF社員番号,有給残数\nSAF001,20\n");
        $result = app(HarmosMigrationService::class)->preview(['importType' => 'HARMOS_PAID_LEAVE_BALANCE_CSV', 'migrationDate' => '2026-04-01'], $file);
        $this->assertSame(0, $result['successCount']);
        $this->assertSame(1, $result['errorCount']);
        $this->assertEquals($before, DB::table('paid_leave_grants')->orderBy('id')->get()->all());
        $this->assertEquals(10, app(LeaveLedgerService::class)->balance($this->employeeId)['currentBalance']);
    }
}
