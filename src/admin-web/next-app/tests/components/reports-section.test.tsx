import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ComponentProps } from "react";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { ReportsSection } from "@/components/dashboard-sections/reports-section";
import { loadAttendanceDailyGrid, createAttendanceDaily } from "@/lib/api/attendance";
import type { AttendanceDaily } from "@/types";

vi.mock("@/lib/api/attendance", () => ({
  loadAttendanceDailyGrid: vi.fn(), createAttendanceDaily: vi.fn(), loadAttendanceDailyDetail: vi.fn(),
  loadAttendanceDailyHistories: vi.fn().mockResolvedValue([]), resetAttendanceDailyManualEdit: vi.fn(), updateAttendanceDaily: vi.fn(),
}));
vi.mock("@/components/dashboard-sections/attendance/attendance-daily-editor", () => ({ AttendanceDailyEditor: () => null }));

function props(): ComponentProps<typeof ReportsSection> {
  const noop = vi.fn();
  const download = vi.fn().mockResolvedValue(undefined);
  return {
    data: { dashboard: {} as ComponentProps<typeof ReportsSection>["data"]["dashboard"],
      employees: [{ id: 1, employeeCode: "T001", name: "確認職員", employmentType: "PART_TIME" } as never],
      reportTodayAttendance: [], reportAttendanceApprovals: [], paidLeaveReport: [], reportFileHistory: [], reportResult: "", activePanel: "reports-export" },
    filters: { reportMonth: "2026-09", reportFrom: "2026-09-01", reportTo: "2026-09-30", reportEmployeeId: "1" },
    actions: { onReportMonthChange: noop, onReportFromChange: noop, onReportToChange: noop, onReportEmployeeIdChange: noop,
      onOpenMonthClose: vi.fn(),
      onDownloadMonthlyAttendanceCsv: download, onDownloadMonthlyPayrollCsv: download, onDownloadDailyAttendanceCsv: download,
      onDownloadDailyAttendancePdf: download, onDownloadMonthlyWorksPdf: download, onFileHistoryDownload: download },
    formatters: { formatDateOnly: (v) => v ?? "-", formatDateTime: (v) => v ?? "-", formatTimeOnly: (v) => v ?? "-",
      formatApprovalStatus: (v) => v ?? "-", formatImportType: (v) => v ?? "-", formatLeaveLedgerEntryType: (v) => v ?? "-" },
  };
}

describe("report scope and month", () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it("opens the selected calendar month for closing from the report menu and monthly list", async () => {
    const p = props();
    vi.mocked(loadAttendanceDailyGrid).mockResolvedValue([]);
    render(<ReportsSection {...p} />);
    fireEvent.click(screen.getByRole("button", { name: /月次締めへ/ }));
    expect(p.actions.onOpenMonthClose).toHaveBeenLastCalledWith("2026-09");
    expect(p.actions.onDownloadMonthlyAttendanceCsv).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: /月次レポート\s*月ごとの/ }));
    await waitFor(() => expect(loadAttendanceDailyGrid).toHaveBeenCalled());
    fireEvent.click(screen.getByRole("button", { name: "この月の月次締めへ" }));
    expect(p.actions.onOpenMonthClose).toHaveBeenCalledTimes(2);
    expect(screen.getByText(/月次締めは暦月の全職員/)).toBeInTheDocument();
  });

  it("shows original punches separately from adopted corrections and leave hours", async () => {
    const p=props();
    p.data.employees[0].employmentType="FULL_TIME";
    vi.mocked(loadAttendanceDailyGrid).mockResolvedValue([
      {id:1,employeeId:1,employeeCode:"T001",employeeName:"確認職員",targetDate:"2026-09-14",clockInAt:"07:45",clockOutAt:"18:10",rawClockInAt:"07:39",rawClockOutAt:"16:41",workMinutes:565,breakMinutes:60,overtimeMinutes:85,isManuallyEdited:true,timeSource:"MANUAL",childCareLeaveMinutes:180,nursingCareLeaveMinutes:60} as AttendanceDaily,
      {id:2,employeeId:1,employeeCode:"T001",employeeName:"確認職員",targetDate:"2026-09-15",clockInAt:"09:00",clockOutAt:"18:00",workMinutes:480,breakMinutes:60,overtimeMinutes:0,isManuallyEdited:true,sourceSystem:"HRMOS",timeSource:"RULE"} as AttendanceDaily,
    ]);
    render(<ReportsSection {...p}/>);
    fireEvent.click(screen.getByRole("button",{name:/個人別月次表示/}));
    await screen.findByText("手動修正");
    expect(screen.getByText("勤務ルール適用")).toBeInTheDocument();
    expect(screen.getByText("18:10")).toBeInTheDocument();
    expect(screen.getByText("16:41").tagName).toBe("SMALL");
    expect(screen.getByRole("columnheader",{name:"出勤時間/元の打刻"})).toBeInTheDocument();
    expect(screen.getByRole("columnheader",{name:"退勤時間/元の打刻"})).toBeInTheDocument();
    expect(screen.getByRole("columnheader",{name:"勤務時間"})).toBeInTheDocument();
    expect(screen.getByRole("columnheader",{name:"子の看護等休暇"})).toBeInTheDocument();
    expect(screen.getByRole("columnheader",{name:"介護休暇"})).toBeInTheDocument();
    expect(screen.getByText("3:00")).toBeInTheDocument();
    expect(screen.getByText("9:25")).toBeInTheDocument();
    expect(screen.getByText("1:25")).toBeInTheDocument();
  });

  it("describes an all-employee export independently of the selected individual", () => {
    render(<ReportsSection {...props()} />);
    fireEvent.click(screen.getByRole("button", { name: /月次集計データ出力/ }));
    expect(screen.getByText(/対象職員: 全職員/)).toBeInTheDocument();
    expect(screen.getByText(/対象期間:.*前月21日～当月20日/)).toBeInTheDocument();
  });

  it("shows the official citizens' holiday and preserves both leave units", async () => {
    const p = props();
    p.data.employees[0].employmentType = "FULL_TIME";
    vi.mocked(loadAttendanceDailyGrid).mockResolvedValue([
      { id: 1, employeeId: 1, employeeCode: "T001", employeeName: "確認職員", targetDate: "2026-09-22", childCareLeaveDays: 1, childCareLeaveMinutes: 90, nursingCareLeaveDays: 1, nursingCareLeaveMinutes: 45 } as AttendanceDaily,
    ]);
    const view = render(<ReportsSection {...p} />);
    fireEvent.click(screen.getByRole("button", { name: /個人別月次表示/ }));
    await screen.findByText("1日 / 1:30");
    expect(screen.getByText("1日 / 0:45")).toBeInTheDocument();
    const holidayRow = Array.from(view.container.querySelectorAll("tr")).find((row) => row.textContent?.includes("22 火"));
    expect(holidayRow).toHaveClass("report-holiday-row");
    expect(holidayRow?.textContent).toContain("休日");
  });

  it("does not let an older response replace the selected month", async () => {
    let september!: (rows: AttendanceDaily[]) => void;
    const p = props();
    vi.mocked(loadAttendanceDailyGrid).mockImplementationOnce(() => new Promise((resolve) => { september = resolve; }))
      .mockResolvedValueOnce([{ id: 2, employeeId: 1, employeeCode: "T001", employeeName: "10月実績", targetDate: "2026-10-01", workMinutes: 60 }] as AttendanceDaily[]);
    const view = render(<ReportsSection {...p} />);
    fireEvent.click(screen.getByRole("button", { name: /月次レポート\s*月ごとの/ }));
    fireEvent.change(view.container.querySelector('input[type="month"]')!, { target: { value: "2026-10" } });
    view.rerender(<ReportsSection {...p} filters={{ ...p.filters, reportMonth: "2026-10" }} />);
    await screen.findByText("10月実績");
    await act(async () => { september([{ id: 1, employeeId: 1, employeeCode: "T001", employeeName: "9月旧実績", targetDate: "2026-09-01", workMinutes: 60 }] as AttendanceDaily[]); });
    expect(screen.queryByText("9月旧実績")).not.toBeInTheDocument();
    expect(screen.getByText("10月実績")).toBeInTheDocument();
    expect(loadAttendanceDailyGrid).toHaveBeenLastCalledWith({ targetMonth: "2026-10", periodBasis: "PAYROLL" });
  });

  it("can start correcting an empty date in the previous-month portion of the period", async () => {
    vi.mocked(loadAttendanceDailyGrid).mockResolvedValue([]);
    vi.mocked(createAttendanceDaily).mockRejectedValue(new Error("stop after observing creation request"));
    render(<ReportsSection {...props()} />);
    fireEvent.click(screen.getByRole("button", { name: /個人別月次表示/ }));
    await waitFor(() => expect(loadAttendanceDailyGrid).toHaveBeenCalled());
    fireEvent.click(screen.getAllByRole("button", { name: /21 .*を修正/ })[0]);
    await waitFor(() => expect(createAttendanceDaily).toHaveBeenCalledWith({ employeeId: 1, targetDate: "2026-08-21" }));
  });
});
