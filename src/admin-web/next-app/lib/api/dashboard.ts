import type {
  AttendanceApproval,
  AttendanceDaily,
  AttendanceDailyEditRequest,
  AttendanceErrorReportRow,
  AttendanceEvent,
  AttendanceMonthClosePrecheck,
  AttendanceMonthCloseStatusRow,
  AttendanceMonthlyCloseSummary,
  AuditLog,
  CardAssignment,
  DashboardData,
  Employee,
  EmployeePortalData,
  ImportHistory,
  LeaveLedgerEntry,
  LeaveRequest,
  MobileHome,
  Notice,
  NotificationItem,
  PaidLeaveReportRow,
  PayrollDataDefinition,
  PayrollImportBatch,
  PayrollStatement,
  ReportHubSummary,
  SystemMasters,
} from "@/types";
import { fetchHealthStatus, fetchJson, fetchJsonOptional, toDateValue, toMonthValue } from "@/lib/api/core";

export async function loadDashboardData(): Promise<DashboardData> {
  const today = new Date();
  const todayValue = toDateValue(today);
  const monthValue = toMonthValue(today);
  const [
    health,
    employees,
    cards,
    attendance,
    todayAttendance,
    attendanceDaily,
    dailyGrid,
    attendanceApprovals,
    attendanceErrors,
    attendanceMonthCloseStatus,
    attendanceMonthClosePrecheck,
    attendanceDailyEditRequests,
    leaveRequests,
    workProcedures,
    attendanceMonthlyClose,
    payroll,
    payrollDefinitions,
    payrollImportBatches,
    importHistory,
    notices,
    reportsHub,
    reportTodayAttendance,
    reportAttendanceApprovals,
    paidLeaveReport,
    systemMasters,
    auditLogs,
  ] = await Promise.all([
    fetchHealthStatus(),
    fetchJson<Employee[]>("/api/admin/employees"),
    fetchJson<CardAssignment[]>("/api/admin/cards"),
    fetchJson<AttendanceEvent[]>("/api/admin/attendance/events"),
    fetchJson<AttendanceEvent[]>(`/api/admin/attendance/events?from=${todayValue}&to=${todayValue}`),
    fetchJson<AttendanceDaily[]>(`/api/admin/attendance/daily?targetMonth=${monthValue}`),
    fetchJson<AttendanceDaily[]>(`/api/admin/attendance/daily-grid?targetMonth=${monthValue}`),
    fetchJson<AttendanceApproval[]>(`/api/admin/attendance/approvals?status=PENDING`),
    fetchJson<AttendanceErrorReportRow[]>(`/api/admin/attendance/errors?fromMonth=${monthValue}&toMonth=${monthValue}`),
    fetchJson<AttendanceMonthCloseStatusRow[]>(`/api/admin/attendance/month-close-status?targetMonth=${monthValue}`),
    fetchJsonOptional<AttendanceMonthClosePrecheck>(`/api/admin/attendance/month-close/precheck?targetMonth=${monthValue}`, {
      targetYearMonth: monthValue,
      canClose: true,
      blockers: [],
      summary: {
        unsubmittedDailyCount: 0,
        pendingApprovalCount: 0,
        returnedApprovalCount: 0,
        openErrorCount: 0,
        inProgressErrorCount: 0,
        pendingDailyEditRequestCount: 0,
        dailyCount: 0,
        closedDailyCount: 0,
        openDailyCount: 0,
        payrollBatchCount: 0,
        monthCloseStatus: "OPEN",
      },
      payrollReady: false,
      payrollBlockers: [
        {
          code: "MONTH_NOT_CLOSED",
          label: "月締未完了",
          count: 1,
          message: "給与連携前に対象月を月締めしてください。",
        },
      ],
      payrollWarnings: [],
    }),
    fetchJson<AttendanceDailyEditRequest[]>("/api/admin/attendance/daily-edit-requests?status=PENDING"),
    fetchJson<LeaveRequest[]>("/api/admin/leave/requests"),
    fetchJson<LeaveRequest[]>("/api/admin/work-procedures?status=PENDING"),
    fetchJsonOptional<AttendanceMonthlyCloseSummary>(`/api/admin/attendance/month-close?targetMonth=${monthValue}`, {
      targetYearMonth: monthValue,
      status: "OPEN",
      note: null,
      closedAt: null,
      closedByName: null,
      reopenedAt: null,
      reopenedByName: null,
      dailyCount: 0,
      closedDailyCount: 0,
      openDailyCount: 0,
      pendingApprovalCount: 0,
      payrollBatchCount: 0,
    }),
    fetchJsonOptional<PayrollStatement[]>("/api/admin/payroll/statements", []),
    fetchJsonOptional<PayrollDataDefinition[]>("/api/admin/payroll/definitions", []),
    fetchJsonOptional<PayrollImportBatch[]>("/api/admin/payroll/import-batches", []),
    fetchJson<ImportHistory[]>("/api/admin/files/history"),
    fetchJson<Notice[]>("/api/admin/notices"),
    fetchJson<ReportHubSummary>("/api/admin/reports/hub"),
    fetchJson<AttendanceDaily[]>(`/api/admin/reports/today-attendance?targetDate=${todayValue}`),
    fetchJson<AttendanceApproval[]>("/api/admin/reports/attendance-approvals"),
    fetchJson<PaidLeaveReportRow[]>("/api/admin/reports/paid-leave"),
    fetchJson<SystemMasters>("/api/admin/system-masters"),
    fetchJson<AuditLog[]>("/api/admin/audit-logs"),
  ]);

  const todayDaily = attendanceDaily.filter((item) => item.targetDate === todayValue);

  return {
    health,
    employees,
    cards,
    attendance,
    todayAttendance,
    attendanceDaily,
    todayDaily,
    dailyGrid,
    attendanceApprovals,
    attendanceErrors,
    attendanceMonthCloseStatus,
    attendanceMonthClosePrecheck,
    attendanceDailyEditRequests,
    leaveRequests,
    workProcedures,
    attendanceMonthlyClose,
    payroll,
    payrollDefinitions,
    payrollImportBatches,
    importHistory,
    notices,
    reportsHub,
    reportTodayAttendance,
    reportAttendanceApprovals,
    paidLeaveReport,
    systemMasters,
    auditLogs,
  };
}

export async function loadEmployeePortalData(): Promise<EmployeePortalData> {
  const [home, leaveRequests, attendanceDailyEditRequests, payroll, notifications, leaveLedger] = await Promise.all([
    loadEmployeePortalHome(),
    loadEmployeeLeaveRequests(),
    fetchJson<AttendanceDailyEditRequest[]>("/api/attendance/daily-edit-requests"),
    loadEmployeePayrollStatements(),
    loadEmployeeNotifications(),
    loadEmployeeLeaveLedger(),
  ]);

  return {
    home,
    leaveRequests,
    attendanceDailyEditRequests,
    payroll,
    notifications,
    leaveLedger,
  };
}

export async function loadEmployeePortalHome() {
  return fetchJson<MobileHome>("/api/mobile/home");
}

export async function loadEmployeeLeaveRequests() {
  return fetchJson<LeaveRequest[]>("/api/leave/requests");
}

export async function loadEmployeePayrollStatements() {
  return fetchJson<PayrollStatement[]>("/api/payroll/statements");
}

export async function loadEmployeeNotifications() {
  return fetchJson<NotificationItem[]>("/api/notifications");
}

export async function loadEmployeeLeaveLedger() {
  const leaveLedger = await fetchJson<{ employeeId: number; currentBalance: number; items: LeaveLedgerEntry[] }>("/api/leave/ledger");
  return leaveLedger.items;
}
