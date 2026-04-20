import { useState } from "react";

export function useLeaveAdminState() {
  const [decisionComment, setDecisionComment] = useState("");
  const [decisionResult, setDecisionResult] = useState("");
  const [grantEmployeeId, setGrantEmployeeId] = useState("1");
  const [grantDays, setGrantDays] = useState("10");
  const [grantDate, setGrantDate] = useState("2026-04-01");
  const [grantExpiresOn, setGrantExpiresOn] = useState("2027-03-31");
  const [grantNote, setGrantNote] = useState("");
  const [adjustType, setAdjustType] = useState<"ADJUST_PLUS" | "ADJUST_MINUS">("ADJUST_PLUS");
  const [adjustDays, setAdjustDays] = useState("1");
  const [adjustDate, setAdjustDate] = useState("2026-04-01");
  const [adjustNote, setAdjustNote] = useState("");
  const [leaveAdminResult, setLeaveAdminResult] = useState("");
  const [workProcedureStatus, setWorkProcedureStatus] = useState("PENDING");
  const [workProcedureEmployeeCode, setWorkProcedureEmployeeCode] = useState("");
  const [workProcedureDepartmentName, setWorkProcedureDepartmentName] = useState("");
  const [workProcedureLeaveTypeCode, setWorkProcedureLeaveTypeCode] = useState("");
  const [workProcedureRequestCategory, setWorkProcedureRequestCategory] = useState("");
  const [workProcedureTimeLeaveType, setWorkProcedureTimeLeaveType] = useState("");
  const [workProcedureFrom, setWorkProcedureFrom] = useState("2026-03-01");
  const [workProcedureTo, setWorkProcedureTo] = useState("2026-03-31");

  return {
    decisionComment,
    setDecisionComment,
    decisionResult,
    setDecisionResult,
    grantEmployeeId,
    setGrantEmployeeId,
    grantDays,
    setGrantDays,
    grantDate,
    setGrantDate,
    grantExpiresOn,
    setGrantExpiresOn,
    grantNote,
    setGrantNote,
    adjustType,
    setAdjustType,
    adjustDays,
    setAdjustDays,
    adjustDate,
    setAdjustDate,
    adjustNote,
    setAdjustNote,
    leaveAdminResult,
    setLeaveAdminResult,
    workProcedureStatus,
    setWorkProcedureStatus,
    workProcedureEmployeeCode,
    setWorkProcedureEmployeeCode,
    workProcedureDepartmentName,
    setWorkProcedureDepartmentName,
    workProcedureLeaveTypeCode,
    setWorkProcedureLeaveTypeCode,
    workProcedureRequestCategory,
    setWorkProcedureRequestCategory,
    workProcedureTimeLeaveType,
    setWorkProcedureTimeLeaveType,
    workProcedureFrom,
    setWorkProcedureFrom,
    workProcedureTo,
    setWorkProcedureTo,
  };
}
