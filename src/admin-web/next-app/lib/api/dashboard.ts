import type {
  AttendanceApproval,
  AttendanceDaily,
  AttendanceDailyEditRequest,
  AttendanceBreakRule,
  AttendanceDeviceStatus,
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
import { buildQuery, fetchHealthStatus, fetchJson, fetchJsonOptional, fetchJsonPage, toDateValue, toMonthValue } from "@/lib/api/core";
import { emptyData, emptyEmployeePortal } from "@/lib/dashboard-defaults";

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

export type AttendanceSectionLoadOptions = {
  targetMonth?: string;
  employeeCode?: string;
  departmentName?: string;
  approvalStatus?: string;
  eventFrom?: string;
  eventTo?: string;
  errorCode?: string;
  errorHandlingStatus?: string;
  monthCloseApprovalStatus?: string;
  monthCloseStatus?: string;
};

function unavailableMonthClosePrecheck(targetYearMonth: string): AttendanceMonthClosePrecheck {
  return {
    targetYearMonth,
    canClose: false,
    blockers: [{
      code: "CHECK_UNAVAILABLE",
      label: "確認未取得",
      count: 1,
      message: "月締前チェックを取得できないため、月締めできません。",
    }],
    payrollReady: false,
    payrollBlockers: [{
      code: "CHECK_UNAVAILABLE",
      label: "確認未取得",
      count: 1,
      message: "給与連携前チェックを取得できません。",
    }],
    payrollWarnings: [],
  };
}

export async function loadDashboardData(): Promise<Partial<DashboardData>> {
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
      isAvailable: false,
    },
  };
}

export async function loadAdminSectionData(section: AdminSectionKey, attendanceOptions?: AttendanceSectionLoadOptions): Promise<Partial<DashboardData>> {
  if (section === "dashboard" || section === "reports") return loadAdminSectionDataInner(section, attendanceOptions);
  const [sectionData, reportsHub] = await Promise.all([
    loadAdminSectionDataInner(section, attendanceOptions),
    fetchJson<ReportHubSummary>("/api/admin/reports/hub").catch(() => ({ ...emptyData.reportsHub, checkedAt: undefined })),
  ]);
  return { ...sectionData, reportsHub };
}

async function loadAdminSectionDataInner(
  section: AdminSectionKey,
  attendanceOptions?: AttendanceSectionLoadOptions,
): Promise<Partial<DashboardData>> {
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
    const targetMonth = attendanceOptions?.targetMonth || monthValue;
    const employeeCode = attendanceOptions?.employeeCode || undefined;
    const departmentName = attendanceOptions?.departmentName || undefined;
    const approvalStatus = attendanceOptions?.approvalStatus || undefined;
    const eventFrom = attendanceOptions?.eventFrom || undefined;
    const eventTo = attendanceOptions?.eventTo || undefined;
    const [
      attendance,
      attendanceDevices,
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
      systemMasters,
    ] = await Promise.all([
      fetchJson<AttendanceEvent[]>(`/api/admin/attendance/events${buildQuery({ from: eventFrom, to: eventTo, employeeCode })}`),
      fetchJson<AttendanceDeviceStatus[]>("/api/admin/attendance/devices"),
      fetchJson<AttendanceEvent[]>(`/api/admin/attendance/events?from=${todayValue}&to=${todayValue}`),
      fetchJson<AttendanceDaily[]>(`/api/admin/attendance/daily${buildQuery({ targetMonth, employeeCode, departmentName })}`),
      fetchJson<AttendanceDaily[]>(`/api/admin/attendance/daily-grid${buildQuery({ targetMonth, employeeCode, departmentName })}`),
      fetchJson<AttendanceApproval[]>(`/api/admin/attendance/approvals${buildQuery({ status: attendanceOptions?.approvalStatus === "" ? "ALL" : approvalStatus, from: eventFrom, to: eventTo, employeeCode, departmentName })}`),
      fetchJson<AttendanceErrorReportRow[]>(`/api/admin/attendance/errors${buildQuery({ fromMonth: targetMonth, toMonth: targetMonth, errorCode: attendanceOptions?.errorCode, handlingStatus: attendanceOptions?.errorHandlingStatus, employeeCode, departmentName, approvalStatus })}`),
      fetchJson<AttendanceMonthCloseStatusRow[]>(`/api/admin/attendance/month-close-status${buildQuery({ targetMonth, employeeCode, departmentName, approvalStatus: attendanceOptions?.monthCloseApprovalStatus, closeStatus: attendanceOptions?.monthCloseStatus, page:1, perPage:10000 })}`),
      fetchJsonOptional<AttendanceMonthClosePrecheck>(`/api/admin/attendance/month-close/precheck?targetMonth=${targetMonth}`, unavailableMonthClosePrecheck(targetMonth)),
      fetchJson<AttendanceDailyEditRequest[]>(`/api/admin/attendance/daily-edit-requests${buildQuery({ status: "PENDING", employeeCode, departmentName, from: eventFrom, to: eventTo })}`),
      fetchJsonOptional<AttendanceMonthlyCloseSummary>(`/api/admin/attendance/month-close?targetMonth=${targetMonth}`, {
        ...emptyData.attendanceMonthlyClose,
        targetYearMonth: targetMonth,
        isAvailable: false,
      }),
      fetchJson<EmployeeAttendanceSetting[]>("/api/admin/attendance/employee-settings"),
      fetchJson<AttendanceShiftSchedule[]>(`/api/admin/attendance/shift-schedules?targetMonth=${targetMonth}`),
      fetchJsonOptional<AttendanceBreakRule | null>("/api/admin/attendance/break-rules", null),
      fetchJson<SystemMasters>("/api/admin/system-masters"),
    ]);

    return {
      attendance,
      attendanceDevices,
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
      systemMasters,
    };
  }

  if (section === "leave") {
    const [employees, leaveRequests, workProcedures, paidLeaveReport, systemMasters] = await Promise.all([
      fetchJson<Employee[]>("/api/admin/employees"),
      fetchJson<LeaveRequest[]>("/api/admin/leave/requests"),
      fetchJson<LeaveRequest[]>("/api/admin/work-procedures?status=PENDING"),
      fetchJson<PaidLeaveReportRow[]>("/api/admin/reports/paid-leave"),
      fetchJson<SystemMasters>("/api/admin/system-masters"),
    ]);

    return { employees, leaveRequests, workProcedures, paidLeaveReport, systemMasters };
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
      fetchJson<AttendanceApproval[]>("/api/admin/reports/attendance-approvals?page=1&perPage=100"),
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
    const auditLogs = await fetchJsonPage<AuditLog>("/api/admin/audit-logs?page=1&perPage=100");
    return { auditLogs: auditLogs.items, auditLogsPagination: auditLogs.meta };
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
    fetchJson<AttendanceMonthCloseStatusRow[]>(`/api/admin/attendance/month-close-status${buildQuery({targetMonth:monthValue,page:1,perPage:10000})}`),
    fetchJsonOptional<AttendanceMonthClosePrecheck>(`/api/admin/attendance/month-close/precheck?targetMonth=${monthValue}`, unavailableMonthClosePrecheck(monthValue)),
    fetchJson<AttendanceDailyEditRequest[]>("/api/admin/attendance/daily-edit-requests?status=PENDING"),
    fetchJson<LeaveRequest[]>("/api/admin/leave/requests"),
    fetchJson<LeaveRequest[]>("/api/admin/work-procedures?status=PENDING"),
    fetchJsonOptional<AttendanceMonthlyCloseSummary>(`/api/admin/attendance/month-close?targetMonth=${monthValue}`, {
      targetYearMonth: monthValue,
      status: "OPEN",
      isAvailable: false,
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
    fetchJson<AttendanceApproval[]>("/api/admin/reports/attendance-approvals?page=1&perPage=100"),
    fetchJson<PaidLeaveReportRow[]>("/api/admin/reports/paid-leave"),
    fetchJson<SystemMasters>("/api/admin/system-masters"),
    fetchJson<EnvironmentStatus>("/api/admin/environment"),
    fetchJsonPage<AuditLog>("/api/admin/audit-logs?page=1&perPage=100"),
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
    auditLogs: auditLogs.items,
    auditLogsPagination: auditLogs.meta,
  };
}

export async function loadEmployeePortalData(targetMonth?: string, todayOnly = false): Promise<EmployeePortalData> {
  if (todayOnly) {
    const today = await loadEmployeeToday();
    return { ...emptyEmployeePortal, home: { ...emptyEmployeePortal.home, ...today } };
  }
  const monthValue = targetMonth || toMonthValue(new Date());
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
    attendanceTargetMonth: monthValue,
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

export function loadEmployeeToday() {
  return fetchJson<Pick<MobileHome, "employee" | "todayAttendance" | "checkedAt">>("/api/mobile/home?view=today");
}

export function loadEmployeeAttention() {
  return fetchJson<Pick<MobileHome, "returnedRequestCount" | "unreadNotificationCount">>("/api/mobile/home?view=attention");
}

export function loadEmployeeLeaveOptions() {
  return fetchJson<Pick<MobileHome, "pendingLeaveCount" | "paidLeaveBalance" | "hourlyPaidLeaveBalance" | "leaveTypes">>("/api/mobile/home?view=leave");
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
