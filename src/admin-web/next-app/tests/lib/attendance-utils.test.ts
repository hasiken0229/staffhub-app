import { describe, expect, it } from "vitest";
import { hasAvailableMonthClosePrecheck } from "@/lib/attendance-utils";
import { monthDateRangeValue } from "@/lib/date-defaults";

describe("attendance month checks", () => {
  it("accepts only a precheck for the selected month that was actually available", () => {
    const precheck = {
      targetYearMonth: "2026-06",
      canClose: true,
      blockers: [],
    };

    expect(hasAvailableMonthClosePrecheck(precheck, "2026-06")).toBe(true);
    expect(hasAvailableMonthClosePrecheck(precheck, "2026-05")).toBe(false);
    expect(hasAvailableMonthClosePrecheck({ ...precheck, blockers: [{ code: "CHECK_UNAVAILABLE", label: "未取得", count: 1, message: "取得できません" }] }, "2026-06")).toBe(false);
  });

  it("matches the selected month to its calendar date range, including leap years", () => {
    expect(monthDateRangeValue("2024-02")).toEqual({ from: "2024-02-01", to: "2024-02-29" });
    expect(monthDateRangeValue("2026-09")).toEqual({ from: "2026-09-01", to: "2026-09-30" });
  });
});
