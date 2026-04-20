import type { DashboardData } from "@/types";

export type AttendanceSectionProps = {
  data: {
    dashboard: DashboardData;
    attendanceDecisionResult: string;
    attendanceCloseResult: string;
    reportMonth: string;
    reportFrom: string;
    reportTo: string;
    activePanel: string;
  };
  filters: {
    attendanceFilterMonth: string;
    attendanceFilterEmployeeCode: string;
    attendanceFilterDepartmentName: string;
    attendanceApprovalStatus: string;
    attendanceEventFrom: string;
    attendanceEventTo: string;
    attendanceDecisionComment: string;
    attendanceErrorCode: string;
    attendanceErrorHandlingStatus: string;
    attendanceMonthCloseApprovalStatus: string;
    attendanceMonthCloseStatusFilter: string;
  };
  actions: {
    onAttendanceFilterMonthChange: (value: string) => void;
    onAttendanceFilterEmployeeCodeChange: (value: string) => void;
    onAttendanceFilterDepartmentNameChange: (value: string) => void;
    onAttendanceApprovalStatusChange: (value: string) => void;
    onAttendanceEventFromChange: (value: string) => void;
    onAttendanceEventToChange: (value: string) => void;
    onAttendanceDecisionCommentChange: (value: string) => void;
    onAttendanceErrorCodeChange: (value: string) => void;
    onAttendanceErrorHandlingStatusChange: (value: string) => void;
    onAttendanceMonthCloseApprovalStatusChange: (value: string) => void;
    onAttendanceMonthCloseStatusFilterChange: (value: string) => void;
    onApplyAttendanceFilters: () => Promise<void>;
    onResetAttendanceFilters: () => Promise<void>;
    onAttendanceMonthClose: (status: "OPEN" | "CLOSED") => Promise<void>;
    onAttendanceDecision: (id: number, decision: "approve" | "return") => Promise<void>;
    onAttendanceDailyEditRequestDecision: (id: number, decision: "approve" | "return") => Promise<void>;
    onBulkAttendanceDecision: (decision: "approve" | "return") => Promise<void>;
    onAttendanceErrorStatus: (
      row: { employeeId: number; targetDate: string; errorCode: string },
      status: "OPEN" | "IN_PROGRESS" | "RESOLVED" | "IGNORED",
    ) => Promise<void>;
    onDownloadMonthlyAttendanceCsv: (targetMonth: string) => Promise<void>;
    onDownloadDailyAttendanceCsv: (from: string, to: string) => Promise<void>;
    onDownloadDailyAttendancePdf: (targetMonth: string) => Promise<void>;
  };
  formatters: {
    formatDateOnly: (value?: string | null) => string;
    formatDateTime: (value?: string | null) => string;
    formatTimeOnly: (value?: string | null) => string;
    formatWorkMinutes: (value?: number | null) => string;
    formatApprovalStatus: (value?: string | null) => string;
    formatCloseStatus: (value?: string | null) => string;
    formatEventType: (value?: string | null) => string;
    formatReceiveStatus: (value?: string | null) => string;
  };
};
