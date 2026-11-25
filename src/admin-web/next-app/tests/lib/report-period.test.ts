import { describe, expect, it } from "vitest";
import { reportPeriod } from "@/lib/report-period";

describe("reportPeriod", () => {
  it("crosses the year for dependents and part-time staff", () => {
    for (const type of ["PART_TIME", "扶養内"]) {
      const { from, to } = reportPeriod("2027-01", type);
      expect([from.getFullYear(), from.getMonth() + 1, from.getDate()]).toEqual([2026, 12, 21]);
      expect([to.getFullYear(), to.getMonth() + 1, to.getDate()]).toEqual([2027, 1, 20]);
    }
  });
  it("keeps leap-day for calendar-month staff", () => {
    expect(reportPeriod("2028-02", "FULL_TIME").to.getDate()).toBe(29);
  });
});
