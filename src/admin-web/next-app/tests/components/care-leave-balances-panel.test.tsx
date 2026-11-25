import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { it, expect, vi } from "vitest";
import { CareLeaveBalancesPanel } from "@/components/dashboard-sections/leave/care-leave-balances-panel";
import { fetchJson } from "@/lib/api/core";
vi.mock("@/lib/api/core", () => ({ fetchJson: vi.fn() }));
it("shows unknown balances without inventing entitlement and saves an explicit baseline", async () => {
  vi.mocked(fetchJson).mockResolvedValue([{ employeeId: 1, employeeCode: "970006", employeeName: "確認職員", category: "CHILD", configured: false,
    eligibleCount: null, entitlementDays: null, dayMinutes: null, effectiveOn: null, openingMinutes: null, note: null,
    recordedDays: 1, recordedMinutes: 180, remainingMinutes: null, futureReservedMinutes: null, availableMinutes: null }]);
  render(<CareLeaveBalancesPanel />);
  fireEvent.click(await screen.findByText("設定", { selector: "button" }));
  expect(screen.getByLabelText("対象人数")).toHaveValue(null);
  expect(screen.getByLabelText("残日数")).toHaveValue(null);
  fireEvent.change(screen.getByLabelText("対象人数"), { target: { value: "2" } });
  fireEvent.change(screen.getByLabelText("所定勤務時間（1日分・分）"), { target: { value: "360" } });
  fireEvent.change(screen.getByLabelText("残日数"), { target: { value: "7" } });
  fireEvent.change(screen.getByLabelText("残日数に加える端数（分）"), { target: { value: "180" } });
  fireEvent.change(screen.getByLabelText("確認根拠・変更理由"), { target: { value: "原本確認" } });
  fireEvent.click(screen.getByText("確認済み残数を保存"));
  await waitFor(() => expect(vi.mocked(fetchJson).mock.calls.some(([, options]) => options?.method === "POST")).toBe(true));
  const call = vi.mocked(fetchJson).mock.calls.find(([, options]) => options?.method === "POST")!;
  expect(JSON.parse(call[1]!.body as string)).toMatchObject({ employeeId: 1, category: "CHILD", eligibleCount: 2, dayMinutes: 360, openingMinutes: 2700 });
});
