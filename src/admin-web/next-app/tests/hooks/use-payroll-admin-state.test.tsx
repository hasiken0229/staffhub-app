import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { usePayrollAdminState } from "@/hooks/use-payroll-admin-state";

describe("payroll date defaults in the registration form", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-10-10T12:00:00+09:00"));
  });
  afterEach(() => vi.useRealTimers());

  it("starts with this month's payday and publication the day before", () => {
    const { result } = renderHook(usePayrollAdminState);
    expect(result.current.payrollTargetYearMonth).toBe("2026-10");
    expect(result.current.payrollPayDate).toBe("2026-10-28");
    expect(result.current.payrollPublishDate).toBe("2026-10-27");
  });

  it("recalculates both dates when selecting another payroll month", () => {
    const { result } = renderHook(usePayrollAdminState);
    act(() => result.current.setPayrollTargetYearMonth("2026-11"));
    expect(result.current.payrollPayDate).toBe("2026-11-27");
    expect(result.current.payrollPublishDate).toBe("2026-11-26");
  });

  it("tracks a manual payday with the calendar day before and allows a publication override", () => {
    const { result } = renderHook(usePayrollAdminState);
    act(() => result.current.setPayrollPayDate("2026-09-28"));
    expect(result.current.payrollPublishDate).toBe("2026-09-27");
    act(() => result.current.setPayrollPublishDate("2026-09-25"));
    expect(result.current.payrollPublishDate).toBe("2026-09-25");
    act(() => result.current.setPayrollPayDate(""));
    expect(result.current.payrollPublishDate).toBe("");
  });

  it("does not impose the monthly salary rule on a bonus", () => {
    const { result } = renderHook(usePayrollAdminState);
    act(() => result.current.setPayrollStatementType("BONUS"));
    act(() => {
      result.current.setPayrollPayDate("2026-12-10");
      result.current.setPayrollPublishDate("2026-12-08");
    });
    act(() => result.current.setPayrollTargetYearMonth("2026-12"));
    expect(result.current.payrollPayDate).toBe("2026-12-10");
    expect(result.current.payrollPublishDate).toBe("2026-12-08");
  });

  it("clears dates for an incomplete month and restores them after a valid selection", () => {
    const { result } = renderHook(usePayrollAdminState);
    act(() => result.current.setPayrollTargetYearMonth(""));
    expect(result.current.payrollPayDate).toBe("");
    expect(result.current.payrollPublishDate).toBe("");
    act(() => result.current.setPayrollTargetYearMonth("2026-09"));
    expect(result.current.payrollPayDate).toBe("2026-09-28");
    expect(result.current.payrollPublishDate).toBe("2026-09-27");
  });
});
