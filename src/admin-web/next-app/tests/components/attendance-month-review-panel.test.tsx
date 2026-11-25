import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { AttendanceMonthReviewPanel } from "@/components/dashboard-sections/attendance/attendance-month-review-panel";
import { approveAttendanceMonthCandidates, loadAttendanceMonthCloseStatus } from "@/lib/api/attendance";
import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";

vi.mock("@/lib/api/attendance",()=>({
  loadAttendanceApprovalPreview:vi.fn().mockResolvedValue({targetMonth:"2026-09",eligibleCount:1,excludedCount:1,items:[
    {daily:{id:1,employeeId:1,employeeCode:"T1",employeeName:"確認職員",targetDate:"2026-09-02",clockInAt:"09:00",clockOutAt:"18:00",breakMinutes:60,workMinutes:480},eligible:true,reasons:[],fingerprint:"a".repeat(64)},
    {daily:{id:2,employeeId:2,employeeCode:"T2",employeeName:"補完職員",targetDate:"2026-09-03",clockInAt:"09:00",clockOutAt:"18:00",breakMinutes:60,workMinutes:480},eligible:false,reasons:["予定補完のため個別確認が必要"],fingerprint:"b".repeat(64)},
  ]}),
  loadAttendanceMonthCloseStatus:vi.fn().mockResolvedValue([]),
  approveAttendanceMonthCandidates:vi.fn().mockResolvedValue({updatedCount:1,unchangedCount:0,skippedCount:0,errorCount:0,errors:[]}),
}));

const formatters={formatDateOnly:(v?:string|null)=>v??"-",formatTimeOnly:(v?:string|null)=>v??"-",formatWorkMinutes:(v?:number|null)=>v===null||v===undefined?"-":`${v}分`,formatApprovalStatus:(v?:string|null)=>v??"-"} as AttendanceSectionProps["formatters"];

describe("month approval review",()=>{
  it("opens and focuses blocker details with the matching employee/date filter, including registered future days",async()=>{
    const open = vi.fn().mockResolvedValue(undefined);
    vi.mocked(loadAttendanceMonthCloseStatus).mockResolvedValueOnce([{
      employee:{id:1,employeeCode:"T1",name:"確認職員",departmentName:null,locationName:null,employmentType:"FULL_TIME"},
      targetYearMonth:"2026-09",unsubmittedCount:2,pendingCount:1,returnedCount:0,approvedCount:1,dailyCount:2,closeStatus:"OPEN",closedAt:null,closedByName:null,
      missingBreakdown:{workday:1,holiday:0,future:1,today:0},
      dayDetails:[
        {targetDate:"2026-09-01",dailyId:null,state:"WORKDAY_UNREGISTERED",approvalStatus:null},
        {targetDate:"2026-09-25",dailyId:null,state:"FUTURE_UNREGISTERED",approvalStatus:null},
        {targetDate:"2026-09-26",dailyId:26,state:"FUTURE",approvalStatus:"APPROVED"},
        {targetDate:"2026-09-02",dailyId:2,state:"COMPLETE",approvalStatus:"PENDING"},
      ],
    }]);
    const props={targetMonth:"2026-09",formatters,onRefresh:vi.fn(),onOpenDaily:open};
    const view=render(<AttendanceMonthReviewPanel {...props} reviewRequest={{targetMonth:"2026-09",code:"UNSUBMITTED_DAILY",revision:1}}/>);
    await screen.findByRole("button",{name:"職員・日付別に確認"});
    const panel=screen.getByLabelText("月締めの職員・日付別確認");
    expect(panel).toHaveFocus();
    expect(screen.getByRole("combobox",{name:"確認する状況"})).toHaveValue("UNREGISTERED");
    expect(within(panel).getByText("2026-09-01")).toBeVisible();
    expect(within(panel).getByText("2026-09-25")).toBeVisible();
    expect(within(panel).queryByText("2026-09-02")).not.toBeInTheDocument();
    view.rerender(<AttendanceMonthReviewPanel {...props} reviewRequest={{targetMonth:"2026-09",code:"PERIOD_NOT_FINISHED",revision:2}}/>);
    expect(screen.getByRole("combobox",{name:"確認する状況"})).toHaveValue("FUTURE_ALL");
    expect(within(panel).getByText("2026-09-25")).toBeVisible();
    expect(within(panel).getByText("2026-09-26")).toBeVisible();
    expect(within(panel).queryByText("2026-09-01")).not.toBeInTheDocument();
    view.rerender(<AttendanceMonthReviewPanel {...props} reviewRequest={{targetMonth:"2026-09",code:"PENDING_APPROVAL",revision:3}}/>);
    expect(screen.getByRole("combobox",{name:"確認する状況"})).toHaveValue("PENDING");
    expect(within(panel).getByText("2026-09-02")).toBeVisible();
    await userEvent.setup().click(within(panel).getByRole("button",{name:"詳細"}));
    expect(open).toHaveBeenCalledExactlyOnceWith(2);
    view.rerender(<AttendanceMonthReviewPanel {...props} reviewRequest={{targetMonth:"2026-09",code:"OPEN_ERROR",revision:4}}/>);
    expect(screen.getByRole("combobox",{name:"確認する状況"})).toHaveValue("");
    expect(within(panel).getByText(/この一覧だけでは絞り込めない/)).toBeVisible();
    expect(within(panel).getByText("2026-09-01")).toBeVisible();
  });

  it("requests every employee for the unfiltered month review instead of only the default first fifty",async()=>{
    const user=userEvent.setup();
    const rows=Array.from({length:51},(_,index)=>({
      employee:{id:index+1,employeeCode:`T${index+1}`,name:`職員${index+1}`,departmentName:null,locationName:null,employmentType:"FULL_TIME"},
      targetYearMonth:"2026-09",unsubmittedCount:1,pendingCount:0,returnedCount:0,approvedCount:0,dailyCount:0,
      closeStatus:"OPEN" as const,closedAt:null,closedByName:null,
      missingBreakdown:{workday:1,holiday:0,future:0,today:0},
      dayDetails:[{targetDate:"2026-09-01",dailyId:null,state:"WORKDAY_UNREGISTERED",approvalStatus:null}],
    }));
    vi.mocked(loadAttendanceMonthCloseStatus).mockResolvedValueOnce(rows);
    const rendered=render(<AttendanceMonthReviewPanel targetMonth="2026-09" formatters={formatters} onRefresh={vi.fn()} onOpenDaily={vi.fn()}/>);
    await user.click(await screen.findByRole("button",{name:"職員・日付別に確認"}));
    expect(loadAttendanceMonthCloseStatus).toHaveBeenCalledWith({targetMonth:"2026-09",page:1,perPage:10000});
    expect(screen.getByRole("option",{name:"T51 職員51"})).toBeInTheDocument();
    rendered.unmount();
  });
  it("selects only eligible rows and submits frozen ids after confirmation without closing the month",async()=>{
    const user=userEvent.setup();const refresh=vi.fn().mockResolvedValue(undefined);
    render(<AttendanceMonthReviewPanel targetMonth="2026-09" formatters={formatters} onRefresh={refresh} onOpenDaily={vi.fn()}/>);
    await user.click(await screen.findByRole("button",{name:"問題のない勤怠を一括承認（1件）"}));
    const eligible=screen.getByRole("checkbox",{name:"2026-09-02 確認職員を承認対象にする"});
    const excluded=screen.getByRole("checkbox",{name:"2026-09-03 補完職員を承認対象にする"});
    expect(eligible).toBeChecked();expect(excluded).not.toBeChecked();expect(excluded).toBeDisabled();
    expect(screen.getByText("予定補完のため個別確認が必要")).toBeInTheDocument();
    await user.click(screen.getByRole("button",{name:"選択した 1件を承認"}));
    expect(approveAttendanceMonthCandidates).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button",{name:"1件を承認"}));
    await waitFor(()=>expect(approveAttendanceMonthCandidates).toHaveBeenCalledExactlyOnceWith("2026-09",[{id:1,fingerprint:"a".repeat(64)}],""));
    expect(await screen.findByText(/月締めはまだ行っていません/)).toBeInTheDocument();
    await waitFor(()=>expect(refresh).toHaveBeenCalledOnce());
  });
});
