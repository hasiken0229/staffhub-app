import { describe, expect, it } from "vitest";
import { buildDailyUpdatePayload, normalizeBreaks } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import type { AttendanceDailyDetail } from "@/types/attendance";

const original = {id:1, employeeId:1,employeeCode:"T1",employeeName:"職員",targetDate:"2026-10-02",clockInAt:"2026-10-02T22:00:40+09:00",clockOutAt:"2026-10-03T06:00:20+09:00",remark:"元の備考",isManuallyEdited:false,breaks:[{startTime:"02:00",endTime:"02:45",startNextDay:true,endNextDay:true}]} as AttendanceDailyDetail;
describe("daily edit changed fields",()=>{
  it("sends only the comment without truncating seconds or changing next day flags",()=>{
    expect(buildDailyUpdatePayload(original,{...original,remark:"確認済み"},normalizeBreaks(original.breaks))).toEqual({remark:"確認済み"});
  });
  it("keeps unchanged values out of the request",()=>{
    expect(buildDailyUpdatePayload(original,original,normalizeBreaks(original.breaks))).toEqual({});
  });
  it("never writes effective statuses and can explicitly approve a reviewed record",()=>{
    const initial = {...original, approvalStatus: "NOT_REQUIRED"};
    expect(buildDailyUpdatePayload(initial,{...initial,remark:"変更"},initial.breaks!)).toEqual({remark:"変更"});
    expect(buildDailyUpdatePayload(initial,{...initial,approvalStatus:"NOT_DUE"},initial.breaks!)).toEqual({});
    expect(buildDailyUpdatePayload(initial,{...initial,approvalStatus:"APPROVED"},initial.breaks!)).toEqual({approvalStatus:"APPROVED"});
  });
  it("sends the next-day setting only with an explicitly changed clock",()=>{
    expect(buildDailyUpdatePayload(original,{...original,clockOutAt:"2026-10-03T07:00:00+09:00"},normalizeBreaks(original.breaks))).toEqual({clockOutTime:"07:00",clockOutNextDay:true});
  });
});
