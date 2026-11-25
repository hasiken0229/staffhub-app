export type LeaveRequest = {
  id: number;
  employeeName?: string;
  employee?: {
    id: number;
    employeeCode: string;
    name: string;
    departmentName?: string;
    locationName?: string;
    employmentType?: string;
  };
  leaveTypeCode?: string;
  leaveTypeName: string;
  startDate: string;
  endDate: string;
  dayUnit?: string;
  halfDayType?: string | null;
  quantityDays?: number;
  effectiveDates?: string[] | null;
  requestCategory?: "LEAVE" | "TIME_LEAVE" | string;
  timeLeaveType?: "PAID_HOURLY" | "CHILD_CARE_HOURLY" | "NURSING_CARE_HOURLY" | string | null;
  targetDate?: string | null;
  startTime?: string | null;
  endTime?: string | null;
  quantityMinutes?: number | null;
  status: string;
  reason?: string;
  createdAt?: string;
  approvedAt?: string | null;
  decisionComment?: string | null;
  comment?: string | null;
  actions?: Array<{
    actionType: string;
    actionByName: string;
    actedAt: string;
    comment?: string | null;
  }>;
};

export type LeaveDayUnit = "FULL" | "HALF" | "HOURLY";

export type LeaveHalfDayType = "AM" | "PM";

export type LeaveDecisionResult = {
  id: number;
  status: string;
};

export type LeaveRequestCreatePayload = {
  expectedDates?: string[];
  requestCategory?: "LEAVE" | "TIME_LEAVE";
  leaveTypeCode?: string;
  startDate?: string;
  endDate?: string;
  dayUnit?: LeaveDayUnit;
  halfDayType?: LeaveHalfDayType | null;
  timeLeaveType?: "PAID_HOURLY" | "CHILD_CARE_HOURLY" | "NURSING_CARE_HOURLY";
  targetDate?: string;
  startTime?: string;
  endTime?: string;
  quantityMinutes?: number;
  reason?: string;
};

export type LeaveRequestCreateResult = {
  effectiveDates?: string[] | null;
  id: number;
  status: string;
  quantityDays: number;
  createdAt: string;
};

export type LeaveLedgerEntry = {
  id: number;
  entryType: string;
  sourceType: string;
  sourceId?: number | null;
  occurredOn: string;
  daysDelta: number;
  balanceAfter?: number | null;
  note?: string | null;
  createdAt: string | null;
};

export type PaidLeaveGrantLot = {
  id: number; grantedOn: string; grantedDays: number; usedDays: number; adjustedDays?: number; remainingDays: number;
  expiresOn: string | null; note: string | null; sourceSystem: string | null;
  originalGrantedOn: string | null; expiryNeedsReview: boolean; expiryReviewedAt: string | null; evidenceReference: string | null;
};
export type AdminLeaveBalance = {
  employeeId: number; asOf: string; currentBalance: number; availableDays: number; futureApprovedDays: number; grants: PaidLeaveGrantLot[];
};
export type GrantReviewPayload = {
  lots: Array<{ days: number; expiresOn: string; originalGrantedOn?: string }>;
  evidenceReference: string;
};
export type GrantReviewPreview = GrantReviewPayload & {
  grantId: number; employeeId: number; effectiveOn: string; totalDays: number; beforeBalance: number; afterBalance: number; fingerprint: string;
};
export type AttendanceLeaveValues = {
  absence_flag: number; special_leave_flag: number; paid_leave_unit: number | null;
  hour_paid_leave_minutes: number; child_care_leave_minutes: number; nursing_care_leave_minutes: number;
};
export type AttendanceLeaveBaseline = {
  employeeId: number; targetDate: string; current: AttendanceLeaveValues; applied: AttendanceLeaveValues;
  baseline: AttendanceLeaveValues | null; requestIds: number[]; fingerprint: string; requiresDuplicateResolution?: boolean;
};
