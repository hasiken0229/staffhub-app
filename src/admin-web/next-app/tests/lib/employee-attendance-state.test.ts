import { describe, expect, it } from "vitest";
import { employeeDayState } from "@/lib/employee-attendance-state";
import type { AttendanceDaily } from "@/types";

const row: AttendanceDaily = { id: null, employeeId: 1, employeeCode: "001", employeeName: "確認", targetDate: "2026-10-10", absenceFlag: false, specialLeaveFlag: false };
describe("職員の勤怠状態", () => {
  it("未来・出勤前・勤務中は打刻漏れにしない", () => {
    expect(employeeDayState({ ...row, targetDate: "2026-10-11" }, "2026-10-10")).toBe("FUTURE");
    expect(employeeDayState(row, "2026-10-10")).toBe("NOT_DUE");
    expect(employeeDayState({ ...row, clockInAt: "2026-10-10T08:00:00+09:00" }, "2026-10-10")).toBe("WORKING");
  });
  it("休日・全日休暇と過去の片側欠落を区別する", () => {
    expect(employeeDayState({ ...row, targetDate: "2026-10-09", scheduleName: "公休" }, "2026-10-10")).toBe("HOLIDAY");
    expect(employeeDayState({ ...row, targetDate: "2026-10-09", paidLeaveUnit: 1 }, "2026-10-10")).toBe("LEAVE");
    expect(employeeDayState({ ...row, targetDate: "2026-10-09", clockInAt: "2026-10-09T08:00:00+09:00" }, "2026-10-10")).toBe("MISSING");
  });
  it("当日の要確認判定はサーバーの予定時刻判定を優先する", () => {
    expect(employeeDayState({ ...row, dayState: "MISSING" }, "2026-10-10")).toBe("MISSING");
  });
});
