import type { ReactNode } from "react";
import type {
  AuthAudience,
  AttendanceDailyEditRequestCreatePayload,
  EmployeePortalData,
  LeaveRequestCreatePayload,
  LeaveTypeSetting,
} from "@/types";

export type LeaveRequestCategory = "LEAVE" | "TIME_LEAVE";

export type TimeLeaveType = "PAID_HOURLY" | "CHILD_CARE_HOURLY" | "NURSING_CARE_HOURLY";

export type EmployeePortalSectionProps = {
  data: {
    employeePortal: EmployeePortalData;
    currentUserName?: string;
    isPending: boolean;
    errorMessage: string;
    selectedPayrollDetailCard?: ReactNode;
    currentMode?: AuthAudience;
    canUseEmployeePortal?: boolean;
  };
  actions: {
    onRefresh: () => void;
    onLogout: () => void;
    onModeChange?: (mode: AuthAudience) => void;
    onLeaveRequestCreate: (payload: LeaveRequestCreatePayload) => Promise<void>;
    onAttendanceDailyEditRequestCreate: (payload: AttendanceDailyEditRequestCreatePayload) => Promise<void>;
    onLoadEmployeePayrollDetail: (statementId: number) => Promise<void>;
    onPayrollDownload: (statementId: number, fileName?: string) => Promise<void>;
    onNotificationRead: (notificationId: number, sourceType: string) => Promise<void>;
  };
  formatters: {
    formatDateOnly: (value?: string | null) => string;
    formatDateTime: (value?: string | null) => string;
    formatMonthDay: (value?: string | null) => string;
    formatApprovalStatus: (value?: string | null) => string;
    formatLeaveLedgerEntryType: (value?: string | null) => string;
  };
};

export type EmployeePortalLeaveType = LeaveTypeSetting;
