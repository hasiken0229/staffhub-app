export type AttendanceEvent = {
  id: number;
  occurredAt: string;
  employeeCode?: string | null;
  employeeName?: string | null;
  eventType?: string | null;
  receiveStatus: string;
  deviceName: string;
  cardUid: string;
};

export type AttendanceDaily = {
  id: number | null;
  employeeId: number;
  employeeCode: string;
  employeeName: string;
  departmentName?: string | null;
  locationName?: string | null;
  targetDate: string;
  scheduleName?: string | null;
  workTypeId?: number | null;
  rawClockInAt?: string | null;
  rawClockOutAt?: string | null;
  clockInAt?: string | null;
  clockOutAt?: string | null;
  breakMinutes?: number;
  workMinutes?: number | null;
  isManuallyEdited?: boolean;
  absenceFlag: boolean;
  specialLeaveFlag: boolean;
  paidLeaveUnit?: number | null;
  hourPaidLeaveMinutes?: number;
  childCareLeaveMinutes?: number;
  nursingCareLeaveMinutes?: number;
  remark?: string | null;
  supervisorComment?: string | null;
  approvalStatus?: string;
  approvalComment?: string | null;
  approvedAt?: string | null;
  closeStatus?: string;
  manualEditedAt?: string | null;
  alerts?: AttendanceAlert[];
  alertSummary?: string;
  workStyleName?: string;
  graphSegments?: AttendanceGraphSegment[];
  breaks?: AttendanceDailyBreak[];
};

export type AttendanceDailyBreak = {
  id?: number;
  segmentNo?: number;
  startAt?: string | null;
  endAt?: string | null;
  startTime?: string | null;
  endTime?: string | null;
  startNextDay?: boolean;
  endNextDay?: boolean;
};

export type AttendanceDailyDetail = AttendanceDaily & {
  id: number;
  employmentType?: string | null;
  manualEditedByName?: string | null;
  manualEditedByCode?: string | null;
  hasHistories?: boolean;
};

export type AttendanceDailyHistory = {
  id: number;
  actedAt: string;
  actionType: string;
  fieldKey: string;
  fieldLabel: string;
  oldValue?: string | null;
  newValue?: string | null;
  actorRole?: string | null;
  actorEmployeeCode?: string | null;
  actorName?: string | null;
  comment?: string | null;
};

export type AttendanceDailyUpdatePayload = {
  workTypeId?: number | null;
  clockInTime?: string | null;
  clockInNextDay?: boolean;
  clockOutTime?: string | null;
  clockOutNextDay?: boolean;
  breaks?: AttendanceDailyBreak[];
  remark?: string | null;
  supervisorComment?: string | null;
  approvalStatus?: string;
  approvalComment?: string | null;
};

export type AttendanceErrorReportRow = {
  dailyId: number;
  employeeId: number;
  targetDate: string;
  errorCode: string;
  errorName: string;
  employeeCode: string;
  employeeName: string;
  departmentName?: string | null;
  locationName?: string | null;
  employmentType?: string | null;
  approvalStatus?: string | null;
  handlingStatus: string;
  comment?: string | null;
  handledAt?: string | null;
  histories?: Array<{
    oldStatus?: string | null;
    newStatus: string;
    comment?: string | null;
    handledAt: string;
    handledByCode?: string | null;
    handledByName?: string | null;
  }>;
};

export type AttendanceMonthCloseStatusRow = {
  employee: {
    id: number;
    employeeCode: string;
    name: string;
    departmentName?: string | null;
    locationName?: string | null;
    employmentType?: string | null;
  };
  targetYearMonth: string;
  unsubmittedCount: number;
  pendingCount: number;
  returnedCount: number;
  approvedCount: number;
  dailyCount: number;
  closeStatus: string;
  closedAt?: string | null;
  closedByName?: string | null;
};

export type AttendanceMonthCloseCheckItem = {
  code: string;
  label: string;
  count: number;
  message: string;
};

export type AttendanceMonthClosePrecheck = {
  targetYearMonth: string;
  canClose: boolean;
  blockers: AttendanceMonthCloseCheckItem[];
  summary?: {
    unsubmittedDailyCount: number;
    pendingApprovalCount: number;
    returnedApprovalCount: number;
    openErrorCount: number;
    inProgressErrorCount: number;
    pendingDailyEditRequestCount: number;
    dailyCount: number;
    closedDailyCount: number;
    openDailyCount: number;
    payrollBatchCount: number;
    monthCloseStatus: string;
  };
  payrollReady?: boolean;
  payrollBlockers?: AttendanceMonthCloseCheckItem[];
  payrollWarnings?: AttendanceMonthCloseCheckItem[];
};

export type AttendanceDailyEditRequest = {
  id: number;
  employee: {
    id: number;
    employeeCode: string;
    name: string;
    departmentName?: string | null;
    locationName?: string | null;
    employmentType?: string | null;
  };
  targetDate: string;
  workTypeId?: number | null;
  workTypeName?: string | null;
  clockInAt?: string | null;
  clockOutAt?: string | null;
  clockInTime?: string | null;
  clockInNextDay?: boolean;
  clockOutTime?: string | null;
  clockOutNextDay?: boolean;
  breaks: AttendanceDailyBreak[];
  remark?: string | null;
  employeeComment?: string | null;
  status: string;
  approvedByName?: string | null;
  approvedAt?: string | null;
  decisionComment?: string | null;
  cancelledAt?: string | null;
  createdAt: string;
  updatedAt: string;
};

export type AttendanceDailyEditRequestCreatePayload = {
  targetDate: string;
  workTypeId?: number | null;
  clockInTime?: string | null;
  clockInNextDay?: boolean;
  clockOutTime?: string | null;
  clockOutNextDay?: boolean;
  breaks?: AttendanceDailyBreak[];
  remark?: string | null;
  employeeComment?: string | null;
};

export type AttendanceAlert = {
  code: string;
  name: string;
  severity?: string;
  message: string;
};

export type AttendanceGraphSegment = {
  kind: string;
  startHour?: number;
  startMinute?: number;
  endHour?: number;
  endMinute?: number;
  unit?: number;
};

export type AttendanceMonthlyCloseSummary = {
  targetYearMonth: string;
  status: string;
  note?: string | null;
  closedAt?: string | null;
  closedByName?: string | null;
  reopenedAt?: string | null;
  reopenedByName?: string | null;
  dailyCount: number;
  closedDailyCount: number;
  openDailyCount: number;
  pendingApprovalCount: number;
  payrollBatchCount: number;
};

export type AttendanceApproval = AttendanceDaily & {
  overtimeMinutes?: number;
  employmentType?: string;
};
