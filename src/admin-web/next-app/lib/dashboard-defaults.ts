import type { AttendanceMonthlyCloseSummary, DashboardData, EmployeePortalData } from "@/types";

export const emptySystemMasters: DashboardData["systemMasters"] = {
  departments: [],
  locations: [],
  employmentTypes: [],
  workTypes: [],
  requestTypes: [],
  leaveTypes: [],
  paidLeaveSettings: [],
  attendanceAlerts: [],
  attendanceErrorRules: [],
  dailyFieldSettings: [],
};

export const emptyAttendanceMonthlyClose: AttendanceMonthlyCloseSummary = {
  targetYearMonth: "2026-03",
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
};

export const emptyData: DashboardData = {
  health: "loading",
  employees: [],
  cards: [],
  attendance: [],
  todayAttendance: [],
  attendanceDaily: [],
  todayDaily: [],
  dailyGrid: [],
  attendanceApprovals: [],
  attendanceErrors: [],
  attendanceMonthCloseStatus: [],
  attendanceMonthClosePrecheck: {
    targetYearMonth: "2026-03",
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
  },
  attendanceDailyEditRequests: [],
  leaveRequests: [],
  workProcedures: [],
  attendanceMonthlyClose: emptyAttendanceMonthlyClose,
  payroll: [],
  payrollDefinitions: [],
  payrollImportBatches: [],
  importHistory: [],
  notices: [],
  reportsHub: {
    todayAttendanceCount: 0,
    pendingAttendanceApprovalCount: 0,
    pendingLeaveCount: 0,
    publishedPayrollCount: 0,
  },
  reportTodayAttendance: [],
  reportAttendanceApprovals: [],
  paidLeaveReport: [],
  systemMasters: emptySystemMasters,
  auditLogs: [],
};

export const emptyEmployeePortal: EmployeePortalData = {
  home: {
    employee: null,
    pendingLeaveCount: 0,
    paidLeaveBalance: 0,
    unreadNotificationCount: 0,
    latestPayroll: null,
  },
  leaveRequests: [],
  attendanceDailyEditRequests: [],
  payroll: [],
  notifications: [],
  leaveLedger: [],
};

export const sectionLabels = {
  dashboard: "ダッシュボード",
  employees: "職員管理",
  cards: "カード管理",
  attendance: "日次勤怠",
  leave: "届出・有給管理",
  notices: "お知らせ",
  payroll: "給与明細",
  reports: "レポート",
  system: "システム管理",
  audit: "監査ログ",
} as const;

export type AdminSectionKey = keyof typeof sectionLabels;

export const sectionSubNavItems: Partial<Record<AdminSectionKey, Array<{ label: string; targetId: string }>>> = {
  dashboard: [
    { label: "本日の勤怠状況", targetId: "dashboard-today-attendance" },
    { label: "承認待ちの届出", targetId: "dashboard-pending-requests" },
  ],
  employees: [
    { label: "職員一覧", targetId: "employees-list" },
    { label: "CSV取込", targetId: "employees-import" },
  ],
  cards: [
    { label: "カード一覧", targetId: "cards-list" },
    { label: "カード登録", targetId: "cards-register" },
  ],
  notices: [
    { label: "お知らせ一覧", targetId: "notices-list" },
    { label: "お知らせ登録", targetId: "notices-register" },
  ],
  attendance: [
    { label: "検索条件", targetId: "attendance-filters" },
    { label: "月締め", targetId: "attendance-close" },
    { label: "出力", targetId: "attendance-export" },
    { label: "日次一覧", targetId: "attendance-daily-list" },
    { label: "承認待ち", targetId: "attendance-approvals" },
    { label: "勤怠エラー", targetId: "attendance-errors" },
    { label: "月締状況", targetId: "attendance-month-close-status" },
    { label: "修正申請", targetId: "attendance-edit-requests" },
    { label: "打刻履歴", targetId: "attendance-events" },
  ],
  leave: [
    { label: "届出検索", targetId: "leave-filters" },
    { label: "届出承認", targetId: "leave-requests" },
    { label: "有給残数", targetId: "leave-balances" },
    { label: "付与・調整", targetId: "leave-grant-adjust" },
  ],
  payroll: [
    { label: "種別切替", targetId: "payroll-type" },
    { label: "取込履歴", targetId: "payroll-history" },
    { label: "取込バッチ", targetId: "payroll-batches" },
    { label: "取込詳細", targetId: "payroll-batch-detail" },
    { label: "公開済み", targetId: "payroll-statements" },
    { label: "データ定義", targetId: "payroll-definitions" },
    { label: "CSV登録", targetId: "payroll-register" },
    { label: "PDF運用", targetId: "payroll-operation" },
  ],
  reports: [
    { label: "レポート出力", targetId: "reports-export" },
    { label: "今日の出退勤", targetId: "reports-today" },
    { label: "承認履歴", targetId: "reports-approvals" },
    { label: "有給管理", targetId: "reports-paid-leave" },
    { label: "出力履歴", targetId: "reports-history" },
  ],
  system: [
    { label: "部門", targetId: "system-departments" },
    { label: "拠点", targetId: "system-locations" },
    { label: "雇用形態", targetId: "system-employment" },
    { label: "勤務区分", targetId: "system-work-types" },
    { label: "申請区分", targetId: "system-request-types" },
    { label: "休暇区分", targetId: "system-leave-types" },
    { label: "有給付与", targetId: "system-paid-leave" },
    { label: "勤怠アラート", targetId: "system-attendance-alerts" },
    { label: "日次項目", targetId: "system-daily-fields" },
  ],
  audit: [
    { label: "検索条件", targetId: "audit-filters" },
    { label: "監査ログ", targetId: "audit-logs" },
  ],
};
