<?php

namespace App\Services\Reports;

use App\Services\Attendance\DailyTimeCalculator;
use Carbon\CarbonImmutable;
use TCPDF_FONTS;

trait ReportFormatting
{
    /**
     * Register the bundled BIZ UD Gothic font for every report PDF.
     *
     * TCPDF accepts the generated font family name rather than the original
     * file name, so registration is kept in one place for consistent output.
     */
    protected function resolveReportPdfFontFamily(): string
    {
        static $fontFamily = null;

        if (is_string($fontFamily) && $fontFamily !== '') {
            return $fontFamily;
        }

        $configuredFontPath = $this->resolveReportPdfFontPath((string) config('staffhub.pdf_font_path', ''));
        $candidates = array_filter(array_unique([
            $configuredFontPath,
            storage_path('app/fonts/BIZUDGothic-Regular.ttf'),
            resource_path('fonts/BIZUDGothic-Regular.ttf'),
            public_path('fonts/BIZUDGothic-Regular.ttf'),
        ]));

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '' || ! is_file($candidate)) {
                continue;
            }

            try {
                $registered = TCPDF_FONTS::addTTFfont($candidate);
                if (is_string($registered) && $registered !== '') {
                    $fontFamily = $registered;

                    return $fontFamily;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $fontFamily = 'kozminproregular';
    }

    private function resolveReportPdfFontPath(string $value): string
    {
        $path = trim($value);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

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
            if (! defined($name)) {
                define($name, $value);
            }
        }
    }

    protected function buildUtf8Csv(array $rows, array $textColumnNames): string
    {
        // These UTF-8 attendance reports are opened by people in spreadsheet apps.
        // Payroll's Shift-JIS interchange export deliberately does not use this method.
        $textColumns = [];
        foreach ($rows[0] ?? [] as $column => $label) {
            if (in_array($label, $textColumnNames, true)) $textColumns[$column] = true;
        }
        $csv = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $escaped = array_map(function (string $value, int $column) use ($textColumns): string {
                if (isset($textColumns[$column]) && preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/u', $value) === 1) {
                    $value = "'".$value;
                }
                $value = str_replace('"', '""', $value);

                return '"'.$value.'"';
            }, $row, array_keys($row));
            $csv .= implode(',', $escaped)."\r\n";
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

    protected function overtimeMinutes(array $row, int $actual): int
    {
        return \App\Services\Attendance\ConfirmedAttendancePolicy::overtime($row, $actual);
    }

    protected function clockSpanMinutes(array $row): ?int
    {
        if (empty($row['clockInAt']) || empty($row['clockOutAt'])) {
            return null;
        }

        try {
            return DailyTimeCalculator::span(CarbonImmutable::parse((string) $row['clockInAt']), CarbonImmutable::parse((string) $row['clockOutAt']));
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
        $quantities = $this->leaveQuantities($row);

        if (($row['paidLeaveUnit'] ?? null) !== null && (float) $row['paidLeaveUnit'] > 0) {
            $parts[] = '有給'.$this->formatDayCount((float) $row['paidLeaveUnit']);
        }

        if (!empty($row['requestLeaveName']) && (!empty($row['specialLeaveFlag']) || !empty($row['absenceFlag']))) {
            $parts[] = $row['requestLeaveName'].$this->formatDayCount((float) $row['requestLeaveDays']);
        }

        if ($quantities['hourPaidLeaveMinutes'] > 0) {
            $parts[] = '時間有給'.$this->formatMinutesAsHoursAndMinutes($quantities['hourPaidLeaveMinutes']);
        }
        foreach (['childCareLeave' => '子の看護等', 'nursingCareLeave' => '介護休暇'] as $key => $label) {
            if ($quantities[$key.'Days'] > 0 || $quantities[$key.'Minutes'] > 0) {
                $parts[] = $label.$this->formatCareLeaveQuantity($quantities[$key.'Days'], $quantities[$key.'Minutes']);
            }
        }

        return $parts === [] ? '-' : implode(' / ', $parts);
    }

    /** Keep day and time units separate; a day is never converted to fixed minutes. */
    protected function leaveQuantities(array $row): array
    {
        return [
            'paidLeaveDays' => max(0, (float) ($row['paidLeaveUnit'] ?? 0)),
            'hourPaidLeaveMinutes' => max(0, (int) ($row['hourPaidLeaveMinutes'] ?? 0)),
            'childCareLeaveDays' => max(0, (float) ($row['childCareLeaveDays'] ?? 0)),
            'childCareLeaveMinutes' => max(0, (int) ($row['childCareLeaveMinutes'] ?? 0)),
            'nursingCareLeaveDays' => max(0, (float) ($row['nursingCareLeaveDays'] ?? 0)),
            'nursingCareLeaveMinutes' => max(0, (int) ($row['nursingCareLeaveMinutes'] ?? 0)),
        ];
    }

    protected function formatCareLeaveQuantity(float $days, int $minutes): string
    {
        $parts = $days > 0 ? [$this->formatDayCount($days)] : [];
        if ($minutes > 0 || $parts === []) {
            $parts[] = $this->formatMinutesAsHoursAndMinutes($minutes);
        }

        return implode(' / ', $parts);
    }

    protected function formatDayCount(float $days): string
    {
        $formatted = rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');

        return ($formatted === '' ? '0' : $formatted).'日';
    }

    protected function formatMinutesAsHoursAndMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remain = $minutes % 60;

        return $hours.'時間'.str_pad((string) $remain, 2, '0', STR_PAD_LEFT).'分';
    }

    protected function formatMonthDayWithWeekday(?string $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            $date = CarbonImmutable::parse($value);

            return $date->format('m/d').'('.$this->formatWeekday($date->toDateString()).')';
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
