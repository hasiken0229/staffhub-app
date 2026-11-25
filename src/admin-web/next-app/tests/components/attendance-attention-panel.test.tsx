import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SectionNav } from "@/components/section-nav";
import { AttendanceAttentionPanel } from "@/components/dashboard-sections/attendance/attendance-attention-panel";
import { createAttendanceDaily, loadAttendanceAttentionPage } from "@/lib/api/attendance";
import { formatApprovalStatus } from "@/lib/api/formatters";
import type { AttendanceApproval, AttendanceDailyDetail } from "@/types";

vi.mock("@/lib/api/attendance", () => ({loadAttendanceAttentionPage: vi.fn(), createAttendanceDaily: vi.fn()}));
const daily: AttendanceApproval = {
  id: 9, employeeId: 5, employeeCode: "T05", employeeName: "架空職員",
  targetDate: "2024-05-15", rawClockInAt: "2024-05-15T08:30:00+09:00",
  approvalStatus: "PENDING", approvalReviewReason: "1回しか打刻がありません",
  absenceFlag: false, specialLeaveFlag: false,
};
function result(page: number, total: number, rows: AttendanceApproval[]) {
  return {items: rows, meta: {page, total, perPage: 25}};
}
beforeEach(() => { vi.resetAllMocks(); });
describe("日次勤怠の要確認", () => {
  it("要確認の数を表示せずサインから一覧を開き、届出の件数は保持する", () => {
    const change = vi.fn();
    const {rerender} = render(<SectionNav activeSection="dashboard" onChange={change} badges={{attendance:5106, leave:3}} />);
    const nav = screen.getByRole("button", {name: /日次勤怠.*打刻漏れ/});
    expect(nav).toHaveTextContent("！");
    expect(nav).not.toHaveTextContent("5106");
    fireEvent.click(nav);
    expect(change).toHaveBeenCalledWith("attendance", "attendance-attention");
    expect(screen.getByRole("button", {name:/届出・有給管理.*未処理 3件/})).toHaveTextContent("要3");
    rerender(<SectionNav activeSection="dashboard" onChange={change} badges={{attendance: 0, leave: 3}} />);
    expect(screen.getByRole("button", {name: "日次勤怠"})).not.toHaveTextContent("！");
    expect(formatApprovalStatus("NOT_REQUIRED")).toBe("承認不要");
    expect(formatApprovalStatus("NOT_DUE")).toBe("勤務中・予定");
  });
  it("全期間をページ送りして職員・日付・理由を示し、同じIDの詳細を開く", async () => {
    const open = vi.fn().mockResolvedValue(undefined);
    const total = vi.fn();
    vi.mocked(loadAttendanceAttentionPage).mockResolvedValueOnce(result(1, 51, [daily]))
      .mockResolvedValueOnce(result(2, 51, [{...daily, id: 31, employeeName: "二頁の職員", targetDate: "2022-10-20"}]))
      .mockResolvedValueOnce(result(3, 51, [{...daily, id: 60, employeeName: "最終頁の職員"}]));
    render(<AttendanceAttentionPanel revision="1" onOpenDaily={open} onRefresh={vi.fn()} onTotalChange={total} />);
    await screen.findByText("1回しか打刻がありません");
    expect(total).toHaveBeenCalledWith(51);
    expect(screen.getByRole("button", {name: "前のページ"})).toBeDisabled();
    fireEvent.click(screen.getByRole("button", {name: "次のページ"}));
    expect(await screen.findByRole("row", {name: /二頁の職員/})).toHaveTextContent("2022-10-20");
    fireEvent.click(screen.getByRole("button", {name: "詳細・修正"}));
    expect(open).toHaveBeenCalledWith(31, expect.objectContaining({employeeId: 5, targetDate: "2022-10-20"}));
    fireEvent.click(screen.getByRole("button", {name: "次のページ"}));
    await screen.findByText(/最終頁の職員/);
    expect(screen.getByRole("button", {name: "次のページ"})).toBeDisabled();
    expect(vi.mocked(loadAttendanceAttentionPage).mock.calls.map(([page]) => page)).toEqual([1, 2, 3]);
  });
  it("1回の打刻をどちらと判定したか、予定で補った時刻と実働を表示する", async () => {
    const pair = { clockInAt: "2026-11-09T09:00:00+09:00", clockOutAt: "2026-11-09T18:00:00+09:00", workMinutes: 480 };
    vi.mocked(loadAttendanceAttentionPage).mockResolvedValue(result(1, 2, [
      { ...daily, ...pair, id: 1, rawClockInAt: pair.clockOutAt, scheduleInferredSide: "CLOCK_IN" },
      { ...daily, ...pair, id: 2, rawClockInAt: pair.clockInAt, scheduleInferredSide: "CLOCK_OUT" },
    ]));
    render(<AttendanceAttentionPanel revision="1" onOpenDaily={vi.fn()} onRefresh={vi.fn()} />);
    expect(await screen.findByText("打刻を退勤と判定。出勤は予定の09:00で補完。実働 8:00")).toBeVisible();
    expect(screen.getByText("打刻を出勤と判定。退勤は予定の18:00で補完。実働 8:00")).toBeVisible();
  });
  it("未登録行は確認してから選択した職員・日付でのみ作成し、実際のIDで編集を開く", async () => {
    const missing = {...daily, id: null, approvalReviewReason: "勤務予定がありますが出退勤の記録がありません"};
    vi.mocked(loadAttendanceAttentionPage).mockResolvedValue(result(1, 1, [missing]));
    vi.mocked(createAttendanceDaily).mockResolvedValue({...daily, id: 891} as AttendanceDailyDetail);
    const open = vi.fn().mockResolvedValue(undefined);
    const refresh = vi.fn().mockResolvedValue(undefined);
    render(<AttendanceAttentionPanel revision="1" onOpenDaily={open} onRefresh={refresh} />);
    fireEvent.click(await screen.findByRole("button", {name: "未登録の日次を作成"}));
    expect(createAttendanceDaily).not.toHaveBeenCalled();
    expect(screen.queryByRole("button", {name: "承認"})).toBeNull();
    fireEvent.click(screen.getByRole("button", {name: "日次を作成して開く"}));
    await waitFor(() => expect(open).toHaveBeenCalledWith(891, expect.objectContaining({employeeId: 5, targetDate: "2024-05-15"})));
    expect(createAttendanceDaily).toHaveBeenCalledWith({employeeId: 5, targetDate: "2024-05-15"});
    expect(refresh).toHaveBeenCalled();
  });
  it("表示件数が減った後は残ったページへ戻り、取得失敗をゼロ件と表示しない", async () => {
    vi.mocked(loadAttendanceAttentionPage).mockRejectedValueOnce(new Error("通信に失敗"))
      .mockResolvedValueOnce(result(1, 26, [daily])).mockResolvedValueOnce(result(2, 0, [])).mockResolvedValueOnce(result(1, 0, []));
    render(<AttendanceAttentionPanel revision="1" onOpenDaily={vi.fn()} onRefresh={vi.fn()} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("通信に失敗");
    expect(screen.queryByText("要確認の日次勤怠はありません。")).toBeNull();
    fireEvent.click(screen.getByRole("button", {name: "要確認一覧を更新"}));
    fireEvent.click(await screen.findByRole("button", {name: "次のページ"}));
    await screen.findByText("要確認の日次勤怠はありません。");
    expect(vi.mocked(loadAttendanceAttentionPage).mock.calls.map(([page]) => page)).toEqual([1, 1, 2, 1]);
  });
});
