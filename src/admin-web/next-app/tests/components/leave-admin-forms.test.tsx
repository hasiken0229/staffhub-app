import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { LeaveGrantAdjustPanel } from "@/components/dashboard-sections/leave/leave-grant-adjust-panel";
import { HourlyBalanceForm } from "@/components/dashboard-sections/leave/hourly-balance-form";
import type { LeaveSectionProps } from "@/components/dashboard-sections/leave/leave-section-types";
import * as core from "@/lib/api/core";

describe("admin leave forms", () => {
  it("confirms the employee and amount before granting and places returned field errors beside inputs", async () => {
    const user = userEvent.setup();
    const grant = vi.fn(async (onErrors?: (errors: Record<string, string[]>) => void) => { onErrors?.({ days: ["付与日数を確認してください。"] }); });
    const form: LeaveSectionProps["form"] = { grantEmployeeId: "1", grantDays: "10", grantDate: "2026-10-10", grantExpiresOn: "2027-03-31", grantNote: "",
      adjustType: "ADJUST_MINUS", adjustDays: "1", adjustDate: "2026-10-10", adjustNote: "", decisionComment: "" };
    const actions = { onGrantPaidLeave: grant, onAdjustPaidLeave: vi.fn(), onHourlyBalanceSaved: vi.fn() } as unknown as LeaveSectionProps["actions"];
    const dashboard = { employees: [{ id: 1, employeeCode: "E01", name: "確認職員" }], paidLeaveReport: [] } as unknown as LeaveSectionProps["data"]["dashboard"];
    render(<LeaveGrantAdjustPanel dashboard={dashboard} form={form} actions={actions} leaveAdminResult="" decisionResult="" />);
    await user.click(screen.getByRole("button", { name: "付与を登録" }));
    expect(grant).not.toHaveBeenCalled();
    expect(screen.getByRole("dialog")).toHaveTextContent("E01 / 確認職員");
    expect(screen.getByRole("dialog")).toHaveTextContent("10日");
    await user.click(screen.getByRole("button", { name: "確認して登録" }));
    await waitFor(() => expect(grant).toHaveBeenCalledOnce());
    expect(screen.getByLabelText("付与日数")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getAllByText("付与日数を確認してください。").length).toBeGreaterThan(0);
  });

  it("requires confirmation before changing an hourly snapshot and reports a saved refresh failure accurately", async () => {
    const user = userEvent.setup();
    const save = vi.spyOn(core, "fetchJson").mockResolvedValue({});
    const onSaved = vi.fn().mockRejectedValue(new Error("reload failed"));
    render(<HourlyBalanceForm employeeId={1} onSaved={onSaved} />);
    await user.clear(screen.getByLabelText("残数基準日"));
    await user.type(screen.getByLabelText("残数基準日"), "2026-10-10");
    await user.type(screen.getByLabelText("今年度の利用期限"), "2027-03-31");
    await user.type(screen.getByLabelText("今年度使える残時間"), "2");
    await user.type(screen.getByLabelText("繰越を含む総残時間"), "3");
    await user.click(screen.getByRole("button", { name: "時間有給残数を設定" }));
    expect(save).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "確認して設定" }));
    await screen.findByText("保存済みです。一覧の最新情報を再読み込みしてください。");
    expect(save).toHaveBeenCalledOnce();
    expect(JSON.parse(String(save.mock.calls[0][1]?.body))).toMatchObject({ employeeId: 1, remainingMinutes: 120, totalMinutes: 180 });
    save.mockRestore();
  });
});
