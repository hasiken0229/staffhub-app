<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait AttendanceErrorDetection
{
    protected function detectDailyErrors(object $row, ?array $rules = null): array
    {
        $row = $this->attendanceDayProjection($row);
        if (AttendanceDayState::classify($row) === 'FUTURE' || AttendanceDayState::isDayOff($row)) { return []; }
        $errors = [];
        $rules ??= $this->attendanceErrorRules();
        $clockInAt = !empty($row->clock_in_at) ? CarbonImmutable::parse((string) $row->clock_in_at) : null;
        $clockOutAt = !empty($row->clock_out_at) ? CarbonImmutable::parse((string) $row->clock_out_at) : null;
        $breakMinutes = (int) ($row->break_minutes ?? 0);
        $workMinutes = $row->work_minutes !== null ? (int) $row->work_minutes : $this->calculateWorkMinutes($clockInAt, $clockOutAt, $breakMinutes);
        $hasLeave = AttendanceDayState::hasLeave($row);
        $hasWork = $clockInAt !== null || $clockOutAt !== null;

        if ($clockInAt !== null && $clockOutAt !== null && $clockOutAt->lessThan($clockInAt)) {
            $errors[] = ['errorCode' => 'REVERSED_PUNCHES', 'errorName' => '採用退勤が出勤より前です'];
        }
        if (!AttendanceDayState::notDue($row) && ($clockInAt === null xor $clockOutAt === null) && ($rule = $this->enabledErrorRule($rules, 'MISSING_PUNCH', '出勤・退勤入力漏れ'))) {
            $errors[] = ['errorCode' => 'MISSING_PUNCH', 'errorName' => $rule['name']];
        }
        if (!AttendanceDayState::notDue($row) && $clockInAt === null && $clockOutAt === null && !$hasLeave && ($rule = $this->enabledErrorRule($rules, 'MISSING_BOTH_PUNCHES', '出勤・退勤未入力'))) {
            $errors[] = ['errorCode' => 'MISSING_BOTH_PUNCHES', 'errorName' => $rule['name']];
        }
        if ($hasLeave && $hasWork && ($rule = $this->enabledErrorRule($rules, 'LEAVE_WITH_WORK', '休日・休暇の出勤'))) {
            $errors[] = ['errorCode' => 'LEAVE_WITH_WORK', 'errorName' => $rule['name']];
        }
        $span = $clockInAt && $clockOutAt ? $clockInAt->diffInMinutes($clockOutAt, true) : null;
        return [...$errors, ...$this->workDurationErrors($workMinutes, $breakMinutes, $span, $rules)];
    }

    protected function workDurationErrors(?int $workMinutes, int $breakMinutes, ?float $spanMinutes, array $rules): array
    {
        $errors = [];
        if ($workMinutes !== null && ($rule = $this->enabledErrorRule($rules, 'MISSING_BREAK', '休憩入力漏れ'))) {
            $minWorkMinutes = (int) ($rule['minWorkMinutes'] ?? 360);
            $requiredBreakMinutes = max(1, (int) ($rule['requiredBreakMinutes'] ?? 1));
            if ($workMinutes > $minWorkMinutes && $breakMinutes < $requiredBreakMinutes) {
                $errors[] = ['errorCode' => 'MISSING_BREAK', 'errorName' => $rule['name']];
            }
        }
        if ($workMinutes !== null && $breakMinutes > 0 && ($rule = $this->enabledErrorRule($rules, 'SHORT_BREAK_6_TO_8', '休憩不足（6〜8時間）'))) {
            $minWorkMinutes = (int) ($rule['minWorkMinutes'] ?? 360);
            $maxWorkMinutes = (int) ($rule['maxWorkMinutes'] ?? 480);
            $requiredBreakMinutes = (int) ($rule['requiredBreakMinutes'] ?? 45);
            if ($workMinutes > $minWorkMinutes && $workMinutes <= $maxWorkMinutes && $breakMinutes < $requiredBreakMinutes) {
                $errors[] = ['errorCode' => 'SHORT_BREAK_6_TO_8', 'errorName' => $rule['name']];
            }
        }
        if ($workMinutes !== null && ($rule = $this->enabledErrorRule($rules, 'SHORT_BREAK_OVER_8', '休憩不足（8時間超）'))) {
            $minWorkMinutes = (int) ($rule['minWorkMinutes'] ?? 480);
            $requiredBreakMinutes = (int) ($rule['requiredBreakMinutes'] ?? 60);
            if ($workMinutes > $minWorkMinutes && $breakMinutes < $requiredBreakMinutes) {
                $errors[] = ['errorCode' => 'SHORT_BREAK_OVER_8', 'errorName' => $rule['name']];
            }
        }
        if ($spanMinutes !== null && ($rule = $this->enabledErrorRule($rules, 'BREAK_TOO_LONG', '休憩時間の超過'))) {
            $maxBreakMinutes = (int) ($rule['maxBreakMinutes'] ?? 240);
            if ($breakMinutes >= $spanMinutes || $breakMinutes > $maxBreakMinutes) {
                $errors[] = ['errorCode' => 'BREAK_TOO_LONG', 'errorName' => $rule['name']];
            }
        }
        if ($workMinutes !== null && ($rule = $this->enabledErrorRule($rules, 'LONG_WORK', '長時間勤務'))) {
            $minWorkMinutes = (int) ($rule['minWorkMinutes'] ?? 720);
            if ($workMinutes > $minWorkMinutes) {
                $errors[] = ['errorCode' => 'LONG_WORK', 'errorName' => $rule['name']];
            }
        }

        return $errors;
    }

    protected function autoResolveClearedErrors(int $dailyId, int $operatorId): void
    {
        $row = DB::table('attendance_daily as ad')->join('employees as e', 'e.id', '=', 'ad.employee_id')
            ->where('ad.id', $dailyId)->select(['ad.*', 'e.employee_code', 'e.employment_type'])->first();

        if ($row === null) {
            return;
        }

        $currentErrorCodes = array_map(
            static fn (array $error): string => (string) $error['errorCode'],
            $this->detectDailyErrors($row)
        );
        $currentErrorMap = array_fill_keys($currentErrorCodes, true);
        $resolutions = DB::table('attendance_error_resolutions')
            ->where('employee_id', $row->employee_id)
            ->where('target_date', $row->target_date)
            ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
            ->get(['id', 'error_code', 'status', 'comment']);

        foreach ($resolutions as $resolution) {
            if (isset($currentErrorMap[(string) $resolution->error_code])) {
                continue;
            }

            $comment = trim((string) ($resolution->comment ?? ''));
            $autoComment = '日次修正により自動解消';
            DB::table('attendance_error_resolutions')
                ->where('id', $resolution->id)
                ->update([
                    'status' => 'RESOLVED',
                    'comment' => $comment === '' ? $autoComment : $comment . "\n" . $autoComment,
                    'handled_by' => $operatorId > 0 ? $operatorId : null,
                    'handled_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->recordAttendanceErrorResolutionHistory(
                (int) $resolution->id,
                (int) $row->employee_id,
                (string) $row->target_date,
                (string) $resolution->error_code,
                (string) $resolution->status,
                'RESOLVED',
                $autoComment,
                $operatorId
            );
        }
    }

    protected function attendanceErrorRules(): array
    {
        return DB::table('attendance_error_rules')
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                strtoupper((string) $row->code) => [
                    'code' => strtoupper((string) $row->code),
                    'name' => (string) $row->name,
                    'minWorkMinutes' => $row->min_work_minutes !== null ? (int) $row->min_work_minutes : null,
                    'maxWorkMinutes' => $row->max_work_minutes !== null ? (int) $row->max_work_minutes : null,
                    'requiredBreakMinutes' => $row->required_break_minutes !== null ? (int) $row->required_break_minutes : null,
                    'maxBreakMinutes' => $row->max_break_minutes !== null ? (int) $row->max_break_minutes : null,
                    'enabled' => (bool) $row->enabled,
                ],
            ])
            ->all();
    }

    protected function enabledErrorRule(array $rules, string $code, string $fallbackName): ?array
    {
        $normalized = strtoupper($code);
        if (isset($rules[$normalized])) {
            return $rules[$normalized]['enabled'] ? $rules[$normalized] : null;
        }

        return [
            'code' => $normalized,
            'name' => $fallbackName,
            'enabled' => true,
        ];
    }

    protected function recordAttendanceErrorResolutionHistory(
        ?int $resolutionId,
        int $employeeId,
        string $targetDate,
        string $errorCode,
        ?string $oldStatus,
        string $newStatus,
        ?string $comment,
        int $operatorId
    ): void {
        DB::table('attendance_error_resolution_histories')->insert([
            'attendance_error_resolution_id' => $resolutionId,
            'employee_id' => $employeeId,
            'target_date' => $targetDate,
            'error_code' => $errorCode,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'comment' => $this->nullableTrim($comment),
            'handled_by' => $operatorId > 0 ? $operatorId : null,
            'handled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
