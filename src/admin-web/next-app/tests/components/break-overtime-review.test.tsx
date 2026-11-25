import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { BreakOvertimeReview } from "@/components/dashboard-sections/attendance/break-overtime-review";
import { previewBreakOvertime } from "@/lib/api/attendance";
import type { BreakOvertimePreview } from "@/types";

vi.mock("@/lib/api/attendance", () => ({ previewBreakOvertime: vi.fn() }));
const initial: BreakOvertimePreview = {
  eligible: true, plannedStart: null, plannedStartSource: null, recordedBreakMinutes: 45, branch: null,
  adoptedStart: null, rawEnd: "2026-10-16T16:58:00+09:00", breakMinutes: null, workMinutes: null,
  overtimeMinutes: null, token: null, canApply: false,
};
const calculated = { ...initial, branch: "RAW" as const, adoptedStart: "2026-10-16T07:56:00+09:00",
  breakMinutes: 60, workMinutes: 482, overtimeMinutes: 2, token: "sample-token", canApply: true };
beforeEach(() => { vi.resetAllMocks(); });

describe("休憩不足の日次確認", () => {
  it("予定始業9:30から18:15の追加休憩枠を表示し、退勤18:16なら休憩46分・実働8時間で確定できる", async () => {
    const regular: BreakOvertimePreview = { ...initial, plannedStart: "09:30", defaultStartBasis: "RULE",
      defaultBreakBasis: "REGULAR_PUNCH", scheduledEnd: "18:30", branch: "SCHEDULED",
      adoptedStart: "2026-10-24T09:30:00+09:00", rawEnd: "2026-10-24T18:16:00+09:00",
      additionalBreakStart: "18:15", additionalBreakMinutes: 1, breakMinutes: 46, workMinutes: 480, overtimeMinutes: 0 };
    vi.mocked(previewBreakOvertime).mockResolvedValueOnce(regular)
      .mockResolvedValue({ ...regular, token: "regular-token", canApply: true });
    const onApply = vi.fn();
    render(<BreakOvertimeReview dailyId={24} onApply={onApply}/>);
    const method = await screen.findByLabelText("休憩の計上方法");
    expect(method).toHaveValue("REGULAR_PUNCH");
    expect(screen.getByText(/追加休憩の開始 18:15/)).toHaveTextContent("計上 1分");
    expect(screen.getByText(/休憩 46分/)).toHaveTextContent(/実働 8:00.*時間外 0分/);
    fireEvent.change(method, { target: { value: "ACTUAL" } });
    expect(screen.queryByText(/追加休憩の開始 18:15/)).not.toBeInTheDocument();
    fireEvent.change(method, { target: { value: "REGULAR_PUNCH" } });
    fireEvent.click(screen.getByRole("button", { name: "確認内容で試算" }));
    fireEvent.click(await screen.findByLabelText(/今回のルールで計算した休憩分を確認/));
    fireEvent.click(screen.getByRole("button", { name: "この内容で勤怠に計上" }));
    await waitFor(() => expect(onApply).toHaveBeenCalledExactlyOnceWith(24, {
      startBasis: "RULE", breakBasis: "REGULAR_PUNCH", confirmed: true, token: "regular-token",
    }));
  });

  it("予定終業16:45の休憩枠で52分を試算し、管理者指定の計上方法で送信する", async () => {
    const scheduled = { ...initial, plannedStart: "08:00", defaultStartBasis: "RULE" as const,
      defaultBreakBasis: "SCHEDULE_END" as const, scheduledEnd: "16:45", scheduledEndSource: "当日の勤務区分",
      additionalBreakMinutes: 7, breakMinutes: 52, workMinutes: 480, overtimeMinutes: 0 };
    vi.mocked(previewBreakOvertime).mockResolvedValueOnce(scheduled).mockResolvedValue({
      ...scheduled, token: "scheduled-token", canApply: true,
    });
    const onApply = vi.fn();
    render(<BreakOvertimeReview dailyId={21} onApply={onApply}/>);
    const method = await screen.findByLabelText("休憩の計上方法");
    expect(method).toHaveValue("SCHEDULE_END");
    expect(screen.getByText(/予定終業 16:45/)).toBeVisible();
    expect(screen.getByText(/追加休憩 7分/)).toBeVisible();
    expect(screen.queryByText(/原打刻の出勤から退勤までが9時間1分以上/)).not.toBeInTheDocument();
    fireEvent.change(method, { target: { value: "ACTUAL" } });
    expect(screen.getByLabelText(/実際に取得した休憩/)).toHaveValue(null);
    fireEvent.change(method, { target: { value: "SCHEDULE_END" } });
    fireEvent.click(screen.getByRole("button", { name: "確認内容で試算" }));
    const confirm = await screen.findByLabelText(/予定終業から計算した休憩分を確認/);
    fireEvent.click(confirm);
    fireEvent.click(screen.getByRole("button", { name: "この内容で勤怠に計上" }));
    await waitFor(() => expect(onApply).toHaveBeenCalledExactlyOnceWith(21, {
      startBasis: "RULE", breakBasis: "SCHEDULE_END", confirmed: true, token: "scheduled-token",
    }));
  });

  it("個別設定の計算ルールと60分を初期表示し、確定前に実際の始業・休憩へ変更できる", async () => {
    vi.mocked(previewBreakOvertime)
      .mockResolvedValueOnce({ ...initial, plannedStart: "07:45", defaultStartBasis: "RULE", defaultBreakMinutes: 60 })
      .mockResolvedValue(calculated);
    const onApply = vi.fn();
    render(<BreakOvertimeReview dailyId={20} onApply={onApply}/>);
    const basis = await screen.findByLabelText("採用する始業");
    const minutes = screen.getByLabelText(/実際に取得した休憩/);
    const calculate = screen.getByRole("button", { name: "確認内容で試算" });
    expect(basis).toHaveValue("RULE");
    expect(screen.getByText(/原打刻の出勤から退勤までが9時間1分以上/)).toBeVisible();
    expect(minutes).toHaveValue(60);
    expect(calculate).toBeEnabled();
    expect(screen.queryByRole("button", { name: "この内容で勤怠に計上" })).not.toBeInTheDocument();
    fireEvent.click(calculate);
    expect(previewBreakOvertime).toHaveBeenLastCalledWith(20,
      { startBasis: "RULE", actualBreakMinutes: 60 }, expect.any(AbortSignal));
    expect(await screen.findByRole("button", { name: "この内容で勤怠に計上" })).toBeDisabled();

    fireEvent.change(minutes, { target: { value: "" } });
    expect(minutes).toHaveValue(null);
    expect(calculate).toBeDisabled();
    fireEvent.change(minutes, { target: { value: "45" } });
    fireEvent.change(basis, { target: { value: "" } });
    expect(calculate).toBeDisabled();
    fireEvent.change(basis, { target: { value: "ACTUAL" } });
    expect(screen.queryByText(/原打刻の出勤から退勤までが9時間1分以上/)).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("実際の始業"), { target: { value: "07:40" } });
    fireEvent.click(calculate);
    expect(previewBreakOvertime).toHaveBeenLastCalledWith(20,
      { startBasis: "ACTUAL", actualStart: "07:40", actualStartNextDay: false, actualBreakMinutes: 45 }, expect.any(AbortSignal));
    expect(await screen.findByRole("button", { name: "この内容で勤怠に計上" })).toBeDisabled();
    expect(onApply).not.toHaveBeenCalled();
  });

  it("原出勤打刻から提案された予定始業を表示し、当日の予定に修正して試算できる", async () => {
    const source = "原出勤打刻に最も近い予定始業の候補（7:45・9:00・9:30）";
    vi.mocked(previewBreakOvertime)
      .mockResolvedValueOnce({ ...initial, plannedStart: "09:00", plannedStartSource: source })
      .mockResolvedValue(calculated);
    const onApply = vi.fn();
    render(<BreakOvertimeReview dailyId={19} onApply={onApply}/>);

    const planned = await screen.findByLabelText(/当日の予定始業/);
    expect(planned).toHaveValue("09:00");
    expect(screen.getByText(source)).toBeVisible();
    expect(screen.getByLabelText("採用する始業")).toHaveValue("");

    fireEvent.change(planned, { target: { value: "09:30" } });
    expect(screen.getByText("入力した当日の予定")).toBeVisible();
    fireEvent.change(screen.getByLabelText("採用する始業"), { target: { value: "RULE" } });
    fireEvent.change(screen.getByLabelText(/実際に取得した休憩/), { target: { value: "45" } });
    fireEvent.click(screen.getByRole("button", { name: "確認内容で試算" }));
    expect(previewBreakOvertime).toHaveBeenLastCalledWith(19, {
      plannedStart: "09:30", startBasis: "RULE", actualBreakMinutes: 45,
    }, expect.any(AbortSignal));
    expect(await screen.findByRole("button", { name: "この内容で勤怠に計上" })).toBeDisabled();
    expect(onApply).not.toHaveBeenCalled();
  });

  it("予定不明では推定せず、実際の始業・休憩を入力して試算し明示的に計上する", async () => {
    vi.mocked(previewBreakOvertime).mockResolvedValueOnce(initial).mockResolvedValue(calculated);
    const onApply = vi.fn();
    render(<BreakOvertimeReview dailyId={17} onApply={onApply}/>);
    await screen.findByLabelText("採用する始業");
    const calculate = screen.getByRole("button", { name: "確認内容で試算" });
    expect(calculate).toBeDisabled();
    fireEvent.change(screen.getByLabelText("採用する始業"), { target: { value: "RULE" } });
    fireEvent.click(screen.getByRole("button", { name: /45分＋15分を取得/ }));
    expect(calculate).toBeDisabled();
    fireEvent.change(screen.getByLabelText("採用する始業"), { target: { value: "ACTUAL" } });
    fireEvent.change(screen.getByLabelText("実際の始業"), { target: { value: "07:56" } });
    fireEvent.click(calculate);
    const button = await screen.findByRole("button", {name:"この内容で勤怠に計上"});
    expect(button).toBeDisabled();
    expect(screen.getByText(/実働 8:02/)).toHaveTextContent("時間外 2分");
    expect(onApply).not.toHaveBeenCalled();
    fireEvent.click(screen.getByLabelText(/一致することを確認/));
    fireEvent.click(button);
    await waitFor(() => expect(onApply).toHaveBeenCalledExactlyOnceWith(17, {
      startBasis:"ACTUAL", actualStart:"07:56", actualStartNextDay:false, actualBreakMinutes:60, token:"sample-token", confirmed:true,
    }));
  });

  it("試算後の入力変更で確認を取り消し、古いトークンで保存できない", async () => {
    vi.mocked(previewBreakOvertime).mockResolvedValueOnce({...initial, plannedStart:"08:00", plannedStartSource:"当日の勤務区分"}).mockResolvedValue(calculated);
    const onApply = vi.fn();
    render(<BreakOvertimeReview dailyId={18} onApply={onApply}/>);
    fireEvent.change(await screen.findByLabelText("採用する始業"), { target: { value: "RULE" } });
    fireEvent.click(screen.getByRole("button", {name:/45分＋15分を取得/}));
    fireEvent.click(screen.getByRole("button", {name:"確認内容で試算"}));
    fireEvent.click(await screen.findByLabelText(/一致することを確認/));
    fireEvent.change(screen.getByLabelText(/実際に取得した休憩/), { target: { value:"50" } });
    expect(screen.queryByRole("button", {name:"この内容で勤怠に計上"})).not.toBeInTheDocument();
    expect(onApply).not.toHaveBeenCalled();
  });
});
