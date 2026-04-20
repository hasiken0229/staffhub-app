import { useState } from "react";

export function useAttendanceAdminState() {
  const [attendanceDecisionComment, setAttendanceDecisionComment] = useState("");
  const [attendanceDecisionResult, setAttendanceDecisionResult] = useState("");
  const [attendanceCloseResult, setAttendanceCloseResult] = useState("");
  const [attendanceFilterMonth, setAttendanceFilterMonth] = useState("2026-03");
  const [attendanceFilterEmployeeCode, setAttendanceFilterEmployeeCode] = useState("");
  const [attendanceFilterDepartmentName, setAttendanceFilterDepartmentName] = useState("");
  const [attendanceApprovalStatus, setAttendanceApprovalStatus] = useState("PENDING");
  const [attendanceEventFrom, setAttendanceEventFrom] = useState("2026-03-01");
  const [attendanceEventTo, setAttendanceEventTo] = useState("2026-03-31");
  const [attendanceErrorCode, setAttendanceErrorCode] = useState("");
  const [attendanceErrorHandlingStatus, setAttendanceErrorHandlingStatus] = useState("");
  const [attendanceMonthCloseApprovalStatus, setAttendanceMonthCloseApprovalStatus] = useState("");
  const [attendanceMonthCloseStatusFilter, setAttendanceMonthCloseStatusFilter] = useState("");

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
