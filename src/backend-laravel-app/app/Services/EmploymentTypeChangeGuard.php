<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Until effective-date/payroll transition rules are confirmed, preserve historical calculations. */
final class EmploymentTypeChangeGuard
{
    public static function assertCanChange(object $employee, string $newType): void
    {
        $existingFlags = EmploymentType::flags((string) $employee->employment_type);
        $newFlags = EmploymentType::flags($newType);
        foreach ($newFlags as $flag => $value) {
            if (!empty($employee->$flag) || isset($existingFlags[$flag])) { unset($newFlags[$flag]); }
        }
        if ($newFlags === [] && EmploymentType::canonical((string) $employee->employment_type) === EmploymentType::canonical($newType)) {
            return;
        }
        foreach (['attendance_daily', 'attendance_events', 'payroll_statements'] as $table) {
            $query = DB::table($table)->where('employee_id', $employee->id);
            // Imports can already have an MVCC snapshot. Use a current read in the
            // write transaction so a newly committed attendance row cannot be missed.
            if (DB::transactionLevel() > 0) { $query->lockForUpdate(); }
            if ($query->first(['id']) !== null) {
                throw new ApiException('EMPLOYMENT_TYPE_CHANGE_REQUIRES_REVIEW',
                    '勤怠・給与の履歴があるため雇用区分を変更できません。適用日と給与期間の扱いを確認し、過去の集計を保護する移行方法を決めてください。',
                    422, [['field' => 'employmentType', 'message' => '過去の給与期間・計算が変わるため、適用日を確認するまで変更を保留してください。']]);
            }
        }
    }
}
