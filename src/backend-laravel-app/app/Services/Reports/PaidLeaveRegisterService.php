<?php

namespace App\Services\Reports;

use App\Services\ApiException;
use App\Services\Leave\HourlyPaidLeaveService;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\PaidLeaveProjection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PaidLeaveRegisterService
{
    public function __construct(
        private readonly PaidLeaveProjection $projection,
        private readonly HourlyPaidLeaveService $hourly,
        private readonly PaidLeaveRegisterReconciliation $reconciliation,
        private readonly PaidLeaveFiscalHistory $fiscalHistory,
    ) {}

    public function forEmployee(int $employeeId, string $from, string $to): array
    {
        $employee = DB::table('employees')->where('id', $employeeId)->first(['id','employee_code','name','status']);
        if (!$employee) {
            throw new ApiException('NOT_FOUND', '職員が見つかりません。', 404);
        }
        $today = now()->toDateString();
        $openingDate = min($today, CarbonImmutable::parse($from)->subDay()->toDateString());
        $closingDate = min($today, $to);
        $allGrants = DB::table('paid_leave_grants')->where('employee_id', $employeeId)
            ->where('granted_on', '<=', $to)->orderBy('granted_on')->orderBy('id')->get()->keyBy('id');
        $grants = $allGrants->whereNull('superseded_at');
        [$warnings, $incompleteFrom] = $this->grantHistoryChecks($allGrants, $openingDate);
        $adjustments = DB::table('paid_leave_adjustments')->where('employee_id', $employeeId)
            ->where('effective_on', '<=', $to)->orderBy('id')->get()->keyBy('id');
        $requests = LeaveLedgerService::paidRequests($employeeId)->where('status', 'APPROVED')
            ->where('start_date', '<=', $to)->orderBy('id')->get()->keyBy('id');
        $projection = $this->projection->calculate($grants->all(), $adjustments->all(), $requests->all(), $to);
        $hours = DB::table('leave_requests')->where('employee_id', $employeeId)->where('status', 'APPROVED')
            ->where('time_leave_type', 'PAID_HOURLY')->whereBetween('start_date', [$from, $to])->orderBy('id')->get();
        $receipted = $this->receiptedAdjustments($adjustments);
        $firstGrant = $grants->first()?->granted_on;
        $opening = $closing = null;
        $entries = [];
        $knownDays = $knownMinutes = $shownHours = $requestDays = $migratedUse = $balanceAtDate = [];
        foreach ($projection['items'] as $event) {
            $date = $event['occurredOn'];
            $balanceAtDate[$date] = (float) $event['balanceAfter'];
            if ($event['entryType'] === 'USE' && $requests[$event['sourceId']]->time_leave_type !== 'PAID_HOURLY') {
                $requestDays[$date] = ($requestDays[$date] ?? 0) - $event['daysDelta'];
            } elseif ($event['entryType'] === 'ADJUST_MINUS' && isset($receipted[$event['sourceId']]) && $event['daysDelta'] < 0) {
                $migratedUse[$date]['days'] = ($migratedUse[$date]['days'] ?? 0) - $event['daysDelta'];
                $migratedUse[$date]['ids'][] = $event['sourceId'];
            }
        }
        foreach ($grants->where('source_system', 'HARMOS')->groupBy('granted_on') as $date => $lots) {
            $snapshot = (float) $lots->sum('granted_days');
            $computed = $balanceAtDate[$date];
            if (abs($snapshot - $computed) > 0.000001) {
                $warnings[] = ['date' => $date, 'kind' => 'MIGRATION_BALANCE_DIFFERENCE',
                    'message' => '移行原本の同日残は'.$snapshot.'日、アプリ台帳の同日終了時の計算残は'.$computed.'日です。移行前の取得や同日までの付与・調整が含まれる可能性があります。原本を確認してください。'];
            }
        }

        foreach ($projection['items'] as $event) {
            $date = $event['occurredOn'];
            $knownBalance = $firstGrant !== null && $date >= $firstGrant && ($incompleteFrom === null || $date < $incompleteFrom)
                ? (float) $event['balanceAfter'] : null;
            if ($date <= $openingDate) { $opening = $knownBalance; }
            if ($date <= $closingDate) { $closing = $knownBalance; }
            if ($date < $from) { continue; }

            $type = $event['entryType'];
            $id = $event['sourceId'];
            $row = $this->entry($date, $type, $event['sourceType'], $id, $today);
            $row['deltaDays'] = $event['daysDelta'];
            $row['balanceAfterDays'] = $date <= $today ? $knownBalance : null;
            if ($type === 'GRANT' || $type === 'EXPIRE') {
                $grant = $grants[$id];
                $migrated = $grant->source_system === 'HARMOS';
                if ($type === 'GRANT' && $migrated) { $row['kind'] = 'MIGRATION_OPENING'; }
                $label = $type === 'EXPIRE' ? '付与期限による失効' : ($migrated ? '移行日時点の残日数' : '付与記録');
                $row['evidence'] = $label.' #'.$id.$this->reference($grant->evidence_reference ?: $grant->source_file_name);
            } elseif ($type === 'USE') {
                $request = $requests[$id];
                if ($request->time_leave_type === 'PAID_HOURLY') {
                    $row['unit'] = 'HOUR';
                    $row['minutes'] = (int) $request->quantity_minutes;
                    $shownHours[$id] = true;
                    $knownMinutes[$date] = ($knownMinutes[$date] ?? 0) + $row['minutes'];
                } else {
                    $row['days'] = -$row['deltaDays'];
                    $row['unit'] = $this->dayUnit($row['days']);
                }
                $period = ['AM' => '午前半日', 'PM' => '午後半日'][$request->half_day_type] ?? null;
                $row['evidence'] = '承認済み休暇申請 #'.$id.$this->reference($period);
            } else {
                $adjustment = $adjustments[$id];
                $row['evidence'] = '残数調整 #'.$id.$this->reference($adjustment->note);
                if ($type === 'ADJUST_MINUS' && isset($receipted[$id]) && $row['deltaDays'] < 0) {
                    $amount = -$row['deltaDays'];
                    if (isset($requestDays[$date])) {
                        $row['evidence'] .= '。同日の承認済み申請と合わせて原票照合の対象。';
                    } else {
                        $row['kind'] = 'USE';
                        $row['days'] = $amount;
                        $row['unit'] = $this->dayUnit($amount);
                    }
                }
            }
            $entries[] = $row;
        }
        $knownDays = $requestDays;
        foreach ($migratedUse as $date => $migration) {
            $imported = $migration['days'];
            $approved = $requestDays[$date] ?? 0;
            $knownDays[$date] = max($imported, $approved);
            if ($approved <= 0) { continue; }
            $references = '#'.implode('、#', $migration['ids']);
            $message = '移行原票の取得控除は計'.$imported.'日（調整'.$references.'）、同日の承認済み申請は計'.$approved.'日です。'
                .'アプリの記帳残は両方を控除しており、重複控除の可能性があります。取得実績には申請分を集計し、午前・午後の対応は原票で確認してください。';
            $warnings[] = ['date' => $date, 'kind' => 'OVERLAPPING_LEDGER_DEDUCTIONS', 'message' => $message];
            $difference = round(abs($imported - $approved), 10);
            if ($date >= $from && $difference > 0.000001) {
                $row = $this->entry($date, 'ATTENDANCE_CANDIDATE', 'RECONCILIATION', null, $today);
                $row['status'] = $date > $today ? 'FUTURE' : 'REFERENCE';
                $row['unit'] = $this->dayUnit($difference);
                $row['days'] = $difference;
                $row['evidence'] = '移行原票の控除'.$imported.'日（'.$references.'）と承認済み申請'.$approved.'日の数量差。'
                    .'原票照合用であり、追加取得として集計しません。';
                $entries[] = $row;
            }
        }
        // Hourly management books minutes in a separate snapshot; these requests can have zero day movement.
        foreach ($hours as $request) {
            if (isset($shownHours[$request->id])) { continue; }
            $date = $request->start_date;
            $row = $this->entry($date, 'USE', 'LEAVE_REQUEST', (int) $request->id, $today);
            $row['unit'] = 'HOUR';
            $row['minutes'] = (int) $request->quantity_minutes;
            $row['deltaDays'] = 0;
            $row['evidence'] = '承認済み時間有給申請 #'.$request->id.'（時間枠）';
            $knownMinutes[$date] = ($knownMinutes[$date] ?? 0) + $row['minutes'];
            $entries[] = $row;
        }
        $daily = DB::table('attendance_daily')->where('employee_id', $employeeId)->whereBetween('target_date', [$from, $to])
            ->where(fn ($q) => $q->where('paid_leave_unit', '>', 0)->orWhere('hour_paid_leave_minutes', '>', 0))
            ->orderBy('target_date')->orderBy('id')->get();
        foreach ($daily as $d) { $d->employee_code = $employee->employee_code; }
        app(\App\Services\Attendance\DailyApprovalReview::class)->annotate($daily);
        foreach ($daily as $d) {
            foreach (['DAY', 'HOUR'] as $unit) {
                $hour = $unit === 'HOUR';
                $recorded = $hour ? (int) $d->hour_paid_leave_minutes : (float) $d->paid_leave_unit;
                $known = $hour ? ($knownMinutes[$d->target_date] ?? 0) : ($knownDays[$d->target_date] ?? 0);
                if ($recorded <= 0 || $recorded <= $known + 0.000001) { continue; }
                $row = $this->entry($d->target_date, 'ATTENDANCE_CANDIDATE', 'ATTENDANCE_DAILY', (int) $d->id, $today);
                $row['status'] = $d->target_date > $today ? 'FUTURE' : 'REFERENCE';
                $remainder = $recorded - $known;
                $row['unit'] = $hour ? 'HOUR' : $this->dayUnit($remainder);
                $row[$hour ? 'minutes' : 'days'] = $remainder;
                $approval = ['APPROVED' => '日次承認済み', 'PENDING' => '日次要確認', 'NOT_REQUIRED'=>'日次承認不要', 'NOT_DUE'=>'勤務中・予定', 'RETURNED' => '日次差戻し'][$d->review_status] ?? '日次承認未確認';
                $row['evidence'] = '勤怠日次 #'.$d->id.$this->reference($d->source_file_name).'・'.$approval
                    .'。日次の取得欄と台帳との差。休暇取得の原本を確認してください。';
                $entries[] = $row;
            }
        }
        // Preserve the ledger's same-day sequence (including expiry before grant).
        usort($entries, static fn ($a, $b) => strcmp($a['date'], $b['date']));
        $actual = collect($entries)->where('kind', 'USE')->where('status', 'RECORDED');

        return [
            'employee' => ['id' => (int) $employee->id, 'code' => $employee->employee_code, 'name' => $employee->name, 'status' => $employee->status],
            'from' => $from, 'to' => $to, 'openingBalanceAsOf' => $openingDate, 'balanceAsOf' => $closingDate,
            'openingBalanceDays' => $incompleteFrom !== null && $openingDate >= $incompleteFrom ? null : $opening,
            'closingBalanceDays' => $incompleteFrom !== null && $closingDate >= $incompleteFrom ? null : $closing,
            'openingHourlyBalance' => $this->hourlyBalance($employeeId, $openingDate),
            'closingHourlyBalance' => $this->hourlyBalance($employeeId, $closingDate),
            'review' => $this->reconciliation->forEmployee((int) $employee->id, $employee->employee_code), 'warnings' => $warnings,
            'fiscalLedger' => $this->fiscalHistory->forEmployee($employeeId, $employee->employee_code, $from, $to, $today,
                $entries, $balanceAtDate, $firstGrant, $incompleteFrom, $warnings,
                $grants->isNotEmpty() && !$receipted && $allGrants->every(fn ($grant) => $grant->source_system === null)),
            'baseDates' => $grants->filter(fn ($g) => $g->expires_on === null || $g->expires_on >= $from)
                ->map(fn ($g) => [
                    'date' => $g->source_system === 'HARMOS' ? $g->original_granted_on : $g->granted_on,
                    'recordedOn' => $g->granted_on, 'kind' => $g->source_system === 'HARMOS' ? 'MIGRATION_OPENING' : 'GRANT',
                    'days' => (float) $g->granted_days, 'expiresOn' => $g->expires_on,
                    'evidence' => $g->evidence_reference ?: $g->source_file_name,
                ])->values()->all(),
            'entries' => $entries, 'totals' => [
                'usedDays' => $actual->sum('days'), 'usedMinutes' => $actual->sum('minutes'),
                'halfDayCount' => $actual->where('unit', 'HALF_DAY')->count(),
            ],
        ];
    }

    /** Reviewed child lots are effective on the old opening date. Never add their superseded parent again. */
    private function grantHistoryChecks(Collection $allGrants, string $openingDate): array
    {
        $warnings = [];
        $incompleteFrom = null;
        $children = $allGrants->whereNotNull('parent_grant_id')->groupBy('parent_grant_id');
        foreach ($allGrants->whereNotNull('superseded_at') as $parent) {
            $lots = $children->get($parent->id, collect());
            $date = substr($parent->superseded_at, 0, 10);
            if ($lots->isEmpty() || abs($lots->sum('granted_days') - $parent->granted_days) > 0.000001) {
                $incompleteFrom = $incompleteFrom === null ? $parent->granted_on : min($incompleteFrom, $parent->granted_on);
                $warnings[] = ['date' => $date, 'kind' => 'MISSING_GRANT_HISTORY', 'message' => '付与 #'.$parent->id
                    .' の見直し前後の内訳を照合できません。'.$parent->granted_on.'以降の残日数は原本を確認してください。'];
            } elseif ($date > $openingDate) {
                $warnings[] = ['date' => $date, 'kind' => 'RESTATED_GRANTS', 'message' => '付与 #'.$parent->id
                    .' の内訳はこの日に見直されています。過去日の残も現在の内訳から再計算しており、当時の締め時点の記録は原本で確認してください。'];
            }
        }
        return [$warnings, $incompleteFrom];
    }

    private function entry(string $date, string $kind, string $source, ?int $id, string $today): array
    {
        return [
            'date' => $date, 'kind' => $kind, 'status' => $date > $today ? 'FUTURE' : 'RECORDED',
            'unit' => null, 'days' => null, 'minutes' => null, 'deltaDays' => null, 'balanceAfterDays' => null,
            'evidence' => '', 'sourceType' => $source, 'sourceId' => $id,
        ];
    }

    private function dayUnit(float $days): string
    {
        return abs($days - 0.5) < 0.000001 ? 'HALF_DAY' : 'DAY';
    }

    private function reference(?string $text): string
    {
        return $text ? '（'.$text.'）' : '';
    }

    private function hourlyBalance(int $employee, string $date): ?array
    {
        $balance = $this->hourly->balance($employee, $date);
        if ($balance === null) { return null; }
        return [
            'annualMinutes' => $balance['remainingAsOfMinutes'],
            'totalMinutes' => $balance['totalRemainingMinutes'] === null ? null
                : $balance['totalRemainingMinutes'] + $balance['futureApprovedMinutes'],
        ];
    }

    private function receiptedAdjustments(Collection $adjustments): array
    {
        if ($adjustments->isEmpty()) { return []; }
        $ids = [];
        $receipts = DB::table('audit_logs')->where('action', 'HARMOS_ADD_PAID_DAY_HISTORY')
            ->where('actor_type', 'SYSTEM')->where('target_type', 'PAID_LEAVE_ADJUSTMENT')->pluck('detail_json');
        foreach ($receipts as $json) {
            $receipt = json_decode((string) $json, true);
            if (!is_array($receipt['adjustmentIds'] ?? null)) { continue; }
            foreach ($receipt['adjustmentIds'] as $id) {
                if (is_int($id) && $adjustments->has($id)) { $ids[$id] = true; }
            }
        }
        return $ids;
    }
}
