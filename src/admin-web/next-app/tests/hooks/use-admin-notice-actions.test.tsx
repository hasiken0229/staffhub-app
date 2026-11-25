import { act, renderHook } from "@testing-library/react";
import { beforeEach, expect, it, vi } from "vitest";
import { createNotice } from "@/lib/api";
import { useAdminUtilityActions } from "@/hooks/use-admin-utility-actions";

vi.mock("@/lib/api", () => ({ createNotice: vi.fn() }));

function params() {
  return { assignEmployeeId: "", assignCardUid: "", noticeType: "GENERAL", noticeTitle: "件名", noticeBody: "本文",
    noticeStartAt: "2026-10-11T09:00", noticeEndAt: "", reportMonth: "2026-10", reportEmployeeId: "",
    setAssignResult: vi.fn(), setAssignCardUid: vi.fn(), setNoticeResult: vi.fn(), setNoticeTitle: vi.fn(),
    setNoticeBody: vi.fn(), setEmployeeImportResult: vi.fn(), setPayrollDefinitionResult: vi.fn(), setReportResult: vi.fn(),
    setErrorMessage: vi.fn(), onRefresh: vi.fn().mockResolvedValue(undefined) };
}
beforeEach(() => { vi.mocked(createNotice).mockReset(); });

it("通信結果が不明な登録は同じrequestIdで再試行する", async () => {
  const input = params(); const { result, rerender } = renderHook((values) => useAdminUtilityActions(values), { initialProps: input });
  vi.mocked(createNotice).mockRejectedValueOnce(new Error("通信エラー")).mockResolvedValue({ id: 1 } as Awaited<ReturnType<typeof createNotice>>);
  await act(async () => { await expect(result.current.handleCreateNotice()).rejects.toThrow("通信エラー"); });
  await act(async () => result.current.handleCreateNotice());
  const first = vi.mocked(createNotice).mock.calls[0][0]; const second = vi.mocked(createNotice).mock.calls[1][0];
  expect(first.requestId).toMatch(/^[0-9a-f-]{36}$/); expect(second.requestId).toBe(first.requestId);
  rerender({ ...input, noticeTitle: "次の件名" });
  await act(async () => result.current.handleCreateNotice());
  expect(vi.mocked(createNotice).mock.calls[2][0].requestId).not.toBe(first.requestId);
});

it("重複呼び出しを防ぎ、登録後の一覧更新失敗で再登録を促さない", async () => {
  const input = params(); input.onRefresh.mockRejectedValue(new Error("一覧更新失敗"));
  let resolve!: (value: Awaited<ReturnType<typeof createNotice>>) => void;
  vi.mocked(createNotice).mockReturnValue(new Promise((done) => { resolve = done; }));
  const { result } = renderHook(() => useAdminUtilityActions(input));
  await act(async () => {
    const first = result.current.handleCreateNotice(); await result.current.handleCreateNotice();
    expect(createNotice).toHaveBeenCalledOnce(); resolve({ id: 1 } as Awaited<ReturnType<typeof createNotice>>); await first;
  });
  expect(input.setNoticeTitle).toHaveBeenCalledWith("");
  expect(input.setNoticeResult).toHaveBeenCalledWith(expect.stringContaining("登録しました"));
  expect(input.setErrorMessage).toHaveBeenCalledWith(expect.stringContaining("再登録は不要"));
});
