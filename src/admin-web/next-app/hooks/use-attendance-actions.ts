import { useRef, type Dispatch, type SetStateAction } from "react";
import { attendanceDecisionAvailable } from "@/lib/attendance-utils";
import {
  loadAdminSectionData,
  type AttendanceSectionLoadOptions,
  approveAttendanceDaily,
  approveAttendanceDailyEditRequest,
  bulkApproveAttendanceDaily,
  bulkReturnAttendanceDaily,
  resolveAttendanceError,
  returnAttendanceDaily,
  returnAttendanceDailyEditRequest,
  updateAttendanceMonthlyClose,
} from "@/lib/api";
import { currentMonthEndValue, currentMonthStartValue, currentMonthValue } from "@/lib/date-defaults";
import { hasAvailableMonthClosePrecheck } from "@/lib/attendance-utils";
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
  attendanceMonthDataFresh: boolean;
  canCloseAttendanceMonth: boolean;
  setAttendanceIsLoading: (value: boolean) => void;
  setAttendanceLoadError: (value: string) => void;
  setAttendancePrecheckReadyMonth: (value: string | null) => void;
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
  onLoadAttendance?: (options: AttendanceSectionLoadOptions) => Promise<void>;
};

export function useAttendanceActions(params: UseAttendanceActionsParams) {
  const loadSequence = useRef(0);
  async function handleAttendanceDecision(id: number, decision: "approve" | "return") {
    try {
      const result =
        decision === "approve"
          ? await approveAttendanceDaily(id, params.attendanceDecisionComment)
          : await returnAttendanceDaily(id, params.attendanceDecisionComment);
      params.setAttendanceDecisionResult(`日次勤怠 ${result.id} を ${result.status} に更新しました。`);
      await params.onRefresh().catch(() => { params.setErrorMessage("処理は完了していますが一覧の更新に失敗しました。再処理せず、最新情報を再読込してください。"); });
    } catch (error) {
      params.setAttendanceDecisionResult(error instanceof Error ? error.message : "日次勤怠の更新に失敗しました。");
    }
  }

  async function handleBulkAttendanceDecision(decision: "approve" | "return") {
    if (decision === "approve") {
      params.setAttendanceDecisionResult("一括承認は『一括承認の対象を確認』から候補と除外理由を確認して実行してください。");
      return;
    }
    const ids = params.dashboard.attendanceApprovals.filter(attendanceDecisionAvailable).map((row) => row.id).filter((id): id is number => typeof id === "number");
    if (ids.length === 0) {
      params.setAttendanceDecisionResult("一括処理の対象がありません。");
      return;
    }

    try {
      const result = await bulkReturnAttendanceDaily(ids, params.attendanceDecisionComment);
      params.setAttendanceDecisionResult(`${result.updatedCount} 件の日次勤怠を更新しました。${result.errorCount ? ` 未完了 ${result.errorCount}件：${result.errors?.map((item) => `ID ${item.id}：${item.message}`).join(" ／ ")}` : ""}`);
      await params.onRefresh().catch(() => { params.setErrorMessage("処理は完了していますが一覧の更新に失敗しました。再処理せず、最新情報を再読込してください。"); });
    } catch (error) {
      params.setAttendanceDecisionResult(error instanceof Error ? error.message : "一括承認に失敗しました。");
    }
  }

  async function reloadAttendance(options: AttendanceSectionLoadOptions) {
    if (params.onLoadAttendance) { await params.onLoadAttendance(options); return; }
    const sequence = ++loadSequence.current;
    params.setAttendancePrecheckReadyMonth(null);
    params.setAttendanceIsLoading(true);
    params.setAttendanceLoadError("");
    try {
      const data = await loadAdminSectionData("attendance", options);
      if (sequence !== loadSequence.current) return;
      params.setDashboard((previous) => ({ ...previous, ...data }));
      params.setErrorMessage("");
      if (options.targetMonth && hasAvailableMonthClosePrecheck(data.attendanceMonthClosePrecheck, options.targetMonth)) {
        params.setAttendancePrecheckReadyMonth(options.targetMonth);
      }
    } catch (error) {
      if (sequence !== loadSequence.current) return;
      const message = error instanceof Error ? error.message : "日次勤怠の取得に失敗しました。";
      params.setErrorMessage(message);
      params.setAttendanceLoadError(message);
    } finally {
      if (sequence === loadSequence.current) params.setAttendanceIsLoading(false);
    }
  }

  async function applyAttendanceFilters() {
    await reloadAttendance({
      targetMonth: params.attendanceFilterMonth,
      employeeCode: params.attendanceFilterEmployeeCode,
      departmentName: params.attendanceFilterDepartmentName,
      approvalStatus: params.attendanceApprovalStatus,
      eventFrom: params.attendanceEventFrom,
      eventTo: params.attendanceEventTo,
      errorCode: params.attendanceErrorCode,
      errorHandlingStatus: params.attendanceErrorHandlingStatus,
      monthCloseApprovalStatus: params.attendanceMonthCloseApprovalStatus,
      monthCloseStatus: params.attendanceMonthCloseStatusFilter,
    });
  }

  async function resetAttendanceFilters() {
    const targetMonth = currentMonthValue();
    const eventFrom = currentMonthStartValue();
    const eventTo = currentMonthEndValue();
    params.setAttendanceFilterMonth(targetMonth);
    params.setAttendanceFilterEmployeeCode("");
    params.setAttendanceFilterDepartmentName("");
    params.setAttendanceApprovalStatus("PENDING");
    params.setAttendanceEventFrom(eventFrom);
    params.setAttendanceEventTo(eventTo);
    params.setAttendanceErrorCode("");
    params.setAttendanceErrorHandlingStatus("");
    params.setAttendanceMonthCloseApprovalStatus("");
    params.setAttendanceMonthCloseStatusFilter("");
    await reloadAttendance({ targetMonth, eventFrom, eventTo, approvalStatus: "PENDING" });
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
    if (!params.attendanceMonthDataFresh || (status === "CLOSED" && !params.canCloseAttendanceMonth)) {
      params.setAttendanceCloseResult("対象月の勤怠と月締前チェックを再取得し、締め可能であることを確認してから操作してください。");
      return;
    }

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
