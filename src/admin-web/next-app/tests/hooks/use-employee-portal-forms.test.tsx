import { act, renderHook, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { useEmployeePortalForms } from "@/components/dashboard-sections/employee-portal/use-employee-portal-forms";
import { emptyEmployeePortal } from "@/lib/dashboard-defaults";
import type { EmployeePortalSectionProps } from "@/components/dashboard-sections/employee-portal/employee-portal-types";
import type { AttendanceDaily } from "@/types";
import { ApiRequestError, loadEmployeeLeaveRequests, loadEmployeeAttendanceDailyEditRequests } from "@/lib/api";
import { loadEmployeeBalanceAtDate } from "@/lib/api/employee-balance";

vi.mock("@/lib/api", async (importOriginal) => ({ ...(await importOriginal<typeof import("@/lib/api")>()), loadEmployeeLeaveRequests: vi.fn().mockResolvedValue([]), loadEmployeeAttendanceDailyEditRequests: vi.fn().mockResolvedValue([]), loadEmployeeStandardDayMinutes: vi.fn().mockResolvedValue({ standardDayMinutes: 480 }), loadEmployeeAttendanceDaily: vi.fn().mockResolvedValue([]) }));
vi.mock("@/lib/api/employee-balance", () => ({ loadEmployeeBalanceAtDate: vi.fn(async (asOf: string) => ({ asOf, currentBalance: 10, availableDays: 8, futureApprovedDays: 1, hourlyBalance: { availableMinutes: 1200, pendingMinutes: 60, expiresOn: "2027-03-31" } })) }));

function daily(date: string): AttendanceDaily { return { id: 1, targetDate: date, employeeId: 1, employeeCode: "001", employeeName: "確認", absenceFlag: false, specialLeaveFlag: false }; }
function props(): EmployeePortalSectionProps {
  return { data: { employeePortal: { ...emptyEmployeePortal, attendanceTargetMonth: "2026-09", attendanceDaily: [daily("2026-09-01")] }, isPending: false, errorMessage: "" },
    actions: { onRefresh: vi.fn(), onLogout: vi.fn(), onLeaveRequestCreate: vi.fn().mockResolvedValue(true), onLeaveRequestCancel: vi.fn(), onAttendanceDailyEditRequestCreate: vi.fn(), onLoadEmployeePayrollDetail: vi.fn(), onPayrollDownload: vi.fn(), onNotificationRead: vi.fn() },
    formatters: { formatDateOnly: (v) => v ?? "-", formatDateTime: (v) => v ?? "-", formatMonthDay: (v) => v ?? "-", formatApprovalStatus: (v) => v ?? "-", formatLeaveLedgerEntryType: (v) => v ?? "-" } };
}

describe("職員画面の対象月と時間有給", () => {
  it("単日の開始日を翌月にすると終了日も移り、複数日の有効な終了日は保持される", () => {
    const input = props();
    const view = renderHook(() => useEmployeePortalForms(input));
    const form = () => view.result.current.leaveRequestFormProps;
    act(() => form().onStartDateChange("2026-12-01"));
    expect(form().values.endDate).toBe("2026-12-01");
    act(() => form().onEndDateChange("2026-12-08"));
    act(() => form().onStartDateChange("2026-12-03"));
    expect(form().values.endDate).toBe("2026-12-08");
    act(() => form().onStartDateChange("2026-12-10"));
    expect(form().values.endDate).toBe("2026-12-10");
  });
  it("本人の勤務日を表示・確認して、同じ日付だけを申請する", async () => {
    const input = props();
    input.data.employeePortal.home = { ...input.data.employeePortal.home, leaveTypes: [
      { code: "PAID", name: "有給", requiresBalance: true, allowsHalfDay: true, sortOrder: 1, isActive: true },
    ] };
    const workdays = { dates: ["2026-10-09", "2026-10-12"], excludedDates: ["2026-10-10", "2026-10-11"], unconfirmedDates: ["2026-10-12"] };
    const balanceMock = vi.mocked(loadEmployeeBalanceAtDate);
    const original = balanceMock.getMockImplementation()!;
    balanceMock.mockImplementation(async (asOf, through) => ({
      asOf, availableDays: 10, currentBalance: 10, futureApprovedDays: 0,
      workdays: through === "2026-10-12" ? workdays : undefined,
    }));
    const view = renderHook(() => useEmployeePortalForms(input));
    act(() => {
      view.result.current.leaveRequestFormProps.onStartDateChange("2026-10-09");
      view.result.current.leaveRequestFormProps.onEndDateChange("2026-10-12");
    });
    await waitFor(() => expect(view.result.current.leaveRequestFormProps.balancePreview.requestedDays).toBe(2), { timeout: 5000 });
    expect(view.result.current.leaveRequestFormProps.balancePreview.workdays).toEqual(workdays);
    act(() => view.result.current.leaveRequestFormProps.onSubmit({ preventDefault: vi.fn() } as unknown as React.FormEvent<HTMLFormElement>));
    expect(view.result.current.confirmationDialogProps.message).toContain("勤務予定未登録");
    await act(async () => view.result.current.confirmationDialogProps.onConfirm());
    expect(input.actions.onLeaveRequestCreate).toHaveBeenCalledWith(expect.objectContaining({ expectedDates: workdays.dates }));
    balanceMock.mockImplementation(original);
  });

  it("行からの申請でも翌日の退勤と複数休憩をすべて保持する", async () => {
    const input = props();
    const row = { ...daily("2026-09-01"), workTypeId: 7, clockInAt: "2026-09-01T22:00:00+09:00", clockOutAt: "2026-09-02T05:00:00+09:00", breaks: [
      { startAt: "2026-09-01T23:00:00+09:00", endAt: "2026-09-01T23:15:00+09:00" },
      { startAt: "2026-09-02T02:00:00+09:00", endAt: "2026-09-02T02:45:00+09:00" },
    ] };
    const view = renderHook(() => useEmployeePortalForms(input));
    act(() => view.result.current.dailyEditRequestFormProps.onDailyRowSubmit(row));
    expect(view.result.current.confirmationDialogProps.message).toContain("退勤: 翌日 05:00");
    expect(view.result.current.confirmationDialogProps.message).toContain("翌日 02:00 - 翌日 02:45");
    await act(async () => view.result.current.confirmationDialogProps.onConfirm());
    expect(input.actions.onAttendanceDailyEditRequestCreate).toHaveBeenCalledWith(expect.objectContaining({ workTypeId: 7, clockOutNextDay: true, breaks: [
      { startTime: "23:00", endTime: "23:15", startNextDay: false, endNextDay: false },
      { startTime: "02:00", endTime: "02:45", startNextDay: true, endNextDay: true },
    ] }));
  });

  it("編集フォーム経由でも複数休憩を保持し、片方だけの休憩を黙って捨てない", async () => {
    const input = props();
    const view = renderHook(() => useEmployeePortalForms(input));
    const formEvent = { preventDefault: vi.fn() } as unknown as React.FormEvent<HTMLFormElement>;
    act(() => view.result.current.dailyEditRequestFormProps.onDailyRowEdit({ ...daily("2026-09-01"), workTypeId: 9, clockInAt: "2026-09-01T22:00:00+09:00", clockOutAt: "2026-09-02T05:00:00+09:00", breaks: [
      { startTime: "23:00", endTime: "23:15" }, { startTime: "02:00", endTime: "02:45", startNextDay: true, endNextDay: true },
    ] }));
    act(() => view.result.current.dailyEditRequestFormProps.onSubmit(formEvent));
    await act(async () => view.result.current.confirmationDialogProps.onConfirm());
    expect(input.actions.onAttendanceDailyEditRequestCreate).toHaveBeenCalledWith(expect.objectContaining({ workTypeId: 9, clockOutNextDay: true, breaks: expect.arrayContaining([expect.objectContaining({ startTime: "02:00", startNextDay: true })]) }));
    act(() => view.result.current.dailyEditRequestFormProps.onEditBreakEndTimeChange(""));
    act(() => view.result.current.dailyEditRequestFormProps.onSubmit(formEvent));
    expect(view.result.current.confirmationDialogProps.open).toBe(false);
    expect(view.result.current.dailyEditRequestFormProps.editRequestError).toContain("両方入力");
    expect(view.result.current.dailyEditRequestFormProps.fieldErrors).toHaveProperty("breaks.0.endTime");
    expect(input.actions.onAttendanceDailyEditRequestCreate).toHaveBeenCalledTimes(1);
  });

  it("別日の行申請・修正に前日の時刻、備考、コメントを持ち込まず、変更前後を確認できる", async () => {
    const input = props();
    input.data.employeePortal.attendanceDaily = [
      { ...daily("2026-11-01"), workTypeId: 7, clockInAt: "07:30", clockOutAt: "16:30", remark: "備考1" },
      { ...daily("2026-11-02"), workTypeId: 9, clockInAt: "09:30", clockOutAt: "18:30", remark: "備考2" },
    ];
    const view = renderHook(() => useEmployeePortalForms(input));
    act(() => view.result.current.dailyEditRequestFormProps.onDailyRowEdit(input.data.employeePortal.attendanceDaily[0]));
    act(() => view.result.current.dailyEditRequestFormProps.onEditEmployeeCommentChange("1日のみ"));
    act(() => view.result.current.dailyEditRequestFormProps.onDailyRowSubmit(input.data.employeePortal.attendanceDaily[1]));
    expect(view.result.current.confirmationDialogProps.message).toContain("申請コメント: なし");
    expect(view.result.current.confirmationDialogProps.message).not.toContain("1日のみ");
    await act(async () => view.result.current.confirmationDialogProps.onConfirm());
    expect(input.actions.onAttendanceDailyEditRequestCreate).toHaveBeenLastCalledWith(expect.objectContaining({ targetDate: "2026-11-02", employeeComment: "", remark: "備考2" }));

    expect(view.result.current.dailyEditRequestFormProps.values.editEmployeeComment).toBe("1日のみ");
    act(() => view.result.current.dailyEditRequestFormProps.onDailyRowEdit(input.data.employeePortal.attendanceDaily[1]));
    expect(view.result.current.dailyEditRequestFormProps.values).toMatchObject({ editTargetDate: "2026-11-02", editClockInTime: "09:30", editClockOutTime: "18:30", editRemark: "備考2", editEmployeeComment: "" });
    act(() => {
      view.result.current.dailyEditRequestFormProps.onEditClockOutTimeChange("18:45");
      view.result.current.dailyEditRequestFormProps.onEditEmployeeCommentChange("2日分の修正");
    });
    act(() => view.result.current.dailyEditRequestFormProps.onSubmit({ preventDefault: vi.fn() } as unknown as React.FormEvent<HTMLFormElement>));
    expect(view.result.current.confirmationDialogProps.message).toMatch(/変更前[\s\S]*退勤: 18:30[\s\S]*申請後[\s\S]*退勤: 18:45[\s\S]*申請コメント: 2日分の修正/);
    await act(async () => view.result.current.confirmationDialogProps.onConfirm());
    expect(input.actions.onAttendanceDailyEditRequestCreate).toHaveBeenLastCalledWith(expect.objectContaining({ targetDate: "2026-11-02", workTypeId: 9, clockOutTime: "18:45", remark: "備考2", employeeComment: "2日分の修正" }));
  });

  it("過去月選択中に別月の全体応答が届いても表示月と一覧を混ぜない", async () => {
    const initial = props();
    const view = renderHook((input) => useEmployeePortalForms(input), { initialProps: initial });
    view.rerender({ ...initial, data: { ...initial.data, employeePortal: { ...initial.data.employeePortal, attendanceTargetMonth: "2026-10", attendanceDaily: [daily("2026-10-01")] } } });
    expect(view.result.current.dailyEditRequestFormProps.values.editTargetMonth).toBe("2026-09");
    expect(view.result.current.dailyEditRequestFormProps.attendanceDaily[0].targetDate).toBe("2026-09-01");
    await waitFor(() => expect(view.result.current.leaveRequestFormProps.isBalanceLoading).toBe(false));
  });

  it("対象月の取得が逆順に完了しても最後に選んだ月を保持する", async () => {
    const input = props();
    let finishAugust!: (value: AttendanceDaily[]) => void;
    input.actions.onAttendanceMonthChange = vi.fn((month: string) => month === "2026-08" ? new Promise<AttendanceDaily[]>(resolve => { finishAugust = resolve; }) : Promise.resolve([daily("2026-07-01")]));
    const view = renderHook(() => useEmployeePortalForms(input));
    act(() => view.result.current.dailyEditRequestFormProps.onEditTargetMonthChange("2026-08"));
    act(() => view.result.current.dailyEditRequestFormProps.onEditTargetMonthChange("2026-07"));
    await waitFor(() => expect(view.result.current.dailyEditRequestFormProps.attendanceDaily[0]?.targetDate).toBe("2026-07-01"));
    await act(async () => finishAugust([daily("2026-08-01")]));
    expect(view.result.current.dailyEditRequestFormProps.values.editTargetMonth).toBe("2026-07");
    expect(view.result.current.dailyEditRequestFormProps.attendanceDaily[0].targetDate).toBe("2026-07-01");
  });

  it("対象日時点の時間残高を取得し、確認文も時間で表示する", async () => {
    const input = props();
    const view = renderHook(() => useEmployeePortalForms(input));
    act(() => { view.result.current.leaveRequestFormProps.onRequestCategoryChange("TIME_LEAVE"); view.result.current.leaveRequestFormProps.onTargetDateChange("2026-10-20"); });
    await waitFor(() => expect(loadEmployeeBalanceAtDate).toHaveBeenLastCalledWith("2026-10-20"), { timeout: 5000 });
    await waitFor(() => expect(view.result.current.leaveRequestFormProps.balanceAvailable).toBe(true), { timeout: 5000 });
    expect(view.result.current.leaveRequestFormProps.balancePreview.requestedMinutes).toBe(60);
    expect(view.result.current.leaveRequestFormProps.balancePreview.projectedMinutes).toBe(1140);
    await act(async () => view.result.current.leaveRequestFormProps.onSubmit({ preventDefault: vi.fn() } as unknown as React.FormEvent<HTMLFormElement>));
    expect(view.result.current.confirmationDialogProps.message).toContain("申請量: 1時間");
    expect(view.result.current.confirmationDialogProps.message).not.toContain("0.00日");
  });
  it("送信結果不明では再送を止め、一覧の取得成功後に確認を促す", async () => {
    const input = props();
    input.actions.onAttendanceDailyEditRequestCreate = vi.fn().mockRejectedValue(new ApiRequestError("結果不明", 500, "UNKNOWN_RESULT"));
    const view = renderHook(() => useEmployeePortalForms(input));
    await act(async () => view.result.current.dailyEditRequestFormProps.onDailyRowSubmit({ ...daily("2026-09-01"), clockInAt: "2026-09-01T09:00:00+09:00", clockOutAt: "2026-09-01T18:00:00+09:00" }));
    await act(async () => view.result.current.confirmationDialogProps.onConfirm());
    expect(view.result.current.dailyEditRequestFormProps.unknownResult).toBe(true);
    expect(view.result.current.dailyEditRequestFormProps.isEditRequestSubmitting).toBe(true);
    await act(async () => view.result.current.dailyEditRequestFormProps.onDailyRowSubmit(daily("2026-09-02")));
    expect(input.actions.onAttendanceDailyEditRequestCreate).toHaveBeenCalledTimes(1);
    await act(async () => view.result.current.dailyEditRequestFormProps.onReconcileResult());
    expect(loadEmployeeLeaveRequests).toHaveBeenCalled();
    expect(loadEmployeeAttendanceDailyEditRequests).toHaveBeenCalled();
    expect(view.result.current.dailyEditRequestFormProps.unknownResult).toBe(false);
    expect(view.result.current.dailyEditRequestFormProps.editRequestMessage).toContain("登録済みの申請は再送不要");
  });

  it("時間有給の残高基準日当日は申請を許可せず翌日以降を案内する", async () => {
    const input = props();
    vi.mocked(loadEmployeeBalanceAtDate).mockResolvedValue({ asOf: "2026-10-20", currentBalance: 10, availableDays: 8, futureApprovedDays: 0, hourlyManagementConfigured: true, hourlyBalance: { effectiveOn: "2026-10-20", availableMinutes: 1200, pendingMinutes: 0, expiresOn: "2027-03-31" } } as Awaited<ReturnType<typeof loadEmployeeBalanceAtDate>>);
    const view = renderHook(() => useEmployeePortalForms(input));
    await waitFor(() => expect(view.result.current.leaveRequestFormProps.isBalanceLoading).toBe(false));
    act(() => view.result.current.leaveRequestFormProps.onRequestCategoryChange("TIME_LEAVE"));
    act(() => view.result.current.leaveRequestFormProps.onTargetDateChange("2026-10-20"));
    await waitFor(() => expect(view.result.current.leaveRequestFormProps.isBalanceLoading).toBe(false));
    expect(view.result.current.leaveRequestFormProps.balanceAvailable).toBe(false);
    expect(view.result.current.leaveRequestFormProps.hourlyEffectiveOn).toBe("2026-10-20");
  });

  it("結果確認後の画面更新に失敗したときは再送停止を解除しない", async () => {
    const input = props();
    input.actions.onAttendanceDailyEditRequestCreate = vi.fn().mockRejectedValue(new ApiRequestError("結果不明", 500, "UNKNOWN_RESULT"));
    input.actions.onRequestStatusReload = vi.fn().mockRejectedValue(new Error("一覧取得失敗"));
    const view = renderHook(() => useEmployeePortalForms(input));
    await act(async () => view.result.current.dailyEditRequestFormProps.onDailyRowSubmit({ ...daily("2026-09-01"), clockInAt: "2026-09-01T09:00:00+09:00", clockOutAt: "2026-09-01T18:00:00+09:00" }));
    await act(async () => view.result.current.confirmationDialogProps.onConfirm());
    await act(async () => view.result.current.dailyEditRequestFormProps.onReconcileResult());
    expect(view.result.current.dailyEditRequestFormProps.unknownResult).toBe(true);
    expect(view.result.current.dailyEditRequestFormProps.editRequestError).toBe("一覧取得失敗");
  });

});
