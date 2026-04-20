<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

trait ReportFormatting
{
    protected function ensureTcpdfCurlConstants(): void
    {
        $fallbacks = [
            'CURLOPT_CONNECTTIMEOUT' => 78,
            'CURLOPT_MAXREDIRS' => 68,
            'CURLOPT_PROTOCOLS' => 181,
            'CURLOPT_SSL_VERIFYHOST' => 81,
            'CURLOPT_SSL_VERIFYPEER' => 64,
            'CURLOPT_TIMEOUT' => 13,
            'CURLOPT_USERAGENT' => 10018,
            'CURLOPT_FAILONERROR' => 45,
            'CURLOPT_RETURNTRANSFER' => 19913,
            'CURLPROTO_HTTP' => 1,
            'CURLPROTO_HTTPS' => 2,
            'CURLPROTO_FTP' => 4,
            'CURLPROTO_FTPS' => 8,
        ];

        foreach ($fallbacks as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    protected function buildUtf8Csv(array $rows): string
    {
        $csv = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $escaped = array_map(function (string $value): string {
                $value = str_replace('"', '""', $value);
                return '"' . $value . '"';
            }, $row);
            $csv .= implode(',', $escaped) . "\r\n";
        }

        return $csv;
    }

    protected function actualWorkMinutes(array $row): int
    {
        if (($row['workMinutes'] ?? null) !== null) {
            return max(0, (int) $row['workMinutes']);
        }

        $spanMinutes = $this->clockSpanMinutes($row);
        if ($spanMinutes === null) {
            return 0;
        }

        $breakMinutes = max(0, (int) ($row['breakMinutes'] ?? 0));

        return max(0, $spanMinutes - $breakMinutes);
    }

    protected function clockSpanMinutes(array $row): ?int
    {
        if (empty($row['clockInAt']) || empty($row['clockOutAt'])) {
            return null;
        }

        try {
            return max(0, CarbonImmutable::parse((string) $row['clockOutAt'])->diffInMinutes(CarbonImmutable::parse((string) $row['clockInAt']), true));
        } catch (\Throwable) {
            return null;
        }
    }

    protected function formatMinutesCell(?int $minutes): string
    {
        if ($minutes === null) {
            return '-';
        }

        return $this->formatMinutesAsHoursAndMinutes($minutes);
    }

    protected function formatDayUnitCell(mixed $days): string
    {
        if ($days === null) {
            return '-';
        }

        $value = (float) $days;
        if (abs($value) < 0.0001) {
            return '-';
        }

        return $this->formatDayCount($value);
    }

    protected function formatLeaveSummary(array $row): string
    {
        $parts = [];

        if (($row['paidLeaveUnit'] ?? null) !== null && (float) $row['paidLeaveUnit'] > 0) {
            $parts[] = '有給' . $this->formatDayCount((float) $row['paidLeaveUnit']);
        }

        foreach ([
            'hourPaidLeaveMinutes' => '時間有給',
            'childCareLeaveMinutes' => '子の看護等',
            'nursingCareLeaveMinutes' => '介護休暇',
        ] as $key => $label) {
            $minutes = max(0, (int) ($row[$key] ?? 0));
            if ($minutes > 0) {
                $parts[] = $label . $this->formatMinutesAsHoursAndMinutes($minutes);
            }
        }

        return $parts === [] ? '-' : implode(' / ', $parts);
    }

    protected function formatDayCount(float $days): string
    {
        $formatted = rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
        return ($formatted === '' ? '0' : $formatted) . '日';
    }

    protected function formatMinutesAsHoursAndMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remain = $minutes % 60;

        return $hours . '時間' . str_pad((string) $remain, 2, '0', STR_PAD_LEFT) . '分';
    }

    protected function formatMonthDayWithWeekday(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            $date = CarbonImmutable::parse($value);
            return $date->format('m/d') . '(' . $this->formatWeekday($date->toDateString()) . ')';
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function formatWeekday(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return ['日', '月', '火', '水', '木', '金', '土'][CarbonImmutable::parse($value)->dayOfWeek];
        } catch (\Throwable) {
            return '';
        }
    }

    protected function formatDateOnly(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->format('Y/n/j');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    protected function formatTimeOnly(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->format('H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
