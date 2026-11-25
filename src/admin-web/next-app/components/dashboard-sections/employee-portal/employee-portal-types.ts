import type { ReactNode } from "react";
import type {
  AuthAudience,
  AttendanceDaily,
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
    selectedPayrollDetailId?: number | null;
    currentMode?: AuthAudience;
    canUseEmployeePortal?: boolean;
  };
  actions: {
    onRefresh: () => void;
    onPortalUpdate?: (update: (current: EmployeePortalData) => EmployeePortalData) => void;
    onRequestStatusReload?: () => Promise<void>;
    onAttendanceMonthChange?: (month: string) => Promise<AttendanceDaily[]>;
    onLogout: () => void;
    onModeChange?: (mode: AuthAudience) => void;
    onLeaveRequestCreate: (payload: LeaveRequestCreatePayload) => Promise<boolean | void>;
    onLeaveRequestCancel: (id: number) => Promise<void>;
    onAttendanceDailyEditRequestCancel?: (id: number) => Promise<void>;
    onAttendanceDailyEditRequestCreate: (payload: AttendanceDailyEditRequestCreatePayload) => Promise<boolean | void>;
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
