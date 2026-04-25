import type { LeaveRequest } from "@/types";

export function formatRequestCategory(value?: string | null) {
  return value === "TIME_LEAVE" ? "時間休暇" : "通常休暇";
}

export function formatTimeLeaveType(value?: string | null) {
  return {
    PAID_HOURLY: "時間有給",
    CHILD_CARE_HOURLY: "子の看護（時間）",
    NURSING_CARE_HOURLY: "介護（時間）",
  }[value ?? ""] ?? value ?? "-";
}

export function formatLeavePeriod(row: LeaveRequest, formatDateOnly: (value?: string | null) => string) {
  if (row.requestCategory === "TIME_LEAVE") {
    return `${formatDateOnly(row.targetDate)} ${row.startTime ?? ""}-${row.endTime ?? ""}`;
  }

  return `${formatDateOnly(row.startDate)} - ${formatDateOnly(row.endDate)}`;
}
