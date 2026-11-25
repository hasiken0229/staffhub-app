import { act, render, renderHook, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { LeaveRequestForm } from "@/components/dashboard-sections/employee-portal/leave-request-form";
import { useEmployeePortalForms } from "@/components/dashboard-sections/employee-portal/use-employee-portal-forms";
import type { EmployeePortalSectionProps } from "@/components/dashboard-sections/employee-portal/employee-portal-types";
import { emptyEmployeePortal } from "@/lib/dashboard-defaults";

const balances = vi.hoisted(() => ({ days:1, minutes:10 }));
vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  loadEmployeeStandardDayMinutes:vi.fn().mockResolvedValue({standardDayMinutes:480}),
}));
vi.mock("@/lib/api/employee-balance", () => ({loadEmployeeBalanceAtDate:vi.fn(async (asOf:string, through?:string) => ({
  workdays: through ? {dates: through === asOf ? [asOf] : [asOf,through], excludedDates: [], unconfirmedDates: []} : undefined,
  asOf,currentBalance:balances.days,availableDays:balances.days,futureApprovedDays:0,
  hourlyManagementConfigured:true,hourlyBalance:{effectiveOn:"2026-09-30",availableMinutes:balances.minutes,pendingMinutes:0,expiresOn:"2027-03-31"},
}))}));

function input(): EmployeePortalSectionProps {
  return { data:{ employeePortal:{...emptyEmployeePortal,home:{...emptyEmployeePortal.home,leaveTypes:[{
    code:"PAID",name:"有給休暇",requiresBalance:true,allowsHalfDay:true,sortOrder:1,isActive:true,
  }]}},isPending:false,errorMessage:""},
    actions:{onRefresh:vi.fn(),onLogout:vi.fn(),onLeaveRequestCreate:vi.fn().mockResolvedValue(true),onLeaveRequestCancel:vi.fn(),
      onAttendanceDailyEditRequestCreate:vi.fn(),onLoadEmployeePayrollDetail:vi.fn(),onPayrollDownload:vi.fn(),onNotificationRead:vi.fn()},
    formatters:{formatDateOnly:v=>v??"-",formatDateTime:v=>v??"-",formatMonthDay:v=>v??"-",formatApprovalStatus:v=>v??"-",formatLeaveLedgerEntryType:v=>v??"-"},
  };
}

describe("休暇期間の残高表示",()=>{
  beforeEach(()=>{balances.days=1;balances.minutes=10;});

  it("複数日申請を開始日残高だけで禁止せず、フォームと確認文では期間の残高を再確認と表示する",async()=>{
    const props=input();
    const hook=renderHook(()=>useEmployeePortalForms(props));
    act(()=>{hook.result.current.leaveRequestFormProps.onStartDateChange("2026-10-31");hook.result.current.leaveRequestFormProps.onEndDateChange("2026-11-01");});
    await waitFor(()=>expect(hook.result.current.leaveRequestFormProps.isBalanceLoading).toBe(false));
    const form=render(<LeaveRequestForm {...hook.result.current.leaveRequestFormProps}/>);
    expect(screen.getByRole("button",{name:"この内容で申請する"})).toBeEnabled();
    expect(screen.getByText("開始日時点の申請可能日数")).toBeInTheDocument();
    expect(screen.getByText("送信時に確認")).toBeInTheDocument();
    expect(screen.getByText(/期間中の付与・失効と後日の予約を含めて送信時に再確認/)).toBeInTheDocument();
    expect(screen.queryByText("-1日")).not.toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole("button",{name:"この内容で申請する"}));
    expect(hook.result.current.confirmationDialogProps.message).toContain("期間中の付与・失効と後日の予約を含めて送信時に再確認");
    expect(hook.result.current.confirmationDialogProps.message).not.toContain("-1.00日");
    await act(async()=>hook.result.current.confirmationDialogProps.onConfirm());
    expect(props.actions.onLeaveRequestCreate).toHaveBeenCalledWith(expect.objectContaining({startDate:"2026-10-31",endDate:"2026-11-01"}));
    form.unmount();
  });

  it("単日の有給不足と時間有給不足では送信停止を維持する",async()=>{
    balances.days=0.5;
    const props=input();
    const hook=renderHook(()=>useEmployeePortalForms(props));
    act(()=>{hook.result.current.leaveRequestFormProps.onStartDateChange("2026-10-31");hook.result.current.leaveRequestFormProps.onEndDateChange("2026-10-31");});
    await waitFor(()=>expect(hook.result.current.leaveRequestFormProps.isBalanceLoading).toBe(false));
    const form=render(<LeaveRequestForm {...hook.result.current.leaveRequestFormProps}/>);
    expect(screen.getByRole("button",{name:"この内容で申請する"})).toBeDisabled();
    act(()=>{hook.result.current.leaveRequestFormProps.onRequestCategoryChange("TIME_LEAVE");hook.result.current.leaveRequestFormProps.onTargetDateChange("2026-10-31");});
    await waitFor(()=>expect(hook.result.current.leaveRequestFormProps.isBalanceLoading).toBe(false));
    form.rerender(<LeaveRequestForm {...hook.result.current.leaveRequestFormProps}/>);
    expect(hook.result.current.leaveRequestFormProps.balancePreview.requestedMinutes).toBe(60);
    expect(screen.getByRole("button",{name:"この内容で申請する"})).toBeDisabled();
  });
});
