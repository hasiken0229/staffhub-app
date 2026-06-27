import type { Dispatch, SetStateAction } from "react";
import {
  approveAttendanceDaily,
  approveAttendanceDailyEditRequest,
  bulkApproveAttendanceDaily,
  bulkReturnAttendanceDaily,
  loadAttendanceApprovals,
  loadAttendanceDailyEditRequests,
  loadAttendanceDailyGrid,
  loadAttendanceErrors,
  loadAttendanceMonthClosePrecheck,
  loadAttendanceEvents,
  loadAttendanceBreakRule,
  loadAttendanceMonthCloseStatus,
  loadAttendanceMonthlyClose,
  loadAttendanceShiftSchedules,
  loadEmployeeAttendanceSettings,
  resolveAttendanceError,
  returnAttendanceDaily,
  returnAttendanceDailyEditRequest,
  updateAttendanceMonthlyClose,
} from "@/lib/api";
import { currentMonthEndValue, currentMonthStartValue, currentMonthValue } from "@/lib/date-defaults";
import type { DashboardData } from "@/types";

type UseAttendanceActionsParams = {
  dashboard: DashboardData;
  attendanceDecisionComment: string;
  attendanceFilterMonth: string;
  attendanceFilterEmployeeCode: string;
  attendanceFilterDepartmentName: string;
  attendanceApprovalStatus: string;
  attendanceEventFrom: string;
  attendanceEventTo: string;
  attendanceErrorCode: string;
  attendanceErrorHandlingStatus: string;
  attendanceMonthCloseApprovalStatus: string;
  attendanceMonthCloseStatusFilter: string;
  setAttendanceDecisionResult: (value: string) => void;
  setAttendanceCloseResult: (value: string) => void;
  setAttendanceFilterMonth: (value: string) => void;
  setAttendanceFilterEmployeeCode: (value: string) => void;
  setAttendanceFilterDepartmentName: (value: string) => void;
  setAttendanceApprovalStatus: (value: string) => void;
  setAttendanceEventFrom: (value: string) => void;
  setAttendanceEventTo: (value: string) => void;
  setAttendanceErrorCode: (value: string) => void;
  setAttendanceErrorHandlingStatus: (value: string) => void;
  setAttendanceMonthCloseApprovalStatus: (value: string) => void;
  setAttendanceMonthCloseStatusFilter: (value: string) => void;
  setDashboard: Dispatch<SetStateAction<DashboardData>>;
  setErrorMessage: (value: string) => void;
  onRefresh: () => Promise<void>;
};

export function useAttendanceActions(params: UseAttendanceActionsParams) {
  async function handleAttendanceDecision(id: number, decision: "approve" | "return") {
    try {
      const result =
        decision === "approve"
          ? await approveAttendanceDaily(id, params.attendanceDecisionComment)
          : await returnAttendanceDaily(id, params.attendanceDecisionComment);
      params.setAttendanceDecisionResult(`日次勤怠 ${result.id} を ${result.status} に更新しました。`);
      await params.onRefresh();
    } catch (error) {
      params.setAttendanceDecisionResult(error instanceof Error ? error.message : "日次勤怠の更新に失敗しました。");
    }
  }

  async function handleBulkAttendanceDecision(decision: "approve" | "return") {
    const ids = params.dashboard.attendanceApprovals.map((row) => row.id).filter((id): id is number => typeof id === "number");
    if (ids.length === 0) {
      params.setAttendanceDecisionResult("一括処理の対象がありません。");
      return;
    }

    try {
      const result =
        decision === "approve"
          ? await bulkApproveAttendanceDaily(ids, params.attendanceDecisionComment)
          : await bulkReturnAttendanceDaily(ids, params.attendanceDecisionComment);
      params.setAttendanceDecisionResult(`${result.updatedCount} 件の日次勤怠を更新しました。`);
      await params.onRefresh();
    } catch (error) {
      params.setAttendanceDecisionResult(error instanceof Error ? error.message : "一括承認に失敗しました。");
    }
  }

  async function applyAttendanceFilters() {
    try {
      const [
        dailyGrid,
        approvals,
        events,
        attendanceMonthlyClose,
        attendanceErrors,
        attendanceMonthCloseStatus,
        attendanceMonthClosePrecheck,
        attendanceDailyEditRequests,
        employeeAttendanceSettings,
        attendanceShiftSchedules,
        attendanceBreakRule,
      ] = await Promise.all([
        loadAttendanceDailyGrid({
          targetMonth: params.attendanceFilterMonth,
          employeeCode: params.attendanceFilterEmployeeCode || undefined,
          departmentName: params.attendanceFilterDepartmentName || undefined,
        }),
        loadAttendanceApprovals({
          status: params.attendanceApprovalStatus || undefined,
          from: params.attendanceEventFrom || undefined,
          to: params.attendanceEventTo || undefined,
          employeeCode: params.attendanceFilterEmployeeCode || undefined,
          departmentName: params.attendanceFilterDepartmentName || undefined,
        }),
        loadAttendanceEvents({
          from: params.attendanceEventFrom || undefined,
          to: params.attendanceEventTo || undefined,
          employeeCode: params.attendanceFilterEmployeeCode || undefined,
        }),
        loadAttendanceMonthlyClose(params.attendanceFilterMonth),
        loadAttendanceErrors({
          fromMonth: params.attendanceFilterMonth,
          toMonth: params.attendanceFilterMonth,
          errorCode: params.attendanceErrorCode || undefined,
          handlingStatus: params.attendanceErrorHandlingStatus || undefined,
          employeeCode: params.attendanceFilterEmployeeCode || undefined,
          departmentName: params.attendanceFilterDepartmentName || undefined,
          approvalStatus: params.attendanceApprovalStatus || undefined,
        }),
        loadAttendanceMonthCloseStatus({
          targetMonth: params.attendanceFilterMonth,
          employeeCode: params.attendanceFilterEmployeeCode || undefined,
          departmentName: params.attendanceFilterDepartmentName || undefined,
          approvalStatus: params.attendanceMonthCloseApprovalStatus || undefined,
          closeStatus: params.attendanceMonthCloseStatusFilter || undefined,
        }),
        loadAttendanceMonthClosePrecheck(params.attendanceFilterMonth),
        loadAttendanceDailyEditRequests({
          status: "PENDING",
          employeeCode: params.attendanceFilterEmployeeCode || undefined,
          departmentName: params.attendanceFilterDepartmentName || undefined,
          from: params.attendanceEventFrom || undefined,
          to: params.attendanceEventTo || undefined,
        }),
        loadEmployeeAttendanceSettings(),
        loadAttendanceShiftSchedules({ targetMonth: params.attendanceFilterMonth }),
        loadAttendanceBreakRule(),
      ]);

      params.setDashboard((previous) => ({
        ...previous,
        dailyGrid,
        attendanceApprovals: approvals,
        attendance: events,
        attendanceMonthlyClose,
        attendanceErrors,
        attendanceMonthCloseStatus,
        attendanceMonthClosePrecheck,
        attendanceDailyEditRequests,
        employeeAttendanceSettings,
        attendanceShiftSchedules,
        attendanceBreakRule,
      }));
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "日次勤怠の絞り込みに失敗しました。");
    }
  }

  async function resetAttendanceFilters() {
    const month = currentMonthValue();
    params.setAttendanceFilterMonth(month);
    params.setAttendanceFilterEmployeeCode("");
    params.setAttendanceFilterDepartmentName("");
    params.setAttendanceApprovalStatus("PENDING");
    params.setAttendanceEventFrom(currentMonthStartValue());
    params.setAttendanceEventTo(currentMonthEndValue());
    params.setAttendanceErrorCode("");
    params.setAttendanceErrorHandlingStatus("");
    params.setAttendanceMonthCloseApprovalStatus("");
    params.setAttendanceMonthCloseStatusFilter("");

    try {
      const [
        dailyGrid,
        approvals,
        events,
        attendanceMonthlyClose,
        attendanceErrors,
        attendanceMonthCloseStatus,
        attendanceMonthClosePrecheck,
        attendanceDailyEditRequests,
        employeeAttendanceSettings,
        attendanceShiftSchedules,
        attendanceBreakRule,
      ] = await Promise.all([
        loadAttendanceDailyGrid({ targetMonth: month }),
        loadAttendanceApprovals({ status: "PENDING" }),
        loadAttendanceEvents({}),
        loadAttendanceMonthlyClose(month),
        loadAttendanceErrors({ fromMonth: month, toMonth: month }),
        loadAttendanceMonthCloseStatus({ targetMonth: month }),
        loadAttendanceMonthClosePrecheck(month),
        loadAttendanceDailyEditRequests({ status: "PENDING" }),
        loadEmployeeAttendanceSettings(),
        loadAttendanceShiftSchedules({ targetMonth: month }),
        loadAttendanceBreakRule(),
      ]);

      params.setDashboard((previous) => ({
        ...previous,
        dailyGrid,
        attendanceApprovals: approvals,
        attendance: events,
        attendanceMonthlyClose,
        attendanceErrors,
        attendanceMonthCloseStatus,
        attendanceMonthClosePrecheck,
        attendanceDailyEditRequests,
        employeeAttendanceSettings,
        attendanceShiftSchedules,
        attendanceBreakRule,
      }));
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "日次勤怠の再読込に失敗しました。");
    }
  }

  async function handleAttendanceErrorStatus(row: { employeeId: number; targetDate: string; errorCode: string }, status: "OPEN" | "IN_PROGRESS" | "RESOLVED" | "IGNORED") {
    try {
      await resolveAttendanceError({ ...row, status, comment: params.attendanceDecisionComment || undefined });
      await applyAttendanceFilters();
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "勤怠エラーの対応状況更新に失敗しました。");
    }
  }

  async function handleAttendanceMonthClose(status: "OPEN" | "CLOSED") {
    try {
      const summary = await updateAttendanceMonthlyClose({
        targetMonth: params.attendanceFilterMonth,
        status,
      });

      params.setDashboard((previous) => ({
        ...previous,
        attendanceMonthlyClose: summary,
        dailyGrid: previous.dailyGrid.map((row) =>
          row.targetDate.startsWith(params.attendanceFilterMonth) ? { ...row, closeStatus: summary.status } : row,
        ),
      }));
      params.setAttendanceCloseResult(
        status === "CLOSED"
          ? `${params.attendanceFilterMonth} を締めました。`
          : `${params.attendanceFilterMonth} の締めを解除しました。`,
      );
      await applyAttendanceFilters();
    } catch (error) {
      params.setAttendanceCloseResult(error instanceof Error ? error.message : "月締め更新に失敗しました。");
    }
  }

  async function handleAttendanceDailyEditRequestDecision(id: number, decision: "approve" | "return") {
    try {
      const result =
        decision === "approve"
          ? await approveAttendanceDailyEditRequest(id, params.attendanceDecisionComment)
          : await returnAttendanceDailyEditRequest(id, params.attendanceDecisionComment);
      params.setAttendanceDecisionResult(`日次修正申請 ${result.id} を ${result.status} に更新しました。`);
      await applyAttendanceFilters();
    } catch (error) {
      params.setAttendanceDecisionResult(error instanceof Error ? error.message : "日次修正申請の更新に失敗しました。");
    }
  }

  return {
    applyAttendanceFilters,
    handleAttendanceDecision,
    handleAttendanceDailyEditRequestDecision,
    handleAttendanceMonthClose,
    handleAttendanceErrorStatus,
    handleBulkAttendanceDecision,
    resetAttendanceFilters,
  };
}
