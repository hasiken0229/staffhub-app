import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AttendanceSettingsPanels } from "@/components/dashboard-sections/attendance/attendance-settings-panels";
import { emptyData } from "@/lib/dashboard-defaults";
import { loadAttendanceShiftSchedules, saveAttendanceShiftSchedule, saveEmployeeAttendanceSetting } from "@/lib/api";
import { ApiRequestError } from "@/lib/api/core";
import type { AttendanceShiftSchedule, DashboardData } from "@/types";

vi.mock("@/lib/api", () => ({ loadAttendanceShiftSchedules: vi.fn(), saveAttendanceShiftSchedule: vi.fn(), saveEmployeeAttendanceSetting: vi.fn(), saveAttendanceBreakRule: vi.fn() }));
function dashboard(): DashboardData {
  return { ...emptyData, employees: [
    { id: 1, employeeCode: "001", name: "職員一", employmentType: "FULL_TIME", status: "ACTIVE" },
    { id: 2, employeeCode: "002", name: "職員二", employmentType: "PART_TIME", status: "ACTIVE" },
  ], employeeAttendanceSettings: [
    { employeeId: 1, employeeCode: "001", employeeName: "職員一", standardClockInTime: null, standardClockOutTime: null, includeBeforeStart: false, includeAfterEnd: false },
    { employeeId: 2, employeeCode: "002", employeeName: "職員二", standardClockInTime: "08:00", standardClockOutTime: "17:00", includeBeforeStart: true, includeAfterEnd: false },
  ], systemMasters: { ...emptyData.systemMasters, workTypes: [{ id: 7, name: "早番", startTime: "07:00", endTime: "16:00", sortOrder: 1, isActive: true }] } };
}
function row(overrides: Partial<AttendanceShiftSchedule> = {}): AttendanceShiftSchedule {
  return { id: 11, employeeId: 1, employeeCode: "001", employeeName: "職員一", targetDate: "2026-10-01", workTypeId: 7, workTypeName: "早番", scheduledClockInTime: "22:00", scheduledClockOutTime: "02:00", note: "既存の予定", ...overrides };
}
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>((done) => { resolve = done; }); return { promise, resolve }; }
const props = () => ({ activePanel: "attendance-shifts", dashboard: dashboard(), targetMonth: "2026-10" });
beforeEach(() => { vi.clearAllMocks(); vi.mocked(loadAttendanceShiftSchedules).mockResolvedValue([]); vi.mocked(saveAttendanceShiftSchedule).mockResolvedValue([]); vi.mocked(saveEmployeeAttendanceSetting).mockResolvedValue(dashboard().employeeAttendanceSettings ?? []); });

describe("attendance shift settings", () => {
  it("loads existing values for the chosen employee/date and distinguishes next-day times", async () => {
    vi.mocked(loadAttendanceShiftSchedules).mockResolvedValue([row()]);
    render(<AttendanceSettingsPanels {...props()} />);
    await waitFor(() => expect(screen.getByLabelText("予定出勤")).toHaveValue("22:00"));
    expect(screen.getByLabelText("予定退勤")).toHaveValue("02:00");
    expect(screen.getByText("翌日 02:00")).toBeVisible();
    fireEvent.change(screen.getByLabelText("日付"), { target: { value: "2026-10-02" } });
    expect(screen.getByLabelText("予定出勤")).toHaveValue("");
    expect(screen.getByLabelText("勤務区分")).toHaveValue("");
    fireEvent.change(screen.getByLabelText("職員"), { target: { value: "2" } });
    expect(screen.getByLabelText("予定出勤")).toHaveValue("08:00");
    expect(screen.getByLabelText("予定退勤")).toHaveValue("17:00");
  });

  it("changes the entry date with the target month and ignores late responses for the previous month", async () => {
    const october = deferred<AttendanceShiftSchedule[]>();
    vi.mocked(loadAttendanceShiftSchedules).mockReturnValueOnce(october.promise).mockResolvedValueOnce([row({ targetDate: "2026-11-01", note: "翌月予定" })]);
    const initial = props(); const view = render(<AttendanceSettingsPanels {...initial} />);
    view.rerender(<AttendanceSettingsPanels {...initial} targetMonth="2026-11" />);
    await waitFor(() => expect(screen.getByLabelText("日付")).toHaveValue("2026-11-01"));
    await waitFor(() => expect(screen.getByText("翌月予定")).toBeVisible());
    await act(async () => { october.resolve([row()]); });
    expect(screen.queryByText("既存の予定")).not.toBeInTheDocument();
    expect(screen.getByLabelText("日付")).toHaveValue("2026-11-01");
  });

  it("blocks editing on a failed load and allows an explicit retry", async () => {
    vi.mocked(loadAttendanceShiftSchedules).mockRejectedValueOnce(new Error("取得できません"));
    render(<AttendanceSettingsPanels {...props()} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("取得できません");
    expect(screen.getByRole("button", { name: "シフトを保存" })).toBeDisabled();
    vi.mocked(loadAttendanceShiftSchedules).mockResolvedValueOnce([row()]);
    fireEvent.click(screen.getByRole("button", { name: "シフトを再読込" }));
    await waitFor(() => expect(screen.getByLabelText("予定出勤")).toHaveValue("22:00"));
    expect(screen.getByRole("button", { name: "シフトを保存" })).toBeEnabled();
  });

  it("saves once and uses the authoritative POST response without a second GET failure", async () => {
    const saving = deferred<AttendanceShiftSchedule[]>();
    vi.mocked(saveAttendanceShiftSchedule).mockReturnValueOnce(saving.promise);
    render(<AttendanceSettingsPanels {...props()} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "シフトを保存" })).toBeEnabled());
    const form = screen.getByRole("button", { name: "シフトを保存" }).closest("form")!;
    fireEvent.submit(form); fireEvent.submit(form);
    expect(saveAttendanceShiftSchedule).toHaveBeenCalledTimes(1);
    expect(saveAttendanceShiftSchedule).toHaveBeenCalledWith(expect.objectContaining({ employeeId: 1, targetDate: "2026-10-01", scheduledClockInTime: null, scheduledClockOutTime: null, workTypeId: null }));
    await act(async () => { saving.resolve([row()]); });
    expect(await screen.findByText(/月次シフトを保存しました/)).toBeVisible();
    expect(loadAttendanceShiftSchedules).toHaveBeenCalledTimes(1);
    expect(screen.getByLabelText("予定出勤")).toHaveValue("22:00");
  });

  it("fills times from a chosen work type and refuses a date outside the target month", async () => {
    render(<AttendanceSettingsPanels {...props()} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "シフトを保存" })).toBeEnabled());
    fireEvent.change(screen.getByLabelText("勤務区分"), { target: { value: "7" } });
    expect(screen.getByLabelText("予定出勤")).toHaveValue("07:00");
    expect(screen.getByLabelText("予定退勤")).toHaveValue("16:00");
    fireEvent.change(screen.getByLabelText("日付"), { target: { value: "2026-09-30" } });
    expect(screen.getByText("対象月内の日付を選択してください。")).toBeVisible();
    expect(screen.getByRole("button", { name: "シフトを保存" })).toBeDisabled();
    fireEvent.submit(screen.getByRole("button", { name: "シフトを保存" }).closest("form")!);
    expect(saveAttendanceShiftSchedule).not.toHaveBeenCalled();
  });

  it("requires reloading after an unknown save result, then displays the committed result", async () => {
    vi.mocked(saveAttendanceShiftSchedule).mockRejectedValueOnce(new ApiRequestError("結果を確認できません", 0, "UNKNOWN_RESULT"));
    render(<AttendanceSettingsPanels {...props()} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "シフトを保存" })).toBeEnabled());
    fireEvent.click(screen.getByRole("button", { name: "シフトを保存" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("再保存する前にシフトを再読込");
    expect(screen.getByRole("button", { name: "シフトを保存" })).toBeDisabled();
    vi.mocked(loadAttendanceShiftSchedules).mockResolvedValueOnce([row()]);
    fireEvent.click(screen.getByRole("button", { name: "シフトを再読込" }));
    await waitFor(() => expect(screen.getByLabelText("予定出勤")).toHaveValue("22:00"));
    expect(saveAttendanceShiftSchedule).toHaveBeenCalledTimes(1);
  });

  it("keeps missing individual settings blank and reflects refreshed server settings", async () => {
    const data = dashboard(); const view = render(<AttendanceSettingsPanels activePanel="attendance-settings" dashboard={data} targetMonth="2026-10" />);
    expect(screen.getByLabelText("基本出勤")).toHaveValue("");
    expect(screen.getByLabelText("基本退勤")).toHaveValue("");
    fireEvent.click(screen.getByRole("button", { name: "個人別設定を保存" }));
    await waitFor(() => expect(saveEmployeeAttendanceSetting).toHaveBeenCalledWith(expect.objectContaining({ standardClockInTime: null, standardClockOutTime: null })));
    const refreshed = { ...data, employeeAttendanceSettings: data.employeeAttendanceSettings!.map((setting) => setting.employeeId === 1 ? { ...setting, standardClockInTime: "08:30", standardClockOutTime: "17:30" } : setting) };
    view.rerender(<AttendanceSettingsPanels activePanel="attendance-settings" dashboard={refreshed} targetMonth="2026-10" />);
    await waitFor(() => expect(screen.getByLabelText("基本出勤")).toHaveValue("08:30"));
    expect(screen.getByLabelText("基本退勤")).toHaveValue("17:30");
  });

  it("accepts employee and basic settings loaded after the panel opens", async () => {
    const initial = { ...props(), dashboard: { ...dashboard(), employees: [], employeeAttendanceSettings: [] } };
    const view = render(<AttendanceSettingsPanels {...initial} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "シフトを保存" })).toBeDisabled());
    const loaded = dashboard(); loaded.employees = loaded.employees.slice(1); loaded.employeeAttendanceSettings = loaded.employeeAttendanceSettings!.slice(1);
    view.rerender(<AttendanceSettingsPanels {...initial} dashboard={loaded} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "シフトを保存" })).toBeEnabled());
    expect(screen.getByLabelText("職員")).toHaveValue("2");
    expect(screen.getByLabelText("予定出勤")).toHaveValue("08:00");
    fireEvent.click(screen.getByRole("button", { name: "シフトを保存" }));
    await waitFor(() => expect(saveAttendanceShiftSchedule).toHaveBeenCalledWith(expect.objectContaining({ employeeId: 2, scheduledClockInTime: "08:00", scheduledClockOutTime: "17:00" })));
  });

  it("links server validation to the time field and keeps the entered value", async () => {
    vi.mocked(saveAttendanceShiftSchedule).mockRejectedValueOnce(new ApiRequestError("入力を確認してください", 422, "VALIDATION_ERROR", [{ field: "scheduledClockOutTime", message: "退勤は出勤後にしてください" }]));
    render(<AttendanceSettingsPanels {...props()} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "シフトを保存" })).toBeEnabled());
    fireEvent.change(screen.getByLabelText("予定出勤"), { target: { value: "09:00" } });
    fireEvent.change(screen.getByLabelText("予定退勤"), { target: { value: "08:00" } });
    fireEvent.click(screen.getByRole("button", { name: "シフトを保存" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "退勤は出勤後にしてください" })).toBeVisible());
    const field = screen.getByLabelText(/^予定退勤/);
    expect(field).toHaveValue("08:00"); expect(field).toHaveAttribute("aria-invalid", "true"); expect(field).toHaveFocus();
  });
});
