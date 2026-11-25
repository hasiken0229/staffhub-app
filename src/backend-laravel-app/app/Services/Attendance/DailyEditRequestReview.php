<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DailyEditRequestReview
{
    public static function snapshot(?object $daily): array
    {
        if ($daily === null) {
            return ['id' => null];
        }
        $segments = DB::table('attendance_daily_breaks')->where('attendance_daily_id', $daily->id)
            ->orderBy('segment_no')->get(['break_start_at', 'break_end_at'])
            ->map(fn ($row) => [$row->break_start_at, $row->break_end_at])->all();

        return [
            'id' => (int) $daily->id,
            'clock_in_at' => $daily->clock_in_at, 'clock_out_at' => $daily->clock_out_at,
            'breaks' => ['segments' => $segments, 'minutes' => (int) $daily->break_minutes],
            'work_type_id' => $daily->work_type_id === null ? null : (int) $daily->work_type_id,
            'remark' => $daily->remark, 'supervisor_comment' => $daily->supervisor_comment,
        ];
    }

    /** Keep independently edited fields. Never choose between two different edits to the same field. */
    public static function compare(object $request, array $current): array
    {
        $keep = [];
        $changes = [];
        $conflict = null;
        $base = json_decode((string) ($request->daily_baseline ?? ''), true);
        if (! is_array($base) || ! array_key_exists('id', $base)) {
            $conflict = '申請時点の記録がない旧申請です。現在の勤怠と照合し、差戻し・再申請してください。';

            return compact('keep', 'changes', 'conflict');
        }
        if ($current['id'] !== $base['id']) {
            $conflict = '申請後に対象の日次が作成または変更されました。差戻し・再申請してください。';

            return compact('keep', 'changes', 'conflict');
        }
        if ($base['id'] === null) {
            return compact('keep', 'changes', 'conflict');
        }

        $proposedBreaks = json_decode((string) ($request->breaks_json ?? '[]'), true) ?? [];
        $proposed = [
            'clock_in_at' => $request->clock_in_at, 'clock_out_at' => $request->clock_out_at,
            'remark' => $request->remark, 'work_type_id' => $request->work_type_id === null ? $base['work_type_id'] : (int) $request->work_type_id,
            'breaks' => ['segments' => array_map(fn ($b) => [
                self::breakDate($request->target_date, $b['startTime'] ?? null, $b['startNextDay'] ?? false),
                self::breakDate($request->target_date, $b['endTime'] ?? null, $b['endNextDay'] ?? false),
            ], $proposedBreaks)],
        ];
        foreach (['clock_in_at' => '出勤', 'clock_out_at' => '退勤', 'breaks' => '休憩', 'work_type_id' => '勤務区分', 'remark' => '備考'] as $key => $label) {
            if ($base[$key] === $current[$key]) {
                continue;
            }
            $before = self::display($key, $base[$key]);
            $now = self::display($key, $current[$key]);
            $wanted = self::display($key, $proposed[$key]);
            $requestedValue = self::comparable($key, $proposed[$key]);
            if ($requestedValue !== self::comparable($key, $base[$key]) && $requestedValue !== self::comparable($key, $current[$key])) {
                $conflict = '申請後の訂正と申請内容が競合しています。差分を確認して差戻し・再申請してください。';
            } else {
                $keep[] = $key;
            }
            $changes[] = ['label' => $label, 'original' => $before, 'current' => $now, 'requested' => $wanted];
        }
        if ($current['supervisor_comment'] !== $base['supervisor_comment']) {
            $keep[] = 'supervisor_comment';
        }

        return compact('keep', 'changes', 'conflict');
    }

    private static function breakDate(string $date, ?string $time, bool $nextDay): ?string
    {
        return $time === null ? null : CarbonImmutable::parse($date)->addDays((int) $nextDay)->format('Y-m-d').' '.$time;
    }

    private static function comparable(string $key, mixed $value): mixed
    {
        if ($key === 'breaks') {
            return array_map(fn ($pair) => array_map(fn ($time) => $time === null ? null : substr($time, 0, 16), $pair), $value['segments']);
        }
        if (in_array($key, ['clock_in_at', 'clock_out_at'], true)) {
            return $value === null ? null : substr($value, 0, 16);
        }

        return $value;
    }

    private static function display(string $key, mixed $value): string
    {
        if ($key === 'breaks') {
            return implode(' / ', array_map(fn ($segment) => substr((string) $segment[0], 0, 16).' ～ '.substr((string) $segment[1], 0, 16), $value['segments'])) ?: '区間なし';
        }
        if ($key === 'clock_in_at' || $key === 'clock_out_at') {
            return $value === null ? '未入力' : substr($value, 0, 16);
        }

        return $value === null ? '未入力' : (string) $value;
    }
}
