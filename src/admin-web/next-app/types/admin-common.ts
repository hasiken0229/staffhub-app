import type {
  AttendanceApproval,
  AttendanceDaily,
  AttendanceDailyEditRequest,
  AttendanceErrorReportRow,
  AttendanceEvent,
  AttendanceMonthClosePrecheck,
  AttendanceMonthCloseStatusRow,
  AttendanceMonthlyCloseSummary,
} from "@/types/attendance";
import type { LeaveLedgerEntry, LeaveRequest } from "@/types/leave";
import type {
  PayrollDataDefinition,
  PayrollImportBatch,
  PayrollStatement,
} from "@/types/payroll";

export type ApiEnvelope<T> = {
  data: T;
  meta?: {
    page?: number;
    perPage?: number;
    total?: number;
  };
};

export type Employee = {
  id: number;
  employeeCode: string;
  name: string;
  kana?: string | null;
  departmentName?: string | null;
  locationName?: string | null;
  employmentType: string;
  status: string;
  joinedOn?: string;
  retiredOn?: string | null;
  googleChatUserId?: string | null;
};

export type EmployeeUpdatePayload = {
  employeeCode: string;
  name: string;
  kana?: string | null;
  departmentName?: string | null;
  locationName?: string | null;
  employmentType: string;
  status: string;
  joinedOn: string;
  retiredOn?: string | null;
  googleChatUserId?: string | null;
};

export type CardAssignment = {
  id: number;
  employeeId: number;
  cardUid: string;
  employeeCode: string;
  employeeName: string;
  isActive: boolean;
  assignedAt: string;
};

export type AuthAudience = "ADMIN" | "EMPLOYEE";

export type CurrentUser = {
  id: number;
  role: AuthAudience;
  name: string;
  employeeCode?: string;
  employeeId?: number | null;
  email?: string;
  isAdmin: boolean;
  canUseEmployeePortal?: boolean;
};

export type MobileHome = {
  employee?: {
    id: number;
    employeeCode: string;
    name: string;
  } | null;
  pendingLeaveCount: number;
  paidLeaveBalance: number;
  unreadNotificationCount: number;
  leaveTypes?: LeaveTypeSetting[];
  latestPayroll?: PayrollStatement | null;
};

export type NotificationItem = {
  id: number;
  sourceType: string;
  notificationType: string;
  title: string;
  body: string;
  relatedType?: string | null;
  relatedId?: number | null;
  sentAt: string;
  isRead: boolean;
  readAt?: string | null;
};

export type EmployeePortalData = {
  home: MobileHome;
  leaveRequests: LeaveRequest[];
  attendanceDailyEditRequests?: AttendanceDailyEditRequest[];
  payroll: PayrollStatement[];
  notifications: NotificationItem[];
  leaveLedger: LeaveLedgerEntry[];
};

export type EmployeeImportResult = {
  processedCount: number;
  createdCount: number;
  updatedCount: number;
  skippedCount: number;
};

export type AuditLog = {
  id: number;
  actorType?: string;
  actorId?: number | null;
  actorLabel?: string;
  occurredAt: string;
  action: string;
  targetType: string;
  targetId?: string | null;
  detail: string;
  payloadSummary?: string;
  detailJson?: Record<string, unknown>;
  ipAddress?: string | null;
};

export type ImportHistory = {
  id: number;
  importType: string;
  sourceFileName: string;
  downloadFileName?: string | null;
  targetPeriod?: string | null;
  statementType?: string | null;
  processedCount: number;
  successCount: number;
  errorCount: number;
  summary?: Record<string, unknown>;
  createdAt: string;
  importedByName?: string | null;
  importedByEmployeeCode?: string | null;
  downloadAvailable?: boolean;
  contentType?: string | null;
  expiresAt?: string | null;
};

export type Notice = {
  id: number;
  noticeType: string;
  title: string;
  body: string;
  publishStartAt: string;
  publishEndAt?: string | null;
  targetEmployeeId?: number | null;
  relatedType?: string | null;
  relatedId?: number | null;
  createdAt: string;
  createdByName?: string | null;
};

export type PaidLeaveReportRow = {
  employeeId: number;
  employeeCode: string;
  employeeName: string;
  departmentName?: string | null;
  currentBalance: number;
  latestEntryType?: string | null;
  latestOccurredOn?: string | null;
  latestDaysDelta?: number | null;
};

export type ReportHubSummary = {
  todayAttendanceCount: number;
  pendingAttendanceApprovalCount: number;
  pendingLeaveCount: number;
  publishedPayrollCount: number;
};

export type DepartmentSetting = {
  id: number;
  name: string;
  sortOrder: number;
  isActive: boolean;
};

export type LocationSetting = {
  id: number;
  name: string;
  sortOrder: number;
  isActive: boolean;
};

export type EmploymentTypeSetting = {
  code: string;
  label: string;
  standardDayMinutes?: number | null;
  sortOrder: number;
  isActive: boolean;
};

export type WorkTypeSetting = {
  id: number;
  name: string;
  defaultBreakMinutes?: number | null;
  standardDayMinutes?: number | null;
  sortOrder: number;
  isActive: boolean;
};

export type RequestTypeSetting = {
  code: string;
  name: string;
  sortOrder: number;
  isActive: boolean;
};

export type LeaveTypeSetting = {
  code: string;
  name: string;
  requiresBalance: boolean;
  allowsHalfDay: boolean;
  sortOrder: number;
};

export type PaidLeaveSetting = {
  id: number;
  settingName: string;
  annualGrantDays: number;
  carryForwardMonths: number;
  standardDayMinutes?: number;
  note?: string | null;
  isActive: boolean;
};

export type AttendanceAlertSetting = {
  code: string;
  name: string;
  thresholdMinutes?: number | null;
  enabled: boolean;
  note?: string | null;
};

export type AttendanceErrorRuleSetting = {
  code: string;
  name: string;
  minWorkMinutes?: number | null;
  maxWorkMinutes?: number | null;
  requiredBreakMinutes?: number | null;
  maxBreakMinutes?: number | null;
  enabled: boolean;
  note?: string | null;
  sortOrder: number;
};

export type AttendanceDailyFieldSetting = {
  fieldKey: string;
  label: string;
  displayOrder: number;
  enabled: boolean;
};

export type SystemMasters = {
  departments: DepartmentSetting[];
  locations: LocationSetting[];
  employmentTypes: EmploymentTypeSetting[];
  workTypes: WorkTypeSetting[];
  requestTypes: RequestTypeSetting[];
  leaveTypes: LeaveTypeSetting[];
  paidLeaveSettings: PaidLeaveSetting[];
  attendanceAlerts: AttendanceAlertSetting[];
  attendanceErrorRules: AttendanceErrorRuleSetting[];
  dailyFieldSettings: AttendanceDailyFieldSetting[];
};

export type DashboardData = {
  health: string;
  employees: Employee[];
  cards: CardAssignment[];
  attendance: AttendanceEvent[];
  todayAttendance: AttendanceEvent[];
  attendanceDaily: AttendanceDaily[];
  todayDaily: AttendanceDaily[];
  dailyGrid: AttendanceDaily[];
  attendanceApprovals: AttendanceApproval[];
  attendanceErrors?: AttendanceErrorReportRow[];
  attendanceMonthCloseStatus?: AttendanceMonthCloseStatusRow[];
  attendanceMonthClosePrecheck?: AttendanceMonthClosePrecheck;
  attendanceDailyEditRequests?: AttendanceDailyEditRequest[];
  leaveRequests: LeaveRequest[];
  workProcedures: LeaveRequest[];
  attendanceMonthlyClose: AttendanceMonthlyCloseSummary;
  payroll: PayrollStatement[];
  payrollDefinitions: PayrollDataDefinition[];
  payrollImportBatches: PayrollImportBatch[];
  importHistory: ImportHistory[];
  notices: Notice[];
  reportsHub: ReportHubSummary;
  reportTodayAttendance: AttendanceDaily[];
  reportAttendanceApprovals: AttendanceApproval[];
  paidLeaveReport: PaidLeaveReportRow[];
  systemMasters: SystemMasters;
  auditLogs: AuditLog[];
};
