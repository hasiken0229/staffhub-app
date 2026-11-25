<?php

namespace App\Services;

use App\Services\Reports\AttendanceReportExportService;
use App\Services\Reports\MonthlyWorksPdfExportService;
use App\Services\Reports\PayrollReportExportService;
use App\Services\Reports\ReportDashboardService;

final class ReportService
{
    public function __construct(
        private readonly ReportDashboardService $dashboardService,
        private readonly AttendanceReportExportService $attendanceExportService,
        private readonly PayrollReportExportService $payrollExportService,
        private readonly MonthlyWorksPdfExportService $monthlyWorksPdfExportService,
    ) {
    }

    public function hubSummary(): array
    {
        return $this->dashboardService->hubSummary();
    }

    public function todayAttendance(array $filters): array
    {
        return $this->dashboardService->todayAttendance($filters);
    }

    public function attendanceApprovalHistory(array $filters): array
    {
        return $this->dashboardService->attendanceApprovalHistory($filters);
    }

    public function paidLeaveManagement(array $filters): array
    {
        return $this->dashboardService->paidLeaveManagement($filters);
    }

    public function exportMonthlyAttendanceCsv(string $targetMonth, array $filters = [], ?int &$rowCount = null): string
    {
        return $this->attendanceExportService->exportMonthlyAttendanceCsv($targetMonth, $filters, $rowCount);
    }

    public function exportDailyAttendanceCsv(array $filters, ?int &$rowCount = null): string
    {
        return $this->attendanceExportService->exportDailyAttendanceCsv($filters, $rowCount);
    }

    public function exportDailyAttendancePdf(string $targetMonth, array $filters = []): string
    {
        return $this->attendanceExportService->exportDailyAttendancePdf($targetMonth, $filters);
    }

    public function exportMonthlyPayrollCsv(string $targetMonth): array
    {
        return $this->payrollExportService->exportMonthlyPayrollCsv($targetMonth);
    }

    public function exportMonthlyWorksPdf(int $employeeId, string $targetMonth): array
    {
        return $this->monthlyWorksPdfExportService->exportMonthlyWorksPdf($employeeId, $targetMonth);
    }
}
