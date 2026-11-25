<?php

namespace App\Services\Attendance;

use App\Services\ApiException;
use Carbon\CarbonImmutable;

/** Shared elapsed-time calculation; does not infer work schedules or paid time. */
final class DailyTimeCalculator
{
    public static function span(?CarbonImmutable $start, ?CarbonImmutable $end): ?int
    {
        if ($start === null || $end === null || $end->lessThan($start)) {
            return null;
        }

        // Retain the existing seconds policy explicitly, without implicit float casts.
        return (int) floor($start->diffInSeconds($end, false) / 60);
    }

    public static function actual(?CarbonImmutable $start, ?CarbonImmutable $end, int $breakMinutes): ?int
    {
        $span = self::span($start, $end);

        return $span === null ? null : max(0, $span - max(0, $breakMinutes));
    }

    public static function validateManualTimes(?CarbonImmutable $start, ?CarbonImmutable $end, array $breaks): void
    {
        if ($start !== null && $end !== null && $end->lessThan($start)) {
            throw new ApiException('VALIDATION_ERROR', '退勤は出勤以降の時刻を指定してください。日をまたぐ場合は翌日を指定してください。', 422, [
                ['field' => 'clockOutTime', 'message' => '退勤は出勤以降にしてください。日をまたぐ場合は翌日を指定してください。'],
            ]);
        }

        $previousEnd = null;
        foreach ($breaks as $break) {
            if ($break['startAt'] === null || $break['endAt'] === null) {
                throw new ApiException('VALIDATION_ERROR', '休憩開始と終了の両方を確認してください。', 422, [
                    ['field' => 'breaks', 'message' => '時刻を変更する場合は未完了の休憩開始・終了も確認してください。'],
                ]);
            }
            if ($previousEnd !== null && $break['startAt']->lessThan($previousEnd)) {
                throw new ApiException('VALIDATION_ERROR', '休憩時間が重複しています。', 422, [
                    ['field' => 'breaks', 'message' => '休憩時間が重複しています。開始・終了を確認してください。'],
                ]);
            }
            if ($start === null || $end === null || $break['startAt']->lessThan($start) || $break['endAt']->greaterThan($end)) {
                throw new ApiException('VALIDATION_ERROR', '休憩は出勤から退勤までの範囲で指定してください。', 422, [
                    ['field' => 'breaks', 'message' => '休憩は出勤から退勤までの範囲で指定してください。'],
                ]);
            }
            $previousEnd = $break['endAt'];
        }
    }
}
