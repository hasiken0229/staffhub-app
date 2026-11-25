import type { AttendanceDaily } from "@/types";

export type EmployeeDayState = "FUTURE" | "HOLIDAY" | "LEAVE" | "WORKING" | "NOT_DUE" | "COMPLETE" | "MISSING";

export function employeeDayState(row: AttendanceDaily, today = new Date().toLocaleDateString("sv-SE")): EmployeeDayState {
  const serverState = (row as AttendanceDaily & { dayState?: EmployeeDayState }).dayState;
  if (serverState) return serverState;
  if (row.targetDate > today) return "FUTURE";
  const hasIn = Boolean(row.clockInAt ?? row.rawClockInAt);
  const hasOut = Boolean(row.clockOutAt ?? row.rawClockOutAt);
  if (!hasIn && !hasOut && /公休|休日|休園/.test(row.scheduleName ?? "")) return "HOLIDAY";
  if (!hasIn && !hasOut && ((row.paidLeaveUnit ?? 0) >= 1 || row.specialLeaveFlag || row.absenceFlag)) return "LEAVE";
  if (hasIn && hasOut) return "COMPLETE";
  if (row.targetDate === today && hasIn && !hasOut) return "WORKING";
  if (row.targetDate === today && !hasIn && !hasOut) return "NOT_DUE";
  return "MISSING";
}

export function employeeDayStateLabel(row: AttendanceDaily): string {
  const state = employeeDayState(row);
  return { FUTURE: "未到来", HOLIDAY: "休日", LEAVE: "休暇・欠勤", WORKING: "勤務中", NOT_DUE: "出勤前", MISSING: "打刻を要確認", COMPLETE: "勤務完了" }[state];
}
