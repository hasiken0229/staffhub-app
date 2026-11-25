<?php

namespace App\Services\Attendance;

use App\Services\EmploymentType;
use Carbon\CarbonImmutable as Date;

/** Rules confirmed by the operator; earlier periods and managers keep their existing rules. */
final class ConfirmedAttendancePolicy
{
    public const VERSION = '2026-10-08-confirmed';
    public const EFFECTIVE_FROM = '2026-09-01';
    public const SHORT_TIME_SHIFT_FROM = '2026-11-01';

    public static function profile(object $employee, string $date): ?array
    {
        if ($date < self::EFFECTIVE_FROM || ($employee->is_management_supervisor ?? false) || ($employee->employment_type ?? '') === '管理監督者') {
            return null;
        }
        if ((string) ($employee->employee_code ?? '') === '970017') {
            if ($date >= self::SHORT_TIME_SHIFT_FROM) {
                return [
                    'kind' => 'REGULAR_SHORT_TIME', 'version' => '2026-11-01-195-delayed-shift',
                    'allowLaterShift' => true, 'maxWorkMinutes' => 360,
                    'defaultClockIn' => '08:30', 'defaultClockOut' => '14:30',
                ];
            }
            return ['kind' => 'REGULAR_SHORT_TIME', 'version' => '2026-10-08-195-short-time', 'includeBeforeStart' => false, 'includeAfterEnd' => false];
        }
        $category = !empty($employee->is_dependent_within) ? 'PART_TIME' : EmploymentType::canonical((string) ($employee->employment_type ?? ''));
        $kind = match ($category) {
            'FULL_TIME' => 'REGULAR',
            'PART_TIME' => 'PART_TIME',
            default => null,
        };
        if ($kind === null) { return null; }
        $exception = in_array((string) ($employee->employee_code ?? ''), ['970035', '970029', '970001'], true);
        return ['kind' => $kind, 'version' => self::VERSION, 'includeBeforeStart' => $exception, 'includeAfterEnd' => $exception || $kind === 'REGULAR'];
    }

    public static function scheduleBoundary(string $date, ?string $time): ?Date
    {
        if ($time === null || $time === '') { return null; }
        $boundary = Date::parse($date.' '.$time);
        return $boundary->format('H:i:s') <= '05:00:00' ? $boundary->addDay() : $boundary;
    }

    public static function requiresIndividualBreakReview(string $scheduleNames): bool
    {
        return preg_match('/公休|休日|所定休|法定休|日曜|夜勤|宿直/u', $scheduleNames) === 1;
    }

    public static function usesRegularPunchRule(?string $kind, string $code, ?int $standardDayMinutes = null): bool
    {
        return self::usesRawPunchFullBreakRule($kind, $code, $standardDayMinutes)
            && !self::usesThreeShiftRule($code) && !in_array($code, ['970035', '970029', '970001'], true);
    }

    public static function usesRawPunchFullBreakRule(?string $kind, string $code, ?int $standardDayMinutes = null): bool
    {
        return $kind === 'REGULAR' && !in_array($code, ['970005', '970019', '970017'], true)
            && ($standardDayMinutes === null || $standardDayMinutes >= 480);
    }

    public static function usesThreeShiftRule(string $code): bool
    {
        return in_array($code, ['970045', '970008'], true);
    }

    public static function nearestThreeShiftStart(string $date, Date $raw): string
    {
        $nearest = '07:45';
        $distance = PHP_INT_MAX;
        foreach (['07:45', '09:00', '09:30'] as $time) {
            $delta = abs(self::scheduleBoundary($date, $time)->getTimestamp() - $raw->getTimestamp());
            if ($delta <= $distance) {
                $nearest = $time;
                $distance = $delta;
            }
        }
        return $nearest;
    }

    public static function rawPunchNeedsFullBreak(Date $raw, Date $end): bool
    {
        return DailyTimeCalculator::span($raw, $end) >= 541;
    }

    public static function rawPunchFullBreak(Date $raw, Date $end, ?Date $plan, string $date, ?Date $actualStart = null): array
    {
        $start = $actualStart ?? $raw;
        $window = $plan === null ? null : self::breaks($start, $end, [
            'policyKind' => 'REGULAR', 'additionalBreakAnchor' => $plan->addMinutes(525),
        ], $date);
        return [
            'start' => $start, 'branch' => $actualStart === null ? 'RAW' : 'ACTUAL', 'ruleKind' => 'RAW_NINE_HOURS',
            'minutes' => 60, 'breaks' => ($window['minutes'] ?? null) === 60 ? $window['breaks'] : [],
            'additionalBreakStart' => null, 'additionalBreakStartSource' => null, 'additionalBreakMinutes' => null,
        ];
    }

    /** Ordinary eight-hour shifts. A confirmed actual start overrides the chosen start in either branch. */
    public static function regularPunchRule(Date $rawStart, Date $rawEnd, ?Date $plannedStart, string $date, ?Date $actualStart = null): array
    {
        if (self::rawPunchNeedsFullBreak($rawStart, $rawEnd)) {
            return self::rawPunchFullBreak($rawStart, $rawEnd, $plannedStart, $date, $actualStart);
        }
        if ($plannedStart === null) {
            throw new \InvalidArgumentException('A planned start is required for the scheduled break window.');
        }
        $start = $actualStart ?? $rawStart->max($plannedStart);
        $anchor = $plannedStart->addMinutes(525);
        $rule = ['policyKind' => 'REGULAR', 'additionalBreakAnchor' => $anchor];
        $window = self::breaks($start, $rawEnd, $rule, $date) ?? ['breaks' => [], 'minutes' => 0];
        $midday = self::breaks($start, $rawEnd, ['policyKind' => 'REGULAR'], $date);
        return [
            'start' => $start,
            'branch' => $actualStart !== null ? 'ACTUAL' : 'SCHEDULED',
            'ruleKind' => 'REGULAR_BREAK_WINDOW', 'minutes' => $window['minutes'], 'breaks' => $window['breaks'],
            'additionalBreakStart' => $anchor->format('H:i'), 'additionalBreakStartSource' => 'PLANNED_START_8H45',
            'additionalBreakMinutes' => $window['minutes'] - ($midday['minutes'] ?? 0),
        ];
    }

    public static function breaks(?Date $start, ?Date $end, array $rule, string $date): ?array
    {
        if ($start === null || $end === null || !$end->greaterThan($start)) { return null; }
        if ($rule['policyKind'] === 'REGULAR_SHORT_TIME') {
            return ['breaks' => [], 'minutes' => 0];
        }
        $segments = [];
        if ($rule['policyKind'] === 'REGULAR') {
            $segments[] = [Date::parse($date.' 12:00'), Date::parse($date.' 12:45')];
            $scheduled = $rule['additionalBreakAnchor'] ?? self::scheduleBoundary($date, $rule['scheduledClockOut'] ?? null);
            if ($scheduled !== null) {
                $segments[] = [$scheduled, $scheduled->addMinutes(15)];
            }
        } else {
            // Compare seconds before rounding: strictly greater than six/nine hours.
            $seconds = $end->getTimestamp() - $start->getTimestamp();
            $minutes = $seconds > 9 * 3600 ? 60 : ($seconds > 6 * 3600 ? 45 : 0);
            if ($minutes > 0) {
                // Automatic duration allocation, not a claim of an observed break punch.
                $breakStart = $start->addSeconds((int) floor(($seconds - $minutes * 60) / 2));
                $segments[] = [$breakStart, $breakStart->addMinutes($minutes)];
            }
        }
        usort($segments, static fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());
        $merged = [];
        foreach ($segments as [$from, $to]) {
            $from = $from->max($start); $to = $to->min($end);
            if (!$to->greaterThan($from)) { continue; }
            $last = count($merged) - 1;
            if ($last >= 0 && $from->lessThanOrEqualTo($merged[$last][1])) {
                $merged[$last][1] = $merged[$last][1]->max($to);
            } else { $merged[] = [$from, $to]; }
        }
        $breaks = array_map(static fn ($s) => ['start' => $s[0]->format('Y-m-d H:i:s'), 'end' => $s[1]->format('Y-m-d H:i:s'), 'minutes' => DailyTimeCalculator::span($s[0], $s[1])], $merged);
        return ['breaks' => $breaks, 'minutes' => array_sum(array_column($breaks, 'minutes'))];
    }

    /** Use the reconciled HRMOS result only while the underlying day still matches. */
    public static function hrmosSinglePunchResult(object $row): ?array
    {
        if (($row->source_import_type ?? '') !== 'HARMOS_SINGLE_PUNCH_RECONCILIATION'
            || ($row->source_system ?? '') !== 'HARMOS' || !empty($row->is_manually_edited)) {
            return null;
        }
        $summary = $row->harmos_leave_summary ?? null;
        if (is_string($summary)) {
            $summary = json_decode($summary, true);
        }
        $record = is_array($summary) ? ($summary['singlePunchReconciliation'] ?? null) : null;
        if (!is_array($record) || ($record['version'] ?? null) !== 1) {
            return null;
        }
        foreach (['rawClockInAt' => 'raw_clock_in_at', 'rawClockOutAt' => 'raw_clock_out_at',
            'clockInAt' => 'clock_in_at', 'clockOutAt' => 'clock_out_at'] as $key => $column) {
            if (!array_key_exists($key, $record) || !property_exists($row, $column) || $record[$key] !== $row->$column) {
                return null;
            }
        }
        foreach (['workMinutes' => 'work_minutes', 'breakMinutes' => 'break_minutes'] as $key => $column) {
            if (!isset($row->$column) || !is_int($record[$key] ?? null) || $record[$key] !== (int) $row->$column) {
                return null;
            }
        }
        $side = $record['inferredSide'] ?? null;
        $overtime = $record['overtimeMinutes'] ?? null;
        if (!in_array($side, [null, 'CLOCK_IN', 'CLOCK_OUT'], true)
            || ($side === 'CLOCK_IN' && $record['rawClockInAt'] !== null)
            || ($side === 'CLOCK_OUT' && $record['rawClockOutAt'] !== null)
            || ($overtime !== null && (!is_int($overtime) || $overtime < 0 || $overtime > $record['workMinutes']))) {
            return null;
        }
        return ['inferredSide' => $side, 'overtimeMinutes' => $overtime];
    }

    public static function overtime(array $row, int $actual): int
    {
        if (isset($row['overtimeMinutesOverride'])) { return (int) $row['overtimeMinutesOverride']; }
        $employee = (object) ['employment_type' => $row['employmentType'] ?? '', 'employee_code' => $row['employeeCode'] ?? ''];
        $profile = self::profile($employee, (string) ($row['targetDate'] ?? ''));
        if (($profile['kind'] ?? null) === 'PART_TIME') { return 0; }
        // Regular staff deemed hours / variable-period overtime remain unconfirmed.
        return max(0, $actual - 480);
    }
}
