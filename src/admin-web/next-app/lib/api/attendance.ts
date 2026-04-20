import type {
  AttendanceApproval,
  AttendanceDaily,
  AttendanceDailyDetail,
  AttendanceDailyEditRequest,
  AttendanceDailyEditRequestCreatePayload,
  AttendanceDailyHistory,
  AttendanceDailyUpdatePayload,
  AttendanceErrorReportRow,
  AttendanceEvent,
  AttendanceMonthClosePrecheck,
  AttendanceMonthCloseStatusRow,
  AttendanceMonthlyCloseSummary,
  LeaveDecisionResult,
} from "@/types";
import { buildQuery, fetchJson } from "@/lib/api/core";

export async function loadAttendanceDailyGrid(filters: {
  targetMonth?: string;
  employeeCode?: string;
  departmentName?: string;
}) {
  return fetchJson<AttendanceDaily[]>(`/api/admin/attendance/daily-grid${buildQuery(filters)}`);
}

export async function loadAttendanceApprovals(filters: {
  status?: string;
  from?: string;
  to?: string;
  employeeCode?: string;
  departmentName?: string;
}) {
  return fetchJson<AttendanceApproval[]>(`/api/admin/attendance/approvals${buildQuery(filters)}`);
}

export async function loadAttendanceMonthlyClose(targetMonth: string) {
  return fetchJson<AttendanceMonthlyCloseSummary>(`/api/admin/attendance/month-close${buildQuery({ targetMonth })}`);
}

export async function loadAttendanceMonthClosePrecheck(targetMonth: string) {
  return fetchJson<AttendanceMonthClosePrecheck>(`/api/admin/attendance/month-close/precheck${buildQuery({ targetMonth })}`);
}

export async function loadAttendanceDailyDetail(id: number) {
  return fetchJson<AttendanceDailyDetail>(`/api/admin/attendance/daily/${id}`);
}

export async function updateAttendanceDaily(id: number, payload: AttendanceDailyUpdatePayload) {
  return fetchJson<AttendanceDailyDetail>(`/api/admin/attendance/daily/${id}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function resetAttendanceDailyManualEdit(id: number) {
  return fetchJson<AttendanceDailyDetail>(`/api/admin/attendance/daily/${id}/manual-edit`, {
    method: "DELETE",
  });
}

export async function loadAttendanceDailyHistories(id: number) {
  return fetchJson<AttendanceDailyHistory[]>(`/api/admin/attendance/daily/${id}/histories`);
}

export async function loadAttendanceErrors(filters: {
  fromMonth?: string;
  toMonth?: string;
  errorCode?: string;
  handlingStatus?: string;
  employeeCode?: string;
  employeeName?: string;
  departmentName?: string;
  locationName?: string;
  employmentType?: string;
  approvalStatus?: string;
}) {
  return fetchJson<AttendanceErrorReportRow[]>(`/api/admin/attendance/errors${buildQuery(filters)}`);
}

export async function resolveAttendanceError(payload: {
  employeeId: number;
  targetDate: string;
  errorCode: string;
  status: "OPEN" | "IN_PROGRESS" | "RESOLVED" | "IGNORED";
  comment?: string;
}) {
  return fetchJson<{ employeeId: number; targetDate: string; errorCode: string; status: string }>(`/api/admin/attendance/errors/resolve`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function loadAttendanceMonthCloseStatus(filters: {
  targetMonth?: string;
  employeeCode?: string;
  employeeName?: string;
  departmentName?: string;
  locationName?: string;
  employmentType?: string;
  approvalStatus?: string;
  closeStatus?: string;
}) {
  return fetchJson<AttendanceMonthCloseStatusRow[]>(`/api/admin/attendance/month-close-status${buildQuery(filters)}`);
}

export async function loadAttendanceDailyEditRequests(filters: {
  status?: string;
  employeeCode?: string;
  departmentName?: string;
  from?: string;
  to?: string;
}) {
  return fetchJson<AttendanceDailyEditRequest[]>(`/api/admin/attendance/daily-edit-requests${buildQuery(filters)}`);
}

export async function approveAttendanceDailyEditRequest(id: number, comment: string) {
  return fetchJson<AttendanceDailyEditRequest>(`/api/admin/attendance/daily-edit-requests/${id}/approve`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ comment }),
  });
}

export async function returnAttendanceDailyEditRequest(id: number, comment: string) {
  return fetchJson<AttendanceDailyEditRequest>(`/api/admin/attendance/daily-edit-requests/${id}/return`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ comment }),
  });
}

export async function loadEmployeeAttendanceDailyEditRequests() {
  return fetchJson<AttendanceDailyEditRequest[]>("/api/attendance/daily-edit-requests");
}

export async function createEmployeeAttendanceDailyEditRequest(payload: AttendanceDailyEditRequestCreatePayload) {
  return fetchJson<AttendanceDailyEditRequest>("/api/attendance/daily-edit-requests", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function updateAttendanceMonthlyClose(payload: {
  targetMonth: string;
  status: "OPEN" | "CLOSED";
  note?: string;
}) {
  return fetchJson<AttendanceMonthlyCloseSummary>("/api/admin/attendance/month-close", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function loadAttendanceEvents(filters: {
  from?: string;
  to?: string;
  employeeCode?: string;
  receiveStatus?: string;
  deviceCode?: string;
}) {
  return fetchJson<AttendanceEvent[]>(`/api/admin/attendance/events${buildQuery(filters)}`);
}

export async function approveAttendanceDaily(id: number, comment: string) {
  return fetchJson<LeaveDecisionResult>(`/api/admin/attendance/approvals/${id}/approve`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ comment }),
  });
}

export async function returnAttendanceDaily(id: number, comment: string) {
  return fetchJson<LeaveDecisionResult>(`/api/admin/attendance/approvals/${id}/return`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ comment }),
  });
}

export async function bulkApproveAttendanceDaily(ids: number[], comment: string) {
  return fetchJson<{ updatedCount: number }>(`/api/admin/attendance/approvals/bulk-approve`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ids, comment }),
  });
}

export async function bulkReturnAttendanceDaily(ids: number[], comment: string) {
  return fetchJson<{ updatedCount: number }>(`/api/admin/attendance/approvals/bulk-return`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ids, comment }),
  });
}
