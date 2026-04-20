<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait AttendanceAlertRules
{
    protected function buildAlertsForRow(object $row, array $shortIntervalAlertMap, array $settings): array
    {
        $targetDate = (string) ($row->target_date ?? '');
        $employeeId = (int) ($row->employee_id ?? 0);
        $pairKey = $this->alertPairKey($employeeId, $targetDate);
        $alerts = [];

        if (!empty($row->clock_in_at) && empty($row->clock_out_at)) {
            if ($config = $this->resolveEnabledAlert($settings, ['UNCLOCKED_OUT', 'MISSING_CLOCK_OUT'], 'UNCLOCKED_OUT', '未退勤アラート', null)) {
                $alerts[] = [
                    'code' => $config['code'],
                    'name' => $config['name'],
                    'severity' => 'warning',
                    'message' => '出勤打刻後に退勤打刻がありません。',
                ];
            }
        }

        if ((empty($row->clock_in_at) xor empty($row->clock_out_at))) {
            if ($config = $this->resolveEnabledAlert($settings, ['MISSING_PUNCH'], 'MISSING_PUNCH', '打刻漏れ', null)) {
                $alerts[] = [
                    'code' => $config['code'],
                    'name' => $config['name'],
                    'severity' => 'warning',
                    'message' => '出勤または退勤のどちらかが不足しています。',
                ];
            }
        }

        if (($shortIntervalAlertMap[$pairKey] ?? false) === true) {
            $config = $this->resolveEnabledAlert($settings, ['SHORT_INTERVAL', 'CONTINUOUS_PUNCH'], 'SHORT_INTERVAL', '短時間の連続打刻', 2);
            if ($config !== null) {
                $alerts[] = [
                    'code' => $config['code'],
                    'name' => $config['name'],
                    'severity' => 'info',
                    'message' => sprintf('%d分以内の連続打刻が検出されました。', max(1, (int) ($config['thresholdMinutes'] ?? 2))),
                ];
            }
        }

        return $alerts;
    }

    protected function resolveAttendanceAlertSettings(): array
    {
        return DB::table('attendance_alert_settings')
            ->select(['code', 'name', 'threshold_minutes', 'enabled'])
            ->get()
            ->mapWithKeys(fn (object $row) => [
                strtoupper((string) $row->code) => [
                    'code' => strtoupper((string) $row->code),
                    'name' => (string) $row->name,
                    'thresholdMinutes' => $row->threshold_minutes !== null ? (int) $row->threshold_minutes : null,
                    'enabled' => (bool) $row->enabled,
                ],
            ])
            ->all();
    }

    protected function resolveEnabledAlert(array $settings, array $candidateCodes, string $fallbackCode, string $fallbackName, ?int $fallbackThreshold): ?array
    {
        foreach ($candidateCodes as $candidateCode) {
            $normalized = strtoupper($candidateCode);
            if (isset($settings[$normalized])) {
                return $settings[$normalized]['enabled'] ? $settings[$normalized] : null;
            }
        }

        return [
            'code' => $fallbackCode,
            'name' => $fallbackName,
            'thresholdMinutes' => $fallbackThreshold,
            'enabled' => true,
        ];
    }

    protected function buildShortIntervalAlertMap(Collection $rows, array $settings): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $config = $this->resolveEnabledAlert($settings, ['SHORT_INTERVAL', 'CONTINUOUS_PUNCH'], 'SHORT_INTERVAL', '短時間の連続打刻', 2);
        if ($config === null) {
            return [];
        }

        $thresholdMinutes = max(1, (int) ($config['thresholdMinutes'] ?? 2));
        $employeeIds = $rows->pluck('employee_id')->filter()->unique()->map(fn ($id) => (int) $id)->values();
        $targetDates = $rows->pluck('target_date')->filter()->unique()->sort()->values();

        if ($employeeIds->isEmpty() || $targetDates->isEmpty()) {
            return [];
        }

        $events = DB::table('attendance_events')
            ->select(['employee_id', 'occurred_at'])
            ->whereIn('employee_id', $employeeIds->all())
            ->whereBetween('occurred_at', [
                CarbonImmutable::parse((string) $targetDates->first())->startOfDay()->format('Y-m-d H:i:s'),
                CarbonImmutable::parse((string) $targetDates->last())->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->where('receive_status', 'ACCEPTED')
            ->orderBy('employee_id')
            ->orderBy('occurred_at')
            ->get();

        $map = [];
        $grouped = [];
        foreach ($events as $event) {
            $targetDate = CarbonImmutable::parse((string) $event->occurred_at)->toDateString();
            $grouped[$this->alertPairKey((int) $event->employee_id, $targetDate)][] = CarbonImmutable::parse((string) $event->occurred_at);
        }

        foreach ($grouped as $pairKey => $times) {
            $map[$pairKey] = false;
            for ($index = 1; $index < count($times); $index++) {
                if ($times[$index]->diffInRealMinutes($times[$index - 1], true) <= $thresholdMinutes) {
                    $map[$pairKey] = true;
                    break;
                }
            }
        }

        return $map;
    }

    protected function alertPairKey(int $employeeId, string $targetDate): string
    {
        return $employeeId . ':' . $targetDate;
    }

    protected function formatAlertSummary(array $alerts): string
    {
        if ($alerts === []) {
            return '-';
        }

        return implode(' / ', array_map(static fn (array $alert) => (string) ($alert['name'] ?? $alert['code'] ?? ''), $alerts));
    }
}
