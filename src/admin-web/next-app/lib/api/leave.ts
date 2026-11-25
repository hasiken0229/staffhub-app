import type {
  AttendanceAlertSetting,
  AttendanceDailyFieldSetting,
  AttendanceErrorRuleSetting,
  DepartmentSetting,
  EmploymentTypeSetting,
  LeaveHalfDayType,
  LeaveDecisionResult,
  LeaveRequestCreatePayload,
  LeaveRequestCreateResult,
  LeaveRequest,
  LeaveTypeSetting,
  LocationSetting,
  MobileHome,
  Notice,
  PaidLeaveSetting,
  RequestTypeSetting,
  WorkTypeSetting,
} from "@/types";
import { buildQuery, fetchJson } from "@/lib/api/core";
import type { AdminLeaveBalance, AttendanceLeaveBaseline, AttendanceLeaveValues, GrantReviewPayload, GrantReviewPreview } from "@/types/leave";

export function loadAdminLeaveBalance(employeeId: number) {
  return fetchJson<AdminLeaveBalance>(`/api/admin/leave/employees/${employeeId}/balance`);
}
export function previewPaidLeaveGrantReview(id: number, payload: GrantReviewPayload) {
  return fetchJson<GrantReviewPreview>(`/api/admin/leave/grants/${id}/review-preview`, {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload),
  });
}
export function confirmPaidLeaveGrantReview(id: number, payload: GrantReviewPayload & { fingerprint: string }) {
  return fetchJson<AdminLeaveBalance>(`/api/admin/leave/grants/${id}/review`, {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload),
  });
}
export function loadAttendanceLeaveBaseline(employeeId: number, targetDate: string) {
  return fetchJson<AttendanceLeaveBaseline>(`/api/admin/leave/attendance-baseline${buildQuery({ employeeId, targetDate })}`);
}
export function confirmAttendanceLeaveBaseline(payload: {
  employeeId: number; targetDate: string; baseline: AttendanceLeaveValues; evidenceReference: string; fingerprint: string;
}) {
  return fetchJson<AttendanceLeaveBaseline>(`/api/admin/leave/attendance-baseline`, {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload),
  });
}

function normalizeLeaveRequestPayload(payload: LeaveRequestCreatePayload): LeaveRequestCreatePayload {
  if (payload.requestCategory === "TIME_LEAVE") {
    return {
      ...payload,
      requestCategory: "TIME_LEAVE",
      timeLeaveType: payload.timeLeaveType,
      reason: payload.reason?.trim() || undefined,
    };
  }

  const dayUnit = (payload.dayUnit ?? "FULL").toUpperCase() as LeaveRequestCreatePayload["dayUnit"];
  const halfDayType =
    dayUnit === "HALF" && payload.halfDayType
      ? (payload.halfDayType.toUpperCase() as LeaveHalfDayType)
      : null;

  return {
    ...payload,
    requestCategory: "LEAVE",
    leaveTypeCode: payload.leaveTypeCode?.trim().toUpperCase(),
    dayUnit,
    halfDayType,
    reason: payload.reason?.trim() || undefined,
  };
}

export async function loadEmployeeLeaveTypes() {
  const home = await fetchJson<MobileHome>(`/api/mobile/home`);
  return home.leaveTypes ?? [];
}

export async function loadEmployeeStandardDayMinutes(targetDate?: string) {
  return fetchJson<{ standardDayMinutes: number }>(`/api/leave/standard-day-minutes${buildQuery({ targetDate })}`);
}

export async function createEmployeeLeaveRequest(payload: LeaveRequestCreatePayload) {
  return fetchJson<LeaveRequestCreateResult>(`/api/leave/requests`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(normalizeLeaveRequestPayload(payload)),
  });
}

export async function cancelEmployeeLeaveRequest(id: number) {
  return fetchJson<LeaveDecisionResult>(`/api/leave/requests/${id}/cancel`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({}),
  });
}

export async function loadWorkProcedures(filters: {
  status?: string;
  employeeCode?: string;
  departmentName?: string;
  leaveTypeCode?: string;
  requestCategory?: string;
  timeLeaveType?: string;
  from?: string;
  to?: string;
}) {
  return fetchJson<LeaveRequest[]>(`/api/admin/work-procedures${buildQuery(filters)}`);
}

export async function loadWorkProcedureDetail(id: number) {
  return fetchJson<LeaveRequest>(`/api/admin/work-procedures/${id}`);
}

export async function approveWorkProcedure(id: number, comment: string) {
  return fetchJson<LeaveDecisionResult>(`/api/admin/work-procedures/${id}/approve`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ comment }),
  });
}

export async function returnWorkProcedure(id: number, comment: string) {
  return fetchJson<LeaveDecisionResult>(`/api/admin/work-procedures/${id}/return`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ comment }),
  });
}

export async function bulkApproveWorkProcedures(ids: number[], comment: string) {
  return fetchJson<{ updatedCount: number; errorCount?: number; errors?: { id: number; message: string; status: string }[] }>(`/api/admin/work-procedures/bulk-approve`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ids, comment }),
  });
}

export async function bulkReturnWorkProcedures(ids: number[], comment: string) {
  return fetchJson<{ updatedCount: number; errorCount?: number; errors?: { id: number; message: string; status: string }[] }>(`/api/admin/work-procedures/bulk-return`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ids, comment }),
  });
}

export async function grantPaidLeave(payload: {
  employeeId: number;
  days: number;
  grantedOn: string;
  expiresOn?: string;
  note?: string;
}) {
  return fetchJson<{ id: number }>(`/api/admin/leave/grants`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function adjustPaidLeave(payload: {
  employeeId: number;
  adjustmentType: "ADJUST_PLUS" | "ADJUST_MINUS";
  days: number;
  effectiveOn: string;
  note?: string;
}) {
  return fetchJson<{ id: number }>(`/api/admin/leave/adjustments`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function decideLeave(id: number, decision: "approve" | "reject" | "return", comment: string) {
  return fetchJson<LeaveDecisionResult>(`/api/admin/leave/requests/${id}/${decision}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ comment }),
  });
}

export async function createNotice(payload: {
  requestId?: string;
  noticeType: string;
  title: string;
  body: string;
  publishStartAt: string;
  publishEndAt?: string;
  targetEmployeeId?: number;
}) {
  return fetchJson<Notice>(`/api/admin/notices`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

async function saveSystemMaster<T>(path: string, payload: Record<string, unknown>) {
  return fetchJson<T>(path, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function saveDepartmentSetting(payload: { id?: number; name: string; sortOrder?: number; isActive?: boolean }) {
  return saveSystemMaster<DepartmentSetting[]>("/api/admin/system-masters/departments", payload);
}

export async function saveLocationSetting(payload: { id?: number; name: string; sortOrder?: number; isActive?: boolean }) {
  return saveSystemMaster<LocationSetting[]>("/api/admin/system-masters/locations", payload);
}

export async function saveEmploymentTypeSetting(payload: {
  code: string;
  label: string;
  standardDayMinutes?: number;
  sortOrder?: number;
  isActive?: boolean;
}) {
  return saveSystemMaster<EmploymentTypeSetting[]>("/api/admin/system-masters/employment-types", payload);
}

export async function saveWorkTypeSetting(payload: {
  id?: number;
  name: string;
  startTime?: string;
  endTime?: string;
  defaultBreakMinutes?: number;
  standardDayMinutes?: number;
  sortOrder?: number;
  isActive?: boolean;
}) {
  return saveSystemMaster<WorkTypeSetting[]>("/api/admin/system-masters/work-types", payload);
}

export async function saveRequestTypeSetting(payload: {
  code: string;
  name: string;
  sortOrder?: number;
  isActive?: boolean;
}) {
  return saveSystemMaster<RequestTypeSetting[]>("/api/admin/system-masters/request-types", payload);
}

export async function saveLeaveTypeSetting(payload: {
  attendanceEffect?: string;
  balanceSource?: string;
  code: string;
  name: string;
  requiresBalance?: boolean;
  allowsHalfDay?: boolean;
  sortOrder?: number;
  isActive?: boolean;
}) {
  return saveSystemMaster<LeaveTypeSetting[]>("/api/admin/system-masters/leave-types", payload);
}

export async function savePaidLeaveSetting(payload: {
  id?: number;
  settingName: string;
  annualGrantDays: number;
  carryForwardMonths: number;
  standardDayMinutes?: number;
  note?: string;
  isActive?: boolean;
}) {
  return saveSystemMaster<PaidLeaveSetting[]>("/api/admin/system-masters/paid-leave-settings", payload);
}

export async function saveAttendanceAlertSetting(payload: {
  code: string;
  name: string;
  thresholdMinutes?: number;
  enabled?: boolean;
  note?: string;
}) {
  return saveSystemMaster<AttendanceAlertSetting[]>("/api/admin/system-masters/attendance-alerts", payload);
}

export async function saveAttendanceErrorRuleSetting(payload: {
  code: string;
  name: string;
  minWorkMinutes?: number;
  maxWorkMinutes?: number;
  requiredBreakMinutes?: number;
  maxBreakMinutes?: number;
  enabled?: boolean;
  note?: string;
  sortOrder?: number;
}) {
  return saveSystemMaster<AttendanceErrorRuleSetting[]>("/api/admin/system-masters/attendance-error-rules", payload);
}

export async function saveAttendanceDailyFieldSetting(payload: {
  fieldKey: string;
  label: string;
  displayOrder?: number;
  enabled?: boolean;
}) {
  return saveSystemMaster<AttendanceDailyFieldSetting[]>("/api/admin/system-masters/daily-fields", payload);
}

export async function markNotificationRead(id: number, sourceType?: string) {
  return fetchJson<{ success: boolean }>(`/api/notifications/${id}/read`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ sourceType }),
  });
}
