import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { LeaveSourceReviewPanel } from "@/components/dashboard-sections/leave/leave-source-review-panel";
import { ApiRequestError } from "@/lib/api/core";
import * as api from "@/lib/api/leave";

vi.mock("@/lib/api/leave", () => ({
  confirmAttendanceLeaveBaseline: vi.fn(), confirmPaidLeaveGrantReview: vi.fn(), loadAdminLeaveBalance: vi.fn(),
  loadAttendanceLeaveBaseline: vi.fn(), previewPaidLeaveGrantReview: vi.fn(),
}));
const zero = { absence_flag: 0, special_leave_flag: 0, paid_leave_unit: null, hour_paid_leave_minutes: 0, child_care_leave_minutes: 0, nursing_care_leave_minutes: 0 };
const balance = { employeeId: 1, asOf: "2026-10-10", currentBalance: 5, availableDays: 5, futureApprovedDays: 0,
  grants: [{ id: 7, grantedOn: "2026-09-30", grantedDays: 5, usedDays: 0, remainingDays: 5, expiresOn: null, note: "移行残数", sourceSystem: "HARMOS",
    originalGrantedOn: null, expiryNeedsReview: true, expiryReviewedAt: null, evidenceReference: null }] };

describe("leave source review", () => {
  beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(api.loadAdminLeaveBalance).mockResolvedValue(balance);
    vi.mocked(api.confirmPaidLeaveGrantReview).mockResolvedValue(balance);
  });

  it("shows unknown expiry and does not save until source, preview and confirmation are supplied", async () => {
    const user = userEvent.setup();
    const onSaved = vi.fn().mockResolvedValue(undefined);
    render(<LeaveSourceReviewPanel employees={[{ id: 1, employeeCode: "E01", name: "確認職員" }]} onSaved={onSaved} />);
    await user.click(screen.getByRole("button", { name: "付与内訳を確認" }));
    await screen.findByText("原本確認が必要");
    await user.click(screen.getByRole("button", { name: "期限・内訳を確認" }));
    expect(screen.getByLabelText("失効日")).toHaveValue("");
    expect(api.confirmPaidLeaveGrantReview).not.toHaveBeenCalled();
    await user.type(screen.getByLabelText("失効日"), "2027-03-31");
    await user.type(screen.getByLabelText("確認した原本・確認内容"), "付与管理原本");
    vi.mocked(api.previewPaidLeaveGrantReview).mockResolvedValue({ grantId: 7, employeeId: 1, effectiveOn: "2026-09-30", totalDays: 5,
      beforeBalance: 5, afterBalance: 5, lots: [{ days: 5, expiresOn: "2027-03-31" }], evidenceReference: "付与管理原本", fingerprint: "f".repeat(64) });
    await user.click(screen.getByRole("button", { name: "保存前の残数を確認" }));
    await screen.findByText("現在の残数 5日 → 保存後 5日");
    await user.click(screen.getByRole("button", { name: "原本確認済みの内訳を保存" }));
    expect(api.confirmPaidLeaveGrantReview).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "確認済みの内訳を保存" }));
    await waitFor(() => expect(api.confirmPaidLeaveGrantReview).toHaveBeenCalledOnce());
    expect(api.confirmPaidLeaveGrantReview).toHaveBeenCalledWith(7, expect.objectContaining({ fingerprint: "f".repeat(64), evidenceReference: "付与管理原本" }));
    expect(onSaved).toHaveBeenCalledOnce();
  });

  it("leaves ambiguous daily baseline unconfirmed until an explicit source entry", async () => {
    const user = userEvent.setup();
    const daily = { employeeId: 1, targetDate: "2026-10-12", current: { ...zero, paid_leave_unit: 1, child_care_leave_minutes: 60 },
      applied: { ...zero, paid_leave_unit: 1 }, baseline: null, requestIds: [9], fingerprint: "b".repeat(64) };
    vi.mocked(api.loadAttendanceLeaveBaseline).mockResolvedValue(daily);
    vi.mocked(api.confirmAttendanceLeaveBaseline).mockResolvedValue({ ...daily, baseline: { ...zero, child_care_leave_minutes: 60 } });
    render(<LeaveSourceReviewPanel employees={[{ id: 1, employeeCode: "E01", name: "確認職員" }]} onSaved={vi.fn().mockResolvedValue(undefined)} />);
    await user.clear(screen.getByLabelText("対象日"));
    await user.type(screen.getByLabelText("対象日"), "2026-10-12");
    await user.click(screen.getByRole("button", { name: "日次の現在値を読込" }));
    await screen.findByRole("button", { name: "原本から内訳を入力する" });
    expect(screen.getByRole("button", { name: "原本確認済みの内訳を保存" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "原本から内訳を入力する" }));
    const input = screen.getByLabelText("子の看護等（分）の移行・手修正分");
    await user.clear(input); await user.type(input, "60");
    await user.type(screen.getByLabelText("確認した原本・確認内容"), "移行CSV照合");
    await user.click(screen.getByRole("button", { name: "原本確認済みの内訳を保存" }));
    expect(api.confirmAttendanceLeaveBaseline).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "確認済みの内訳を保存" }));
    await waitFor(() => expect(api.confirmAttendanceLeaveBaseline).toHaveBeenCalledWith(expect.objectContaining({ baseline: { ...zero, child_care_leave_minutes: 60 } })));
  });

  it("shows source validation next to the field and never confirms a failed preview", async () => {
    const user = userEvent.setup();
    render(<LeaveSourceReviewPanel employees={[{ id: 1, employeeCode: "E01", name: "確認職員" }]} onSaved={vi.fn().mockResolvedValue(undefined)} />);
    await user.click(screen.getByRole("button", { name: "付与内訳を確認" }));
    await screen.findByText("原本確認が必要");
    await user.click(screen.getByRole("button", { name: "期限・内訳を確認" }));
    await user.type(screen.getByLabelText("失効日"), "2027-03-31");
    await user.type(screen.getByLabelText("確認した原本・確認内容"), "原本");
    vi.mocked(api.previewPaidLeaveGrantReview).mockRejectedValue(new ApiRequestError("入力内容を確認してください。", 422, "VALIDATION_ERROR", [{ field: "lots.0.expiresOn", message: "原本の期限を再確認してください。" }]));
    await user.click(screen.getByRole("button", { name: "保存前の残数を確認" }));
    await screen.findAllByText("原本の期限を再確認してください。");
    expect(screen.getByLabelText(/失効日/)).toHaveAttribute("aria-invalid", "true");
    expect(api.confirmPaidLeaveGrantReview).not.toHaveBeenCalled();
  });
});
