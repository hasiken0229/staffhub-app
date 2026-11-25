import { render, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import userEvent from "@testing-library/user-event";
import { AttendanceMonthClosePanel } from "@/components/dashboard-sections/attendance/attendance-close-export-panels";
import { emptyData } from "@/lib/dashboard-defaults";

function createProps() {
  return {
    dashboard: {
      ...emptyData,
      attendanceMonthlyClose: { ...emptyData.attendanceMonthlyClose, targetYearMonth: "2026-06", isAvailable: true },
      attendanceMonthClosePrecheck: { targetYearMonth: "2026-06", canClose: true, blockers: [] },
    },
    targetMonth: "2026-06",
    attendanceIsLoading: false,
    attendanceMonthDataFresh: false,
    attendanceLoadError: "",
    attendanceCloseResult: "",
    formatters: { formatDateTime: () => "-", formatCloseStatus: (value?: string | null) => value ?? "-" },
    onAttendanceMonthClose: vi.fn().mockResolvedValue(undefined),
  };
}

describe("AttendanceMonthClosePanel", () => {
  it("distinguishes calendar days, staff-days and extra warnings, and opens the selected blocker", async () => {
    const onReviewBlocker = vi.fn();
    const props = createProps();
    const { getByRole, getByText, getAllByText } = render(<AttendanceMonthClosePanel {...props} attendanceMonthDataFresh onReviewBlocker={onReviewBlocker} dashboard={{...props.dashboard, attendanceMonthClosePrecheck:{
      targetYearMonth:"2026-06",canClose:false,payrollReady:false,
      blockers:[{code:"UNSUBMITTED_DAILY",label:"未申請（未登録）",count:38,message:"未登録の職員・日付があります。"},{code:"PERIOD_NOT_FINISHED",label:"対象月の未到来日",count:19,message:"対象月の最終日まで待ってください。"}],
      payrollBlockers:[{code:"PENDING_APPROVAL",label:"承認待ち",count:2,message:"承認待ちです。"}],payrollWarnings:[],
    }}}/>);
    expect(getByRole("button",{name:"この月を締める"})).toBeDisabled();
    expect(getAllByText(/未申請（未登録） 38人日/).length).toBeGreaterThan(0);
    expect(getAllByText(/対象月の未到来日 19暦日/).length).toBeGreaterThan(0);
    expect(getByText("確認が必要")).toBeVisible();
    expect(getByText("追加の注意事項なし")).toBeVisible();
    expect(getByText(/追加の注意事項はありません/)).toHaveTextContent("可否は上のチェック結果を確認");
    await userEvent.setup().click(getByRole("button",{name:"未申請（未登録）の職員・日付を確認"}));
    expect(onReviewBlocker).toHaveBeenCalledExactlyOnceWith("UNSUBMITTED_DAILY");
    expect(props.onAttendanceMonthClose).not.toHaveBeenCalled();
  });

  it("does not show default zero counts or enable close before the selected month is confirmed", () => {
    const props = createProps();
    const { container, getByRole, getByText } = render(<AttendanceMonthClosePanel {...props} />);
    const panel = container.querySelector<HTMLElement>("#attendance-close");
    if (!panel) throw new Error("month close panel missing");

    expect(within(panel).getAllByText("未確認")[0]).toBeVisible();
    expect(within(panel).getByText(/日次件数 未取得/)).toBeVisible();
    expect(within(panel).getByRole("button", { name: "この月を締める" })).toBeDisabled();
    expect(panel.textContent).not.toContain("日次件数 0 件");
  });

  it("enables close only after a fresh check for the selected month", () => {
    const props = { ...createProps(), attendanceMonthDataFresh: true };
    const { getByRole } = render(<AttendanceMonthClosePanel {...props} />);
    expect(getByRole("button", { name: "この月を締める" })).toBeEnabled();
  });

  it("keeps close disabled on a load failure even if previous data said it was clear", () => {
    const props = { ...createProps(), attendanceMonthDataFresh: false, attendanceLoadError: "Failed to fetch" };
    const { getByRole, getByText } = render(<AttendanceMonthClosePanel {...props} />);
    expect(getByText("Failed to fetch")).toBeVisible();
    expect(getByRole("button", { name: "この月を締める" })).toBeDisabled();
  });
});
