import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { DailyEditRequestForm } from "@/components/dashboard-sections/employee-portal/daily-edit-request-form";
import type { AttendanceDaily } from "@/types";

const values = {
  editTargetMonth: "2026-09",
  editTargetDate: "2026-09-11",
  editClockInTime: "",
  editClockOutTime: "",
  editBreakStartTime: "",
  editBreakEndTime: "",
  editRemark: "",
  editEmployeeComment: "",
};

function createProps() {
  return {
    values,
    attendanceDaily: [] as AttendanceDaily[],
    attendanceDailyLoadError: "",
    requestCount: 0,
    editRequestError: "",
    editRequestMessage: "",
    isPending: false,
    isEditRequestSubmitting: false,
    isAttendanceDailyLoading: false,
    isDailyEditFormOpen: false,
    onSubmit: vi.fn(),
    onReset: vi.fn(),
    onEditTargetMonthChange: vi.fn(),
    onRetryAttendanceDailyLoad: vi.fn(),
    onDailyRowEdit: vi.fn(),
    onDailyRowSubmit: vi.fn(),
    onEditClockInTimeChange: vi.fn(),
    onEditClockOutTimeChange: vi.fn(),
    onEditBreakStartTimeChange: vi.fn(),
    onEditBreakEndTimeChange: vi.fn(),
    onEditRemarkChange: vi.fn(),
    onEditEmployeeCommentChange: vi.fn(),
  };
}

describe("DailyEditRequestForm", () => {
  it("選択した日を固定し、フォームと起点の修正ボタンにフォーカスを移す", () => {
    const props = createProps();
    props.attendanceDaily = [{ id: null, targetDate: values.editTargetDate, employeeId: 1, employeeCode: "1", employeeName: "確認", absenceFlag: false, specialLeaveFlag: false }];
    const view = render(<DailyEditRequestForm {...props} />);
    const trigger = screen.getAllByRole("button", { name: "打刻漏れを修正" })[1];
    fireEvent.click(trigger);
    view.rerender(<DailyEditRequestForm {...props} isDailyEditFormOpen />);
    expect(screen.getByRole("heading", { name: /2026-09-11.*修正内容/ })).toHaveFocus();
    expect(view.container.querySelector('input[type="date"]')).toBeNull();
    fireEvent.click(screen.getAllByRole("button", { name: "一覧へ戻る" })[0]);
    expect(props.onReset).toHaveBeenCalledOnce();
    expect(trigger).toHaveFocus();
  });

  it("打刻漏れには文字付き修正ボタンを表示し、件数の意味を明示する", () => {
    const props = createProps();
    props.attendanceDaily = [
      {
        id: null,
        employeeId: 1,
        employeeCode: "E0001",
        employeeName: "確認 職員",
        targetDate: "2026-09-11",
        rawClockInAt: null,
        rawClockOutAt: null,
        clockInAt: null,
        clockOutAt: null,
        workMinutes: null,
        absenceFlag: false,
        specialLeaveFlag: false,
      },
    ];

    render(<DailyEditRequestForm {...props} />);

    expect(screen.getByText("修正申請 0 件")).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: "打刻漏れを修正" })).toHaveLength(2);
  });

  it("取得失敗時は0件の空表示を出さず、一覧だけの再試行を案内する", () => {
    const props = createProps();
    props.attendanceDailyLoadError = "Failed to fetch";

    render(<DailyEditRequestForm {...props} />);

    expect(screen.getByText("件数を確認できません")).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("勤怠の取得に失敗しました。");
    expect(screen.getByRole("button", { name: "勤怠一覧を再試行" })).toBeInTheDocument();
    expect(screen.queryByText("対象月の日次勤怠はありません")).not.toBeInTheDocument();
  });

  it("基本休憩が空欄なら追加休憩だけにエラーを対応付けてフォーカスする", () => {
    const props = createProps();
    const { container } = render(<DailyEditRequestForm {...props} isDailyEditFormOpen values={{ ...values, editAdditionalBreaks: [{ startTime: "12:00", endTime: "" }] }} fieldErrors={{ "breaks.0.endTime": ["休憩終了を入力してください。"] }} />);
    const baseEnd = screen.getByLabelText("休憩終了");
    const additionalEnd = screen.getByLabelText(/^休憩 2 終了/, { selector: 'input[type="time"]' });
    expect(baseEnd).not.toHaveAttribute("name");
    expect(baseEnd).toHaveAttribute("aria-invalid", "false");
    expect(additionalEnd).toHaveAttribute("name", "breaks.0.endTime");
    expect(additionalEnd).toHaveAttribute("aria-invalid", "true");
    expect(additionalEnd).toHaveAttribute("aria-describedby", "error-breaks.0.endTime");
    expect(additionalEnd).toHaveFocus();
    expect(container.querySelectorAll('[name="breaks.0.endTime"]')).toHaveLength(1);
    expect(container.querySelectorAll('[id="error-breaks.0.endTime"]')).toHaveLength(1);
  });
});
