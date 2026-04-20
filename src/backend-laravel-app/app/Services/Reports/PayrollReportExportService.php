<?php

namespace App\Services\Reports;

use App\Services\ApiException;
use App\Services\AttendanceService;

final class PayrollReportExportService
{
    use ReportFormatting;

    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    public function exportMonthlyPayrollCsv(string $targetMonth): array
    {
        $grid = $this->attendanceService->listMonthlyCalendar(['targetMonth' => $targetMonth]);
        $aggregates = [];

        foreach ($grid['items'] as $item) {
            $employeeKey = (string) ($item['employeeCode'] ?? '');
            if ($employeeKey === '') {
                continue;
            }

            $aggregates[$employeeKey] ??= [
                'employeeCode' => $employeeKey,
                'employeeName' => (string) ($item['employeeName'] ?? ''),
                'baseMinutes' => 0,
                'overtimeMinutes' => 0,
                'hourPaidLeaveMinutes' => 0,
                'childCareLeaveMinutes' => 0,
                'nursingCareLeaveMinutes' => 0,
            ];

            $actualWorkMinutes = $this->actualWorkMinutes($item);
            $aggregates[$employeeKey]['baseMinutes'] += $actualWorkMinutes;
            $aggregates[$employeeKey]['overtimeMinutes'] += max(0, $actualWorkMinutes - 8 * 60);
            $aggregates[$employeeKey]['hourPaidLeaveMinutes'] += max(0, (int) ($item['hourPaidLeaveMinutes'] ?? 0));
            $aggregates[$employeeKey]['childCareLeaveMinutes'] += max(0, (int) ($item['childCareLeaveMinutes'] ?? 0));
            $aggregates[$employeeKey]['nursingCareLeaveMinutes'] += max(0, (int) ($item['nursingCareLeaveMinutes'] ?? 0));
        }

        ksort($aggregates, SORT_NATURAL);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new ApiException('FILE_EXPORT_ERROR', '月次勤務CSVを生成できません。', 500);
        }

        fputcsv($handle, ['個人Ｃ', '氏名', '時給１時間', '前月普通残業１', '時間有給', '子の看護等', '介護休暇']);

        $totalBaseMinutes = 0;
        $totalOvertimeMinutes = 0;

        foreach ($aggregates as $aggregate) {
            $totalBaseMinutes += (int) $aggregate['baseMinutes'];
            $totalOvertimeMinutes += (int) $aggregate['overtimeMinutes'];

            fputcsv($handle, [
                $aggregate['employeeCode'],
                $aggregate['employeeName'],
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['baseMinutes']),
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['overtimeMinutes']),
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['hourPaidLeaveMinutes']),
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['childCareLeaveMinutes']),
                $this->formatMinutesAsHoursAndMinutes((int) $aggregate['nursingCareLeaveMinutes']),
            ]);
        }

        rewind($handle);
        $utf8Csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return [
            'content' => mb_convert_encoding($utf8Csv, 'SJIS-win', 'UTF-8'),
            'fileName' => '給与用_' . now()->format('YmdHi') . '.csv',
            'contentType' => 'text/csv; charset=Shift_JIS',
            'summary' => [
                'employeeCount' => count($aggregates),
                'totalBaseMinutes' => $totalBaseMinutes,
                'totalOvertimeMinutes' => $totalOvertimeMinutes,
                'targetMonth' => $targetMonth,
            ],
        ];
    }
}
