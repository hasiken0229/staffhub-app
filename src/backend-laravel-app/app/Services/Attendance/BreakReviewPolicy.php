<?php

namespace App\Services\Attendance;

/** Read-only review guidance. This never allocates a break or changes payable time. */
final class BreakReviewPolicy
{
    /** A confirmation only applies to the raw punches and adopted values it was recorded for. */
    public static function confirmation(object $row): ?array
    {
        if (empty($row->break_overtime_review)) { return null; }
        $facts = json_decode($row->break_overtime_review, true);
        if (!is_array($facts)) { return null; }
        foreach (['rawClockInAt'=>'raw_clock_in_at', 'rawClockOutAt'=>'raw_clock_out_at',
            'clockInAt'=>'clock_in_at', 'clockOutAt'=>'clock_out_at'] as $key => $field) {
            if (!isset($facts[$key], $row->$field) || $facts[$key] !== $row->$field) { return null; }
        }
        if (!isset($facts['breakMinutes'], $facts['workMinutes'], $facts['overtimeMinutes'], $row->break_minutes, $row->work_minutes)
            || $facts['breakMinutes'] !== (int) $row->break_minutes || $facts['workMinutes'] !== (int) $row->work_minutes
            || $facts['overtimeMinutes'] !== max(0, $facts['workMinutes'] - 480)) { return null; }
        return ['breakMinutes'=>$facts['breakMinutes'], 'workMinutes'=>$facts['workMinutes'], 'overtimeMinutes'=>$facts['overtimeMinutes'],
            'confirmationSource'=>$facts['confirmationSource'] ?? 'ACTUAL']
            + array_intersect_key($facts, array_flip(['ruleKind', 'startBasis', 'scheduledEnd', 'additionalBreakStart',
                'additionalBreakStartSource', 'additionalBreakMinutes']));
    }

    public static function review(object $row): array
    {
        $minutes = (int) ($row->break_minutes ?? 0);
        // Use the adopted minutes, including imported values; do not recalculate history.
        $work = isset($row->work_minutes) ? (int) $row->work_minutes : null;
        $required = $work === null ? null : ($work > 480 ? 60 : ($work > 360 ? 45 : 0));
        $shortage = $required === null ? null : max(0, $required - max(0, $minutes));

        $receipt = self::confirmation($row);
        $adminRule = ($receipt['confirmationSource'] ?? null) === 'ADMIN_RULE';
        $confirmed = $receipt !== null && !$adminRule;
        if ($adminRule) {
            $source = 'ADMIN_RULE';
            $label = sprintf('管理者指定の休憩%d分', $minutes);
            $note = match ($receipt['ruleKind'] ?? null) {
                'SCHEDULE_END' => sprintf('予定終業%sからの追加休憩%d分を含め、休憩%d分を計上しています。', $receipt['scheduledEnd'], $receipt['additionalBreakMinutes'], $minutes),
                'REGULAR_BREAK_WINDOW' => sprintf('予定始業から8時間45分後の%sを起点に追加休憩%d分を計上し、休憩は合計%d分です。', $receipt['additionalBreakStart'], $receipt['additionalBreakMinutes'], $minutes),
                'RAW_NINE_HOURS' => array_key_exists('additionalBreakStartSource', $receipt) && ($receipt['startBasis'] ?? null) === 'ACTUAL'
                    ? '原打刻間が9時間1分以上のため、確認した始業から休憩60分を控除しています。'
                    : '原打刻間が9時間1分以上のため、原打刻から休憩60分を控除しています。',
                'RAW_FIXED_60' => '当日の指定により、出退勤の原打刻から休憩60分を控除しています。',
                default => sprintf('予定・原打刻の計算ルールに従い、休憩%d分を控除して計上しています。', $minutes),
            };
        } elseif ($confirmed) {
            $source = 'MANUAL_OR_RETAINED';
            $label = '確認済みの実休憩';
            $note = '勤務実績を確認して記録した休憩時間です。';
        } elseif (!empty($row->is_manually_edited)) {
            $source = 'MANUAL_OR_RETAINED';
            $label = '手動補正・保存値';
            $note = '手動補正後の保存値です。休憩そのものを修正したか、実際に取得したかは、この表示だけでは確認できません。';
        } elseif (!empty($row->source_system)) {
            $source = 'IMPORTED';
            $label = '移行元の保存値';
            $note = '移行元の休憩分を保持しています。実際の取得状況は移行元の原本等で確認してください。';
        } elseif (!empty($row->clock_in_at) && !empty($row->clock_out_at)) {
            $source = 'AUTOMATIC';
            $label = '自動計算';
            $note = '勤務ルールによる計算値です。自動設定された休憩区間は、実際に取得した時刻の記録ではありません。';
        } else {
            $source = 'UNDETERMINED';
            $label = '計算・保存値';
            $note = '出退勤または実働が未確定です。休憩の取得状況は別途確認してください。';
        }

        return [
            'source' => $source,
            'sourceLabel' => $label,
            'recordedMinutes' => $minutes,
            'actualBreakStatus' => $confirmed ? 'CONFIRMED' : 'UNCONFIRMED',
            'requiredMinutes' => $required,
            'shortageMinutes' => $shortage,
            'needsConfirmation' => $shortage !== null && $shortage > 0,
            'note' => $note,
            'message' => $confirmed && $shortage > 0
                ? sprintf('確認済みの実休憩は%d分です。休憩の基準を%d分下回っています。', $minutes, $shortage)
                : ($shortage !== null && $shortage > 0
                ? sprintf('採用実働が%d時間を超え、計算・保存された休憩が%d分未満です。一般的な休憩基準による確認候補です。適用区分・実際の休憩取得・勤務記録を確認してください。休憩不足を確定する表示ではありません。', $work > 480 ? 8 : 6, $required)
                : null),
        ];
    }
}
