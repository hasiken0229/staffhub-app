<?php

namespace App\Services;

final class EmploymentType
{
    /** Explicit legacy classifications carry attributes in addition to the payroll category. */
    public static function flags(string $value): array
    {
        return match (trim($value)) {
            '扶養内' => ['is_dependent_within' => true],
            '管理監督者' => ['is_management_supervisor' => true],
            default => [],
        };
    }

    public static function canonical(string $value): string
    {
        return match (trim($value)) {
            '扶養内', '非常勤', 'パート', 'アルバイト' => 'PART_TIME',
            '管理監督者', '常勤', '正社員', '正職員' => 'FULL_TIME',
            '契約', '契約職員' => 'CONTRACT',
            '臨時' => 'TEMPORARY',
            default => trim($value),
        };
    }
}
