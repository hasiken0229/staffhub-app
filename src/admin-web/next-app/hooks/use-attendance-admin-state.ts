import { useEffect, useState } from "react";
import { currentMonthValue, monthDateRangeValue } from "@/lib/date-defaults";

export function useAttendanceAdminState() {
  const stored = loadAttendanceFilters();
  const initialMonth = stored.month ?? currentMonthValue();
  const initialRange = monthDateRangeValue(initialMonth);
  const storedDateRangeMatchesMonth = stored.eventFrom?.startsWith(initialMonth) && stored.eventTo?.startsWith(initialMonth);
  const [attendanceDecisionComment, setAttendanceDecisionComment] = useState("");
  const [attendanceDecisionResult, setAttendanceDecisionResult] = useState("");
  const [attendanceCloseResult, setAttendanceCloseResult] = useState("");
  const [attendanceFilterMonth, setAttendanceFilterMonth] = useState(initialMonth);
  const [attendanceFilterEmployeeCode, setAttendanceFilterEmployeeCode] = useState(stored.employeeCode ?? "");
  const [attendanceFilterDepartmentName, setAttendanceFilterDepartmentName] = useState(stored.departmentName ?? "");
  const [attendanceApprovalStatus, setAttendanceApprovalStatus] = useState(stored.approvalStatus ?? "PENDING");
  const [attendanceEventFrom, setAttendanceEventFrom] = useState(storedDateRangeMatchesMonth ? stored.eventFrom! : initialRange.from);
  const [attendanceEventTo, setAttendanceEventTo] = useState(storedDateRangeMatchesMonth ? stored.eventTo! : initialRange.to);
  const [attendanceErrorCode, setAttendanceErrorCode] = useState(stored.errorCode ?? "");
  const [attendanceErrorHandlingStatus, setAttendanceErrorHandlingStatus] = useState(stored.errorHandlingStatus ?? "");
  const [attendanceMonthCloseApprovalStatus, setAttendanceMonthCloseApprovalStatus] = useState(stored.monthCloseApprovalStatus ?? "");
  const [attendanceMonthCloseStatusFilter, setAttendanceMonthCloseStatusFilter] = useState(stored.monthCloseStatus ?? "");

  useEffect(() => {
    window.sessionStorage.setItem("staffhub.attendanceFilters", JSON.stringify({
      month: attendanceFilterMonth,
      employeeCode: attendanceFilterEmployeeCode,
      departmentName: attendanceFilterDepartmentName,
      approvalStatus: attendanceApprovalStatus,
      eventFrom: attendanceEventFrom,
      eventTo: attendanceEventTo,
      errorCode: attendanceErrorCode,
      errorHandlingStatus: attendanceErrorHandlingStatus,
      monthCloseApprovalStatus: attendanceMonthCloseApprovalStatus,
      monthCloseStatus: attendanceMonthCloseStatusFilter,
    }));
  }, [attendanceFilterMonth, attendanceFilterEmployeeCode, attendanceFilterDepartmentName, attendanceApprovalStatus, attendanceEventFrom, attendanceEventTo, attendanceErrorCode, attendanceErrorHandlingStatus, attendanceMonthCloseApprovalStatus, attendanceMonthCloseStatusFilter]);

  return {
    attendanceDecisionComment,
    setAttendanceDecisionComment,
    attendanceDecisionResult,
    setAttendanceDecisionResult,
    attendanceCloseResult,
    setAttendanceCloseResult,
    attendanceFilterMonth,
    setAttendanceFilterMonth,
    attendanceFilterEmployeeCode,
    setAttendanceFilterEmployeeCode,
    attendanceFilterDepartmentName,
    setAttendanceFilterDepartmentName,
    attendanceApprovalStatus,
    setAttendanceApprovalStatus,
    attendanceEventFrom,
    setAttendanceEventFrom,
    attendanceEventTo,
    setAttendanceEventTo,
    attendanceErrorCode,
    setAttendanceErrorCode,
    attendanceErrorHandlingStatus,
    setAttendanceErrorHandlingStatus,
    attendanceMonthCloseApprovalStatus,
    setAttendanceMonthCloseApprovalStatus,
    attendanceMonthCloseStatusFilter,
    setAttendanceMonthCloseStatusFilter,
  };
}

type AttendanceFilters = Partial<{
  month: string; employeeCode: string; departmentName: string; approvalStatus: string; eventFrom: string; eventTo: string;
  errorCode: string; errorHandlingStatus: string; monthCloseApprovalStatus: string; monthCloseStatus: string;
}>;

function loadAttendanceFilters(): AttendanceFilters {
  if (typeof window === "undefined") return {};
  try { return JSON.parse(window.sessionStorage.getItem("staffhub.attendanceFilters") ?? "{}") as AttendanceFilters; } catch { return {}; }
}
