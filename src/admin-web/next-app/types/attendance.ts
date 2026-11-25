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

export type AttendanceDeviceStatus = {
  id: number;
  deviceCode: string;
  name: string;
  locationName?: string | null;
  appVersion?: string | null;
  lastSeenAt?: string | null;
  clientTimeAt?: string | null;
  diagnosticsSchemaReady?: boolean;
  clockDriftSeconds?: number | null;
  pendingOfflineCount: number | null;
  readerStatus?: string | null;
  isActive: boolean;
};

export type AttendanceDaily = {
  dayState?: "FUTURE" | "HOLIDAY" | "LEAVE" | "WORKING" | "NOT_DUE" | "COMPLETE" | "MISSING";
  periodStart?: string;
  periodEnd?: string;
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
  breakReview?: AttendanceBreakReview;
  workMinutes?: number | null;
  reportWorkMinutes?: number | null;
  overtimeMinutes?: number | null;
  overtimeMinutesOverride?: number | null;
  isManuallyEdited?: boolean;
  scheduleInferredSide?: "CLOCK_IN" | "CLOCK_OUT" | null;
  sourceSystem?: string | null;
  timeSource?: "MANUAL" | "SCHEDULE" | "IMPORTED" | "RULE" | "PUNCH";
  absenceFlag: boolean;
  specialLeaveFlag: boolean;
  paidLeaveUnit?: number | null;
  hourPaidLeaveMinutes?: number;
  childCareLeaveMinutes?: number;
  nursingCareLeaveMinutes?: number;
  childCareLeaveDays?: number;
  nursingCareLeaveDays?: number;
  remark?: string | null;
  supervisorComment?: string | null;
  approvalStatus?: string;
  approvalReviewReason?: string | null;
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

export type AttendanceBreakReview = {
  source: "AUTOMATIC" | "MANUAL_OR_RETAINED" | "IMPORTED" | "UNDETERMINED" | "ADMIN_RULE";
  sourceLabel: string;
  recordedMinutes: number;
  actualBreakStatus: "UNCONFIRMED" | "CONFIRMED";
  requiredMinutes: number | null;
  shortageMinutes: number | null;
  needsConfirmation: boolean;
  note: string;
  message: string | null;
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
  breakOvertimeReview?: { breakMinutes: number; workMinutes: number; overtimeMinutes: number; confirmationSource?: "ACTUAL" | "ADMIN_RULE" } | null;
  adoptionReasons?: string[];
  id: number;
  employmentType?: string | null;
  manualEditedByName?: string | null;
  manualEditedByCode?: string | null;
  hasHistories?: boolean;
};

export type BreakOvertimeInputs = {
  plannedStart?: string;
  startBasis?: "RULE" | "ACTUAL";
  actualStart?: string;
  actualStartNextDay?: boolean;
  actualBreakMinutes?: number;
  breakBasis?: "SCHEDULE_END" | "REGULAR_PUNCH";
};

export type BreakOvertimePreview = {
  eligible: boolean;
  message?: string | null;
  defaultStartBasis?: "RULE" | null;
  defaultBreakMinutes?: number | null;
  defaultBreakBasis?: BreakOvertimeInputs["breakBasis"];
  scheduledEnd?: string | null;
  scheduledEndSource?: string | null;
  additionalBreakStart?: string | null;
  additionalBreakStartSource?: "PLANNED_START_8H45" | null;
  additionalBreakMinutes?: number | null;
  ruleKind?: string | null;
  plannedStart: string | null;
  plannedStartSource: string | null;
  recordedBreakMinutes: number;
  branch: "SCHEDULED" | "RAW" | "ACTUAL" | null;
  adoptedStart: string | null;
  rawEnd: string | null;
  breakMinutes: number | null;
  workMinutes: number | null;
  overtimeMinutes: number | null;
  token: string | null;
  canApply: boolean;
};

export type BreakOvertimeConfirmation = BreakOvertimeInputs & {
  startBasis: "RULE" | "ACTUAL";
  token: string;
  confirmed: true;
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

export type AttendanceDailyCreatePayload = {
  employeeId: number;
  targetDate: string;
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
  missingBreakdown?: { workday: number; future: number; holiday: number; today: number };
  dayDetails?: Array<{ targetDate: string; dailyId: number | null; state: string; approvalStatus: string | null; notElapsed?: boolean; holidayName?: string | null; shift?: { name: string | null; start: string | null; end: string | null } | null; incompleteApproved?: boolean }>;
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

export type AttendanceApprovalCandidate = { daily: AttendanceDaily; eligible: boolean; reasons: string[]; fingerprint: string };
export type AttendanceApprovalPreview = { targetMonth: string; items: AttendanceApprovalCandidate[]; eligibleCount: number; excludedCount: number };
export type AttendanceBatchResult = {
  targetMonth?: string; updatedCount: number; unchangedCount?: number; skippedCount?: number; errorCount: number;
  errors: Array<{ id: number; code: string; message: string; status: "FAILED" | "UNKNOWN" | "SKIPPED" }>;
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
  reviewConflict?: string | null;
  reviewChanges?: Array<{ label: string; original: string; current: string; requested: string }>;
  effectiveClockInAt?: string | null;
  effectiveClockOutAt?: string | null;
  currentDaily?: AttendanceDailyDetail | null;
  proposedBreakMinutes?: number;
  proposedWorkMinutes?: number | null;
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
  isAvailable?: boolean;
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

export type EmployeeAttendanceSetting = {
  employeeId: number;
  employeeCode: string;
  employeeName: string;
  departmentName?: string | null;
  locationName?: string | null;
  status?: string | null;
  standardClockInTime?: string | null;
  standardClockOutTime?: string | null;
  includeBeforeStart: boolean;
  includeAfterEnd: boolean;
};

export type AttendanceShiftSchedule = {
  id: number;
  employeeId: number;
  employeeCode: string;
  employeeName: string;
  targetDate: string;
  workTypeId?: number | null;
  workTypeName?: string | null;
  scheduledClockInTime?: string | null;
  scheduledClockOutTime?: string | null;
  note?: string | null;
};

export type AttendanceBreakRule = {
  id: number;
  name: string;
  baseBreakMinutes: number;
  thresholdWorkMinutes: number;
  thresholdBreakMinutes: number;
  note?: string | null;
};
