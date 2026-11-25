import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import calendar from "@/lib/japanese-holidays.json";
import { japaneseHolidayName } from "@/lib/japanese-holidays";
import {
  hasJapaneseHolidayCalendar,
  isJapanesePublicHoliday,
  payrollDateDefaults,
  previousBusinessDateValue,
  previousDateValue,
} from "@/lib/payroll-dates";

describe("payroll dates", () => {
  it("shares official names and dates with the backend PDF calendar", () => {
    const backend = JSON.parse(readFileSync(resolve("../../backend-laravel-app/resources/calendar/japanese-holidays.json"), "utf8"));
    expect(backend).toEqual(calendar);
    for (const date of ["2026-09-22", "2026-05-06", "2019-04-30", "2020-07-24"]) {
      expect(japaneseHolidayName(date)).toBe(backend.names[date]);
      expect(japaneseHolidayName(date)).toBeTruthy();
    }
    expect(japaneseHolidayName("2020-10-12")).toBeUndefined();
    expect(japaneseHolidayName("9999-01-01")).toBeUndefined();
  });
  it.each([
    ["2026-10", "2026-10-28", "2026-10-27"],
    ["2026-02", "2026-02-27", "2026-02-26"],
    ["2026-06", "2026-06-26", "2026-06-25"],
    ["2026-09", "2026-09-28", "2026-09-27"],
    ["2026-11", "2026-11-27", "2026-11-26"],
    ["2024-02", "2024-02-28", "2024-02-27"],
  ])("uses the adjusted 28th and calendar-day-before for %s", (month, payDate, publishDate) => {
    expect(payrollDateDefaults(month)).toEqual({ payDate, publishDate });
  });

  it.each([
    ["2026-09-23", "2026-09-18"], // Autumn equinox, citizens' holiday, Respect for the Aged Day, weekend.
    ["2026-05-06", "2026-05-01"], // Substitute holiday, Golden Week, weekend.
    ["2027-03-22", "2027-03-19"], // Equinox substitute holiday and weekend.
    ["2026-01-01", "2025-12-31"],
  ])("skips consecutive public holidays and weekends from %s", (date, expected) => {
    expect(previousBusinessDateValue(date)).toBe(expected);
  });

  it("includes the official citizens', substitute and historical special holidays", () => {
    expect(isJapanesePublicHoliday("2026-09-22")).toBe(true);
    expect(isJapanesePublicHoliday("2026-05-06")).toBe(true);
    expect(isJapanesePublicHoliday("2019-04-30")).toBe(true);
    expect(isJapanesePublicHoliday("2020-07-24")).toBe(true);
    expect(isJapanesePublicHoliday("2020-10-12")).toBe(false);
  });

  it.each(["", "2026-00", "2026-13", "2026-2", "not-a-month", "9999-02"])("leaves unknown or invalid month %s for manual entry", (month) => {
    expect(hasJapaneseHolidayCalendar(month)).toBe(false);
    expect(payrollDateDefaults(month)).toEqual({ payDate: "", publishDate: "" });
  });

  it("keeps publication on a Sunday and supports a manually entered future date", () => {
    expect(previousDateValue("2026-09-28")).toBe("2026-09-27");
    expect(previousDateValue("2028-03-01")).toBe("2028-02-29");
  });

  it.each(["", "invalid", "2026-02-30"])("does not normalize an invalid date %s", (date) => {
    expect(previousDateValue(date)).toBe("");
    expect(previousBusinessDateValue(date)).toBe("");
  });
});
