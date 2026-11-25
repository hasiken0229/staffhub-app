import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { EmployeePortalSection } from "@/components/dashboard-sections/employee-portal-section";
import type { EmployeePortalSectionProps } from "@/components/dashboard-sections/employee-portal/employee-portal-types";

function createProps(): EmployeePortalSectionProps {
  return {
    data: {
      currentUserName: "確認職員",
      currentMode: "EMPLOYEE",
      canUseEmployeePortal: false,
      errorMessage: "",
      isPending: false,
      employeePortal: {
        home: {
          employee: { id: 1, employeeCode: "E0001", name: "確認職員" },
          pendingLeaveCount: 0,
          paidLeaveBalance: 10,
          unreadNotificationCount: 0,
          leaveTypes: [],
          latestPayroll: null,
        },
        leaveRequests: [],
        attendanceDaily: [],
        attendanceDailyEditRequests: [],
        payroll: [],
        notifications: [],
        leaveLedger: [],
      },
    },
    actions: {
      onRefresh: vi.fn(),
      onLogout: vi.fn(),
      onLeaveRequestCreate: vi.fn().mockResolvedValue(undefined),
      onLeaveRequestCancel: vi.fn().mockResolvedValue(undefined),
      onAttendanceDailyEditRequestCreate: vi.fn().mockResolvedValue(undefined),
      onLoadEmployeePayrollDetail: vi.fn().mockResolvedValue(undefined),
      onPayrollDownload: vi.fn().mockResolvedValue(undefined),
      onNotificationRead: vi.fn().mockResolvedValue(undefined),
    },
    formatters: {
      formatDateOnly: (value) => value ?? "-",
      formatDateTime: (value) => value ?? "-",
      formatMonthDay: (value) => value ?? "-",
      formatApprovalStatus: (value) => value ?? "-",
      formatLeaveLedgerEntryType: (value) => value ?? "-",
    },
  };
}

describe("EmployeePortalSection", () => {
  it("職員メニューから通知一覧へ切り替えられる", async () => {
    const user = userEvent.setup();
    render(<EmployeePortalSection {...createProps()} />);

    expect(screen.getByRole("region", { name: "職員ホーム" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /^通知$/ }));

    expect(screen.getByRole("region", { name: "通知" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^通知$/ })).toHaveAttribute("aria-current", "page");
  });

  it("ホームを当日の確認に絞り、給与メニューから支給日未登録のPC・モバイル一覧を開ける", async () => {
    const user = userEvent.setup();
    const props = createProps();
    props.data.employeePortal.payroll = [{
      id: 3, employeeId: 1, originalFileName: "test.pdf", statementTypeLabel: "給与明細",
      targetYearMonth: "2026-05", payDate: null, publishedAt: "2026-06-05",
    }];
    render(<EmployeePortalSection {...props} />);

    await user.click(screen.getByRole("button", { name: "ホーム" }));
    expect(screen.queryByRole("button", { name: /最新の給与明細/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "給与明細" }));
    expect(screen.getByRole("cell", { name: "未登録" })).toBeInTheDocument();
    expect(screen.getByText("支給日 未登録")).toBeInTheDocument();
    expect(screen.getAllByText("2026-06-05")).toHaveLength(2);
  });

  it("休暇申請の取消は確認後にだけ実行される", async () => {
    const user = userEvent.setup();
    const props = createProps();
    props.data.employeePortal.leaveRequests = [
      {
        id: 10,
        leaveTypeName: "年次有給休暇",
        startDate: "2026-07-21",
        endDate: "2026-07-21",
        quantityDays: 1,
        status: "PENDING",
      },
    ];
    props.data.employeePortal.leaveRequests.push({ ...props.data.employeePortal.leaveRequests[0], id: 11, startDate: "2026-08-03", endDate: "2026-08-03" });
    render(<EmployeePortalSection {...props} />);

    await user.click(screen.getByRole("button", { name: /^申請状況$/ }));
    const chosen = screen.getByRole("row", { name: /2026-08-03/ });
    await user.click(within(chosen).getByRole("button", { name: "取消" }));

    const dialog = screen.getByRole("dialog", { name: "休暇申請を取り消しますか？" });
    expect(dialog).toHaveTextContent("2026-08-03");
    expect(dialog).toHaveTextContent("年次有給休暇");
    expect(dialog).not.toHaveTextContent("2026-07-21");
    expect(props.actions.onLeaveRequestCancel).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "申請を取り消す" }));

    await waitFor(() => expect(props.actions.onLeaveRequestCancel).toHaveBeenCalledExactlyOnceWith(11));
  });
});
