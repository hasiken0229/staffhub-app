import type { AttendanceMonthClosePrecheck } from "@/types";

const unavailableCheckCodes = new Set(["CHECK_NOT_LOADED", "CHECK_UNAVAILABLE"]);

export function hasAvailableMonthClosePrecheck(
  precheck: AttendanceMonthClosePrecheck | undefined,
  targetMonth: string,
) {
  return Boolean(
    precheck
      && precheck.targetYearMonth === targetMonth
      && !precheck.blockers.some((item) => unavailableCheckCodes.has(item.code)),
  );
}

export function attendanceDecisionAvailable(row: {id: number | null; approvalStatus?: string}) {
  return row.id !== null && row.approvalStatus !== "NOT_REQUIRED" && row.approvalStatus !== "NOT_DUE";
}

