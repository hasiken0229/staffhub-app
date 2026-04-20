<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use App\Services\ImportHistoryService;
use App\Services\ReportService;
use Illuminate\Http\Request;

final class ReportAdminController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly ImportHistoryService $importHistoryService,
    ) {
    }

    public function hub()
    {
        return ApiResponse::ok($this->reportService->hubSummary());
    }

    public function todayAttendance(Request $request)
    {
        $result = $this->reportService->todayAttendance($request->only(['targetDate']));
        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function attendanceApprovals(Request $request)
    {
        $result = $this->reportService->attendanceApprovalHistory($request->only([
            'from',
            'to',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function paidLeave(Request $request)
    {
        $result = $this->reportService->paidLeaveManagement($request->only(['employeeCode']));
        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function monthlyCsv(Request $request)
    {
        $request->validate([
            'targetMonth' => ['required', 'string', 'size:7'],
        ]);

        $csv = $this->reportService->exportMonthlyAttendanceCsv((string) $request->query('targetMonth'));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="attendance_monthly_' . $request->query('targetMonth') . '.csv"',
        ]);
    }

    public function dailyCsv(Request $request)
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $csv = $this->reportService->exportDailyAttendanceCsv($request->only([
            'from',
            'to',
            'employeeCode',
        ]));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="attendance_daily_' . $request->query('from') . '_' . $request->query('to') . '.csv"',
        ]);
    }

    public function dailyPdf(Request $request)
    {
        $request->validate([
            'targetMonth' => ['required', 'string', 'size:7'],
        ]);

        $pdfBinary = $this->reportService->exportDailyAttendancePdf((string) $request->query('targetMonth'));

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="attendance_daily_' . $request->query('targetMonth') . '.pdf"',
        ]);
    }

    public function monthlyPayrollCsv(Request $request)
    {
        $request->validate([
            'targetMonth' => ['required', 'string', 'size:7'],
        ]);

        $export = $this->reportService->exportMonthlyPayrollCsv((string) $request->query('targetMonth'));
        $summary = $export['summary'];

        $this->importHistoryService->storeAndRecord(
            'MONTHLY_PAYROLL_CSV',
            $export['fileName'],
            (string) $request->query('targetMonth'),
            'PAYROLL',
            (int) ($summary['employeeCount'] ?? 0),
            (int) ($summary['employeeCount'] ?? 0),
            0,
            $summary,
            $export['content'],
            $export['fileName'],
            $export['contentType'],
            $request->user(),
        );

        return response($export['content'], 200, [
            'Content-Type' => $export['contentType'],
            'Content-Disposition' => 'attachment; filename="' . $export['fileName'] . '"',
        ]);
    }

    public function monthlyWorksPdf(Request $request)
    {
        $payload = $request->validate([
            'employeeId' => ['required', 'integer', 'min:1'],
            'targetMonth' => ['required', 'string', 'size:7'],
        ]);

        $export = $this->reportService->exportMonthlyWorksPdf((int) $payload['employeeId'], (string) $payload['targetMonth']);
        $summary = $export['summary'];

        $this->importHistoryService->storeAndRecord(
            'MONTHLY_WORKS_PDF',
            $export['fileName'],
            (string) $payload['targetMonth'],
            null,
            1,
            1,
            0,
            $summary,
            $export['content'],
            $export['fileName'],
            $export['contentType'],
            $request->user(),
        );

        return response($export['content'], 200, [
            'Content-Type' => $export['contentType'],
            'Content-Disposition' => 'attachment; filename="' . $export['fileName'] . '"',
        ]);
    }
}
