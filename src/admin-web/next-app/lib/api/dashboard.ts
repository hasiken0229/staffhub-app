import type {
  AttendanceApproval,
  AttendanceDaily,
  AttendanceDailyEditRequest,
  AttendanceBreakRule,
  AttendanceErrorReportRow,
  AttendanceEvent,
  AttendanceMonthClosePrecheck,
  AttendanceMonthCloseStatusRow,
  AttendanceMonthlyCloseSummary,
  AttendanceShiftSchedule,
  AuditLog,
  CardAssignment,
  DashboardData,
  Employee,
  EmployeeAttendanceSetting,
  EmployeePortalData,
  EnvironmentStatus,
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
import { emptyData } from "@/lib/dashboard-defaults";

type AdminSectionKey =
  | "dashboard"
  | "employees"
  | "cards"
  | "attendance"
  | "leave"
  | "notices"
  | "payroll"
  | "harmosMigration"
  | "reports"
  | "system"
  | "audit";

export async function loadDashboardData(): Promise<DashboardData> {
  const today = new Date();
  const todayValue = toDateValue(today);
  const monthValue = toMonthValue(today);
  const [health, employees, reportTodayAttendance, workProcedures, paidLeaveReport, reportsHub] = await Promise.all([
    fetchHealthStatus(),
    fetchJson<Employee[]>("/api/admin/employees"),
    fetchJson<AttendanceDaily[]>(`/api/admin/reports/today-attendance?targetDate=${todayValue}`),
    fetchJson<LeaveRequest[]>("/api/admin/work-procedures?status=PENDING"),
    fetchJson<PaidLeaveReportRow[]>("/api/admin/reports/paid-leave"),
    fetchJson<ReportHubSummary>("/api/admin/reports/hub"),
  ]);

  return {
    ...emptyData,
    health,
    employees,
    todayDaily: reportTodayAttendance,
    reportTodayAttendance,
    workProcedures,
    paidLeaveReport,
    reportsHub,
    attendanceMonthClosePrecheck: {
      ...emptyData.attendanceMonthClosePrecheck!,
      targetYearMonth: monthValue,
    },
    attendanceMonthlyClose: {
      ...emptyData.attendanceMonthlyClose,
      targetYearMonth: monthValue,
    },
  };
}

export async function loadAdminSectionData(section: AdminSectionKey): Promise<Partial<DashboardData>> {
  const today = new Date();
  const todayValue = toDateValue(today);
  const monthValue = toMonthValue(today);

  if (section === "dashboard") {
    const [employees, reportTodayAttendance, workProcedures, paidLeaveReport, reportsHub] = await Promise.all([
      fetchJson<Employee[]>("/api/admin/employees"),
      fetchJson<AttendanceDaily[]>(`/api/admin/reports/today-attendance?targetDate=${todayValue}`),
      fetchJson<LeaveRequest[]>("/api/admin/work-procedures?status=PENDING"),
      fetchJson<PaidLeaveReportRow[]>("/api/admin/reports/paid-leave"),
      fetchJson<ReportHubSummary>("/api/admin/reports/hub"),
    ]);

    return {
      employees,
      todayDaily: reportTodayAttendance,
      reportTodayAttendance,
      workProcedures,
      paidLeaveReport,
      reportsHub,
    };
  }

  if (section === "employees") {
    const [employees, importHistory] = await Promise.all([
      fetchJson<Employee[]>("/api/admin/employees"),
      fetchJson<ImportHistory[]>("/api/admin/files/history"),
    ]);

    return { employees, importHistory };
  }

  if (section === "cards") {
    const [employees, cards] = await Promise.all([
      fetchJson<Employee[]>("/api/admin/employees"),
      fetchJson<CardAssignment[]>("/api/admin/cards"),
    ]);

    return { employees, cards };
  }

  if (section === "attendance") {
    const [
      attendance,
      todayAttendance,
      attendanceDaily,
      dailyGrid,
      attendanceApprovals,
      attendanceErrors,
      attendanceMonthCloseStatus,
      attendanceMonthClosePrecheck,
      attendanceDailyEditRequests,
      attendanceMonthlyClose,
      employeeAttendanceSettings,
      attendanceShiftSchedules,
      attendanceBreakRule,
    ] = await Promise.all([
      fetchJson<AttendanceEvent[]>("/api/admin/attendance/events"),
      fetchJson<AttendanceEvent[]>(`/api/admin/attendance/events?from=${todayValue}&to=${todayValue}`),
      fetchJson<AttendanceDaily[]>(`/api/admin/attendance/daily?targetMonth=${monthValue}`),
      fetchJson<AttendanceDaily[]>(`/api/admin/attendance/daily-grid?targetMonth=${monthValue}`),
      fetchJson<AttendanceApproval[]>("/api/admin/attendance/approvals?status=PENDING"),
      fetchJson<AttendanceErrorReportRow[]>(`/api/admin/attendance/errors?fromMonth=${monthValue}&toMonth=${monthValue}`),
      fetchJson<AttendanceMonthCloseStatusRow[]>(`/api/admin/attendance/month-close-status?targetMonth=${monthValue}`),
      fetchJsonOptional<AttendanceMonthClosePrecheck>(`/api/admin/attendance/month-close/precheck?targetMonth=${monthValue}`, emptyData.attendanceMonthClosePrecheck!),
      fetchJson<AttendanceDailyEditRequest[]>("/api/admin/attendance/daily-edit-requests?status=PENDING"),
      fetchJsonOptional<AttendanceMonthlyCloseSummary>(`/api/admin/attendance/month-close?targetMonth=${monthValue}`, emptyData.attendanceMonthlyClose),
      fetchJson<EmployeeAttendanceSetting[]>("/api/admin/attendance/employee-settings"),
      fetchJson<AttendanceShiftSchedule[]>(`/api/admin/attendance/shift-schedules?targetMonth=${monthValue}`),
      fetchJsonOptional<AttendanceBreakRule | null>("/api/admin/attendance/break-rules", null),
    ]);

    return {
      attendance,
      todayAttendance,
      attendanceDaily,
      todayDaily: attendanceDaily.filter((item) => item.targetDate === todayValue),
      dailyGrid,
      attendanceApprovals,
      attendanceErrors,
      attendanceMonthCloseStatus,
      attendanceMonthClosePrecheck,
      attendanceDailyEditRequests,
      attendanceMonthlyClose,
      employeeAttendanceSettings,
      attendanceShiftSchedules,
      attendanceBreakRule,
    };
  }

  if (section === "leave") {
    const [employees, leaveRequests, workProcedures, paidLeaveReport] = await Promise.all([
      fetchJson<Employee[]>("/api/admin/employees"),
      fetchJson<LeaveRequest[]>("/api/admin/leave/requests"),
      fetchJson<LeaveRequest[]>("/api/admin/work-procedures?status=PENDING"),
      fetchJson<PaidLeaveReportRow[]>("/api/admin/reports/paid-leave"),
    ]);

    return { employees, leaveRequests, workProcedures, paidLeaveReport };
  }

  if (section === "notices") {
    const notices = await fetchJson<Notice[]>("/api/admin/notices");
    return { notices };
  }

  if (section === "payroll") {
    const [payroll, payrollDefinitions, payrollImportBatches, importHistory] = await Promise.all([
      fetchJsonOptional<PayrollStatement[]>("/api/admin/payroll/statements", []),
      fetchJsonOptional<PayrollDataDefinition[]>("/api/admin/payroll/definitions", []),
      fetchJsonOptional<PayrollImportBatch[]>("/api/admin/payroll/import-batches", []),
      fetchJson<ImportHistory[]>("/api/admin/files/history"),
    ]);

    return { payroll, payrollDefinitions, payrollImportBatches, importHistory };
  }

  if (section === "harmosMigration") {
    const [employees, importHistory] = await Promise.all([
      fetchJson<Employee[]>("/api/admin/employees"),
      fetchJson<ImportHistory[]>("/api/admin/files/history"),
    ]);

    return { employees, importHistory };
  }

  if (section === "reports") {
    const [reportsHub, reportTodayAttendance, reportAttendanceApprovals, paidLeaveReport, importHistory] = await Promise.all([
      fetchJson<ReportHubSummary>("/api/admin/reports/hub"),
      fetchJson<AttendanceDaily[]>(`/api/admin/reports/today-attendance?targetDate=${todayValue}`),
      fetchJson<AttendanceApproval[]>("/api/admin/reports/attendance-approvals"),
      fetchJson<PaidLeaveReportRow[]>("/api/admin/reports/paid-leave"),
      fetchJson<ImportHistory[]>("/api/admin/files/history"),
    ]);

    return { reportsHub, reportTodayAttendance, reportAttendanceApprovals, paidLeaveReport, importHistory };
  }

  if (section === "system") {
    const [systemMasters, environment] = await Promise.all([
      fetchJson<SystemMasters>("/api/admin/system-masters"),
      fetchJson<EnvironmentStatus>("/api/admin/environment"),
    ]);
    return { systemMasters, environment };
  }

  if (section === "audit") {
    const auditLogs = await fetchJson<AuditLog[]>("/api/admin/audit-logs");
    return { auditLogs };
  }

  return {};
}

export async function loadDashboardDataFull(): Promise<DashboardData> {
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
    environment,
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
    fetchJson<EnvironmentStatus>("/api/admin/environment"),
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
    environment,
    auditLogs,
  };
}

export async function loadEmployeePortalData(): Promise<EmployeePortalData> {
  const monthValue = toMonthValue(new Date());
  const [home, leaveRequests, attendanceDaily, attendanceDailyEditRequests, payroll, notifications, leaveLedger] = await Promise.all([
    loadEmployeePortalHome(),
    loadEmployeeLeaveRequests(),
    fetchJson<AttendanceDaily[]>(`/api/attendance/daily?targetMonth=${monthValue}`),
    fetchJson<AttendanceDailyEditRequest[]>("/api/attendance/daily-edit-requests"),
    loadEmployeePayrollStatements(),
    loadEmployeeNotifications(),
    loadEmployeeLeaveLedger(),
  ]);

  return {
    home,
    leaveRequests,
    attendanceDaily,
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
