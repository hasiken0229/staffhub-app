<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemMasterService
{
    public function leaveTypes(bool $includeInactive = true): array
    {
        $query = DB::table('leave_types');

        if (!$includeInactive && Schema::hasColumn('leave_types', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'requiresBalance' => (bool) $row->requires_balance,
                'allowsHalfDay' => (bool) $row->allows_half_day,
                'sortOrder' => (int) $row->sort_order,
                'isActive' => (bool) ($row->is_active ?? true),
            ])
            ->all();
    }

    public function overview(): array
    {
        return [
            'departments' => DB::table('department_settings')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'sortOrder' => (int) $row->sort_order,
                    'isActive' => (bool) $row->is_active,
                ])
                ->all(),
            'locations' => DB::table('location_settings')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'sortOrder' => (int) $row->sort_order,
                    'isActive' => (bool) $row->is_active,
                ])
                ->all(),
            'employmentTypes' => DB::table('employment_type_settings')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
                ->map(fn ($row) => [
                    'code' => $row->code,
                    'label' => $row->label,
                    'standardDayMinutes' => isset($row->standard_day_minutes) ? (int) $row->standard_day_minutes : null,
                    'sortOrder' => (int) $row->sort_order,
                    'isActive' => (bool) $row->is_active,
                ])
                ->all(),
            'workTypes' => DB::table('work_type_settings')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'startTime' => $row->start_time ?? null,
                    'endTime' => $row->end_time ?? null,
                    'defaultBreakMinutes' => $row->default_break_minutes != null ? (int) $row->default_break_minutes : null,
                    'standardDayMinutes' => isset($row->standard_day_minutes) ? (int) $row->standard_day_minutes : null,
                    'sortOrder' => (int) $row->sort_order,
                    'isActive' => (bool) $row->is_active,
                ])
                ->all(),
            'requestTypes' => DB::table('request_type_settings')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
                ->map(fn ($row) => [
                    'code' => $row->code,
                    'name' => $row->name,
                    'sortOrder' => (int) $row->sort_order,
                    'isActive' => (bool) $row->is_active,
                ])
                ->all(),
            'leaveTypes' => $this->leaveTypes(),
            'paidLeaveSettings' => DB::table('paid_leave_settings')
                ->orderByDesc('is_active')
                ->orderBy('setting_name')
                ->get()
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'settingName' => $row->setting_name,
                    'annualGrantDays' => (float) $row->annual_grant_days,
                    'carryForwardMonths' => (int) $row->carry_forward_months,
                    'standardDayMinutes' => isset($row->standard_day_minutes) ? (int) $row->standard_day_minutes : 480,
                    'note' => $row->note,
                    'isActive' => (bool) $row->is_active,
                ])
                ->all(),
            'attendanceAlerts' => DB::table('attendance_alert_settings')
                ->orderBy('code')
                ->get()
                ->map(fn ($row) => [
                    'code' => $row->code,
                    'name' => $row->name,
                    'thresholdMinutes' => $row->threshold_minutes != null ? (int) $row->threshold_minutes : null,
                    'enabled' => (bool) $row->enabled,
                    'note' => $row->note,
                ])
                ->all(),
            'attendanceErrorRules' => DB::table('attendance_error_rules')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
                ->map(fn ($row) => [
                    'code' => $row->code,
                    'name' => $row->name,
                    'minWorkMinutes' => $row->min_work_minutes !== null ? (int) $row->min_work_minutes : null,
                    'maxWorkMinutes' => $row->max_work_minutes !== null ? (int) $row->max_work_minutes : null,
                    'requiredBreakMinutes' => $row->required_break_minutes !== null ? (int) $row->required_break_minutes : null,
                    'maxBreakMinutes' => $row->max_break_minutes !== null ? (int) $row->max_break_minutes : null,
                    'enabled' => (bool) $row->enabled,
                    'note' => $row->note,
                    'sortOrder' => (int) $row->sort_order,
                ])
                ->all(),
            'dailyFieldSettings' => DB::table('attendance_daily_field_settings')
                ->orderBy('display_order')
                ->get()
                ->map(fn ($row) => [
                    'fieldKey' => $row->field_key,
                    'label' => $row->label,
                    'displayOrder' => (int) $row->display_order,
                    'enabled' => (bool) $row->enabled,
                ])
                ->all(),
        ];
    }

    public function storeDepartment(array $payload): array
    {
        $name = $this->requiredString($payload, 'name', '部門名');
        $id = $this->nullableIntValue($payload, 'id');
        $existing = $id !== null
            ? DB::table('department_settings')->where('id', $id)->first()
            : DB::table('department_settings')->where('name', $name)->first();

        if ($existing === null) {
            DB::table('department_settings')->insert([
                'name' => $name,
                'sort_order' => $this->intValue($payload, 'sortOrder', $this->nextSortOrder('department_settings')),
                'is_active' => $this->boolValue($payload, 'isActive', true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('department_settings')->where('id', $existing->id)->update([
                'name' => $name,
                'sort_order' => $this->intValue($payload, 'sortOrder', (int) $existing->sort_order),
                'is_active' => $this->boolValue($payload, 'isActive', (bool) $existing->is_active),
                'updated_at' => now(),
            ]);
        }

        return $this->overview()['departments'];
    }

    public function storeLocation(array $payload): array
    {
        $name = $this->requiredString($payload, 'name', '拠点名');
        $id = $this->nullableIntValue($payload, 'id');
        $existing = $id !== null
            ? DB::table('location_settings')->where('id', $id)->first()
            : DB::table('location_settings')->where('name', $name)->first();

        if ($existing === null) {
            DB::table('location_settings')->insert([
                'name' => $name,
                'sort_order' => $this->intValue($payload, 'sortOrder', $this->nextSortOrder('location_settings')),
                'is_active' => $this->boolValue($payload, 'isActive', true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('location_settings')->where('id', $existing->id)->update([
                'name' => $name,
                'sort_order' => $this->intValue($payload, 'sortOrder', (int) $existing->sort_order),
                'is_active' => $this->boolValue($payload, 'isActive', (bool) $existing->is_active),
                'updated_at' => now(),
            ]);
        }

        return $this->overview()['locations'];
    }

    public function storeEmploymentType(array $payload): array
    {
        $code = $this->requiredString($payload, 'code', '雇用形態コード');
        $label = $this->requiredString($payload, 'label', '雇用形態名');

        DB::table('employment_type_settings')->updateOrInsert(
            ['code' => $code],
            [
                'label' => $label,
                'standard_day_minutes' => $this->nullableIntValue($payload, 'standardDayMinutes'),
                'sort_order' => $this->intValue($payload, 'sortOrder', $this->nextSortOrder('employment_type_settings')),
                'is_active' => $this->boolValue($payload, 'isActive', true),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $this->overview()['employmentTypes'];
    }

    public function storeWorkType(array $payload): array
    {
        $name = $this->requiredString($payload, 'name', '勤務区分名');
        $id = $this->nullableIntValue($payload, 'id');
        $existing = $id !== null
            ? DB::table('work_type_settings')->where('id', $id)->first()
            : DB::table('work_type_settings')->where('name', $name)->first();

        if ($existing === null) {
            DB::table('work_type_settings')->insert([
                'name' => $name,
                'start_time' => $this->nullableString($payload, 'startTime'),
                'end_time' => $this->nullableString($payload, 'endTime'),
                'default_break_minutes' => $this->nullableIntValue($payload, 'defaultBreakMinutes'),
                'standard_day_minutes' => $this->nullableIntValue($payload, 'standardDayMinutes'),
                'sort_order' => $this->intValue($payload, 'sortOrder', $this->nextSortOrder('work_type_settings')),
                'is_active' => $this->boolValue($payload, 'isActive', true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('work_type_settings')->where('id', $existing->id)->update([
                'name' => $name,
                'start_time' => $this->nullableString($payload, 'startTime'),
                'end_time' => $this->nullableString($payload, 'endTime'),
                'default_break_minutes' => $this->nullableIntValue($payload, 'defaultBreakMinutes'),
                'standard_day_minutes' => $this->nullableIntValue($payload, 'standardDayMinutes'),
                'sort_order' => $this->intValue($payload, 'sortOrder', (int) $existing->sort_order),
                'is_active' => $this->boolValue($payload, 'isActive', (bool) $existing->is_active),
                'updated_at' => now(),
            ]);
        }

        return $this->overview()['workTypes'];
    }

    public function storeRequestType(array $payload): array
    {
        $code = $this->requiredString($payload, 'code', '申請区分コード');
        $name = $this->requiredString($payload, 'name', '申請区分名');

        DB::table('request_type_settings')->updateOrInsert(
            ['code' => $code],
            [
                'name' => $name,
                'sort_order' => $this->intValue($payload, 'sortOrder', $this->nextSortOrder('request_type_settings')),
                'is_active' => $this->boolValue($payload, 'isActive', true),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $this->overview()['requestTypes'];
    }

    public function storeLeaveType(array $payload): array
    {
        $code = $this->requiredString($payload, 'code', '休暇区分コード');
        $name = $this->requiredString($payload, 'name', '休暇区分名');

        $values = [
            'name' => $name,
            'requires_balance' => $this->boolValue($payload, 'requiresBalance', false),
            'allows_half_day' => $this->boolValue($payload, 'allowsHalfDay', false),
            'sort_order' => $this->intValue($payload, 'sortOrder', $this->nextSortOrder('leave_types')),
        ];

        if (Schema::hasColumn('leave_types', 'is_active')) {
            $values['is_active'] = $this->boolValue($payload, 'isActive', true);
        }

        DB::table('leave_types')->updateOrInsert(
            ['code' => $code],
            $values,
        );

        return $this->overview()['leaveTypes'];
    }

    public function storePaidLeaveSetting(array $payload): array
    {
        $settingName = $this->requiredString($payload, 'settingName', '休暇設定名');
        $id = $this->nullableIntValue($payload, 'id');

        if ($id !== null && DB::table('paid_leave_settings')->where('id', $id)->exists()) {
            DB::table('paid_leave_settings')->where('id', $id)->update([
                'setting_name' => $settingName,
                'annual_grant_days' => $this->floatValue($payload, 'annualGrantDays', 10),
                'carry_forward_months' => $this->intValue($payload, 'carryForwardMonths', 24),
                'standard_day_minutes' => $this->intValue($payload, 'standardDayMinutes', 480),
                'note' => $this->nullableString($payload, 'note'),
                'is_active' => $this->boolValue($payload, 'isActive', true),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('paid_leave_settings')->updateOrInsert(
                ['setting_name' => $settingName],
                [
                    'annual_grant_days' => $this->floatValue($payload, 'annualGrantDays', 10),
                    'carry_forward_months' => $this->intValue($payload, 'carryForwardMonths', 24),
                    'standard_day_minutes' => $this->intValue($payload, 'standardDayMinutes', 480),
                    'note' => $this->nullableString($payload, 'note'),
                    'is_active' => $this->boolValue($payload, 'isActive', true),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return $this->overview()['paidLeaveSettings'];
    }

    public function storeAttendanceAlert(array $payload): array
    {
        $code = $this->requiredString($payload, 'code', '勤怠アラートコード');
        $name = $this->requiredString($payload, 'name', '勤怠アラート名');

        DB::table('attendance_alert_settings')->updateOrInsert(
            ['code' => $code],
            [
                'name' => $name,
                'threshold_minutes' => $this->nullableIntValue($payload, 'thresholdMinutes'),
                'enabled' => $this->boolValue($payload, 'enabled', true),
                'note' => $this->nullableString($payload, 'note'),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $this->overview()['attendanceAlerts'];
    }

    public function storeAttendanceErrorRule(array $payload): array
    {
        $code = $this->requiredString($payload, 'code', '勤怠エラーコード');
        $name = $this->requiredString($payload, 'name', '勤怠エラー名');

        DB::table('attendance_error_rules')->updateOrInsert(
            ['code' => strtoupper($code)],
            [
                'name' => $name,
                'min_work_minutes' => $this->nullableIntValue($payload, 'minWorkMinutes'),
                'max_work_minutes' => $this->nullableIntValue($payload, 'maxWorkMinutes'),
                'required_break_minutes' => $this->nullableIntValue($payload, 'requiredBreakMinutes'),
                'max_break_minutes' => $this->nullableIntValue($payload, 'maxBreakMinutes'),
                'enabled' => $this->boolValue($payload, 'enabled', true),
                'note' => $this->nullableString($payload, 'note'),
                'sort_order' => $this->intValue($payload, 'sortOrder', $this->nextSortOrder('attendance_error_rules', 'sort_order')),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $this->overview()['attendanceErrorRules'];
    }

    public function storeDailyField(array $payload): array
    {
        $fieldKey = $this->requiredString($payload, 'fieldKey', '日次項目キー');
        $label = $this->requiredString($payload, 'label', '日次項目名');

        DB::table('attendance_daily_field_settings')->updateOrInsert(
            ['field_key' => $fieldKey],
            [
                'label' => $label,
                'display_order' => $this->intValue($payload, 'displayOrder', $this->nextSortOrder('attendance_daily_field_settings', 'display_order')),
                'enabled' => $this->boolValue($payload, 'enabled', true),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $this->overview()['dailyFieldSettings'];
    }

    private function requiredString(array $payload, string $key, string $label): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new ApiException('VALIDATION_ERROR', $label . 'は必須です。', 422, [
                ['field' => $key, 'message' => $label . 'は必須です。'],
            ]);
        }

        return $value;
    }

    private function nullableString(array $payload, string $key): ?string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        return $value !== '' ? $value : null;
    }

    private function boolValue(array $payload, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }

        $value = $payload[$key];
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    private function intValue(array $payload, string $key, int $default): int
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
            return $default;
        }

        return (int) $payload[$key];
    }

    private function nullableIntValue(array $payload, string $key): ?int
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
            return null;
        }

        return (int) $payload[$key];
    }

    private function floatValue(array $payload, string $key, float $default): float
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
            return $default;
        }

        return (float) $payload[$key];
    }

    private function nextSortOrder(string $table, string $column = 'sort_order'): int
    {
        $max = DB::table($table)->max($column);
        return $max != null ? ((int) $max + 1) : 1;
    }
}
