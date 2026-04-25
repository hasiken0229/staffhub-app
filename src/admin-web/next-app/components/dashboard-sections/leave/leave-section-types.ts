import type { DashboardData } from "@/types";

export type LeaveSectionProps = {
  data: {
    dashboard: DashboardData;
    decisionResult: string;
    leaveAdminResult: string;
    activePanel: string;
  };
  filters: {
    workProcedureStatus: string;
    workProcedureEmployeeCode: string;
    workProcedureDepartmentName: string;
    workProcedureLeaveTypeCode: string;
    workProcedureRequestCategory: string;
    workProcedureTimeLeaveType: string;
    workProcedureFrom: string;
    workProcedureTo: string;
  };
  form: {
    grantEmployeeId: string;
    grantDays: string;
    grantDate: string;
    grantExpiresOn: string;
    grantNote: string;
    adjustType: "ADJUST_PLUS" | "ADJUST_MINUS";
    adjustDays: string;
    adjustDate: string;
    adjustNote: string;
    decisionComment: string;
  };
  actions: {
    onWorkProcedureStatusChange: (value: string) => void;
    onWorkProcedureEmployeeCodeChange: (value: string) => void;
    onWorkProcedureDepartmentNameChange: (value: string) => void;
    onWorkProcedureLeaveTypeCodeChange: (value: string) => void;
    onWorkProcedureRequestCategoryChange: (value: string) => void;
    onWorkProcedureTimeLeaveTypeChange: (value: string) => void;
    onWorkProcedureFromChange: (value: string) => void;
    onWorkProcedureToChange: (value: string) => void;
    onGrantEmployeeIdChange: (value: string) => void;
    onGrantDaysChange: (value: string) => void;
    onGrantDateChange: (value: string) => void;
    onGrantExpiresOnChange: (value: string) => void;
    onGrantNoteChange: (value: string) => void;
    onAdjustTypeChange: (value: "ADJUST_PLUS" | "ADJUST_MINUS") => void;
    onAdjustDaysChange: (value: string) => void;
    onAdjustDateChange: (value: string) => void;
    onAdjustNoteChange: (value: string) => void;
    onDecisionCommentChange: (value: string) => void;
    onApplyWorkProcedureFilters: () => Promise<void>;
    onResetWorkProcedureFilters: () => Promise<void>;
    onWorkProcedureDecision: (id: number, decision: "approve" | "return") => Promise<void>;
    onBulkWorkProcedureDecision: (decision: "approve" | "return", selectedIds?: number[]) => Promise<void>;
    onGrantPaidLeave: () => Promise<void>;
    onAdjustPaidLeave: () => Promise<void>;
  };
  formatters: {
    formatDateOnly: (value?: string | null) => string;
    formatDateTime: (value?: string | null) => string;
    formatApprovalStatus: (value?: string | null) => string;
    formatLeaveLedgerEntryType: (value?: string | null) => string;
  };
};
