<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait AttendanceDailyHistoryRecorder
{
    protected function recordDailyDiffHistories(int $dailyId, string $actionType, array $before, array $after, GenericUser $actor, int $operatorId, ?string $comment): void
    {
        $fields = [
            'workTypeId',
            'clockInAt',
            'clockOutAt',
            'breakMinutes',
            'workMinutes',
            'remark',
            'supervisorComment',
            'approvalStatus',
            'approvalComment',
            'breaks',
            'isManuallyEdited',
        ];

        $rows = [];
        foreach ($fields as $field) {
            $oldValue = $this->historyComparableValue($before[$field] ?? null);
            $newValue = $this->historyComparableValue($after[$field] ?? null);
            if ($oldValue === $newValue) {
                continue;
            }

            $rows[] = $this->buildDailyHistoryRow($dailyId, $actionType, $field, $oldValue, $newValue, $actor, $operatorId, $comment);
        }

        if ($rows !== []) {
            DB::table('attendance_daily_histories')->insert($rows);
        }
    }

    protected function recordDailyHistory(int $dailyId, string $actionType, string $fieldKey, mixed $oldValue, mixed $newValue, GenericUser $actor, int $operatorId, ?string $comment): void
    {
        DB::table('attendance_daily_histories')->insert(
            $this->buildDailyHistoryRow(
                $dailyId,
                $actionType,
                $fieldKey,
                $this->historyComparableValue($oldValue),
                $this->historyComparableValue($newValue),
                $actor,
                $operatorId,
                $comment
            )
        );
    }

    protected function buildDailyHistoryRow(int $dailyId, string $actionType, string $fieldKey, ?string $oldValue, ?string $newValue, GenericUser $actor, int $operatorId, ?string $comment): array
    {
        $employee = $operatorId > 0 ? DB::table('employees')->where('id', $operatorId)->first() : null;
        $role = strtoupper((string) ($actor->role ?? 'ADMIN'));

        return [
            'attendance_daily_id' => $dailyId,
            'action_type' => $actionType,
            'field_key' => $fieldKey,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'actor_type' => $role === 'ADMIN' ? 'ADMIN' : 'EMPLOYEE',
            'actor_id' => (int) $actor->id,
            'actor_employee_id' => $employee?->id,
            'actor_role' => $role,
            'actor_employee_code' => $employee?->employee_code ?? (isset($actor->employeeCode) ? (string) $actor->employeeCode : null),
            'actor_name' => $employee?->name ?? (isset($actor->name) ? (string) $actor->name : null),
            'comment' => $comment,
            'acted_at' => now(),
        ];
    }

    protected function historyComparableValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    protected function dailyHistoryFieldLabel(string $fieldKey): string
    {
        return [
            'workTypeId' => '勤務区分',
            'clockInAt' => '出勤',
            'clockOutAt' => '退勤',
            'breakMinutes' => '休憩',
            'workMinutes' => '勤務時間',
            'remark' => '備考',
            'supervisorComment' => '所属長コメント',
            'approvalStatus' => '申請承認',
            'approvalComment' => '承認コメント',
            'breaks' => '休憩明細',
            'isManuallyEdited' => '手動補正',
            'closeStatus' => '月締状況',
        ][$fieldKey] ?? $fieldKey;
    }
}
