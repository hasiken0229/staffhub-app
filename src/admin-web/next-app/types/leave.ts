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
  createdAt: string;
};
