import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { expect, it, vi } from "vitest";
import { AttendanceDailyEditor } from "@/components/dashboard-sections/attendance/attendance-daily-editor";
import { emptyData } from "@/lib/dashboard-defaults";
import type { AttendanceDailyDetail } from "@/types/attendance";

it("explains admin-only overnight correction next to the explicit next-day flag without moving or saving a punch",async()=>{
  const setClock=vi.fn();const save=vi.fn();
  render(<AttendanceDailyEditor editingDaily={{id:1,employeeId:1,employeeCode:"TEST",employeeName:"合成職員",targetDate:"2026-09-02",clockInAt:"2026-09-02T22:00:00+09:00",clockOutAt:"2026-09-02T06:00:00+09:00",closeStatus:"OPEN",approvalStatus:"PENDING",absenceFlag:false,specialLeaveFlag:false,breaks:[]} as AttendanceDailyDetail}
    editingBreaks={[]} historyRows={[]} activeEditTab="edit" editError="" editMessage="" dashboard={emptyData}
    formatters={{formatDateOnly:v=>v??"-",formatDateTime:v=>v??"-",formatTimeOnly:v=>v??"-"}}
    onClose={vi.fn()} onSave={save} onResetManualEdit={vi.fn()} onApplyBreakOvertime={vi.fn()} onActiveEditTabChange={vi.fn()}
    setEditingDaily={vi.fn()} setEditingBreaks={vi.fn()} setEditingClock={setClock}/>);
  const flag=screen.getByRole("checkbox",{name:"退勤は翌日"});
  expect(flag).toHaveAccessibleDescription(/管理者が勤務開始日の日次/);
  expect(flag).toHaveAccessibleDescription(/原打刻を残して別途確認/);
  expect(flag).toHaveAccessibleDescription(/先に月締めを解除/);
  expect(setClock).not.toHaveBeenCalled();
  expect(save).not.toHaveBeenCalled();
  await userEvent.setup().click(flag);
  expect(setClock).toHaveBeenCalledExactlyOnceWith("clockOutAt",expect.any(String),true);
  expect(save).not.toHaveBeenCalled();
});
