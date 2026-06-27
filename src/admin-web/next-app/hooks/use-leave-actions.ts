import type { Dispatch, SetStateAction } from "react";
import {
  adjustPaidLeave,
  approveWorkProcedure,
  bulkApproveWorkProcedures,
  bulkReturnWorkProcedures,
  grantPaidLeave,
  loadWorkProcedures,
  returnWorkProcedure,
} from "@/lib/api";
import { currentMonthEndValue, currentMonthStartValue } from "@/lib/date-defaults";
import type { DashboardData } from "@/types";

type UseLeaveActionsParams = {
  dashboard: DashboardData;
  decisionComment: string;
  grantEmployeeId: string;
  grantDays: string;
  grantDate: string;
  grantExpiresOn: string;
  grantNote: string;
  adjustType: "ADJUST_PLUS" | "ADJUST_MINUS";
  adjustDays: string;
  adjustDate: string;
  adjustNote: string;
  workProcedureStatus: string;
  workProcedureEmployeeCode: string;
  workProcedureDepartmentName: string;
  workProcedureLeaveTypeCode: string;
  workProcedureRequestCategory: string;
  workProcedureTimeLeaveType: string;
  workProcedureFrom: string;
  workProcedureTo: string;
  setDecisionResult: (value: string) => void;
  setLeaveAdminResult: (value: string) => void;
  setWorkProcedureStatus: (value: string) => void;
  setWorkProcedureEmployeeCode: (value: string) => void;
  setWorkProcedureDepartmentName: (value: string) => void;
  setWorkProcedureLeaveTypeCode: (value: string) => void;
  setWorkProcedureRequestCategory: (value: string) => void;
  setWorkProcedureTimeLeaveType: (value: string) => void;
  setWorkProcedureFrom: (value: string) => void;
  setWorkProcedureTo: (value: string) => void;
  setDashboard: Dispatch<SetStateAction<DashboardData>>;
  setErrorMessage: (value: string) => void;
  onRefresh: () => Promise<void>;
};

export function useLeaveActions(params: UseLeaveActionsParams) {
  async function handleWorkProcedureDecision(id: number, decision: "approve" | "return") {
    try {
      const result =
        decision === "approve"
          ? await approveWorkProcedure(id, params.decisionComment)
          : await returnWorkProcedure(id, params.decisionComment);
      params.setDecisionResult(`届出 ${result.id} を ${result.status} に更新しました。`);
      await params.onRefresh();
    } catch (error) {
      params.setDecisionResult(error instanceof Error ? error.message : "届出の更新に失敗しました。");
    }
  }

  async function handleBulkWorkProcedureDecision(decision: "approve" | "return", selectedIds?: number[]) {
    const ids =
      selectedIds !== undefined
        ? selectedIds
        : params.dashboard.workProcedures.filter((row) => row.status === "PENDING").map((row) => row.id);
    if (ids.length === 0) {
      params.setDecisionResult("一括処理の対象がありません。");
      return;
    }

    try {
      const result =
        decision === "approve"
          ? await bulkApproveWorkProcedures(ids, params.decisionComment)
          : await bulkReturnWorkProcedures(ids, params.decisionComment);
      params.setDecisionResult(`${result.updatedCount} 件の届出を更新しました。`);
      await params.onRefresh();
    } catch (error) {
      params.setDecisionResult(error instanceof Error ? error.message : "一括承認に失敗しました。");
    }
  }

  async function handleGrantPaidLeave() {
    try {
      await grantPaidLeave({
        employeeId: Number(params.grantEmployeeId),
        days: Number(params.grantDays),
        grantedOn: params.grantDate,
        expiresOn: params.grantExpiresOn || undefined,
        note: params.grantNote || undefined,
      });
      params.setLeaveAdminResult("有給付与を登録しました。");
      await params.onRefresh();
    } catch (error) {
      params.setLeaveAdminResult(error instanceof Error ? error.message : "有給付与に失敗しました。");
    }
  }

  async function handleAdjustPaidLeave() {
    try {
      await adjustPaidLeave({
        employeeId: Number(params.grantEmployeeId),
        adjustmentType: params.adjustType,
        days: Number(params.adjustDays),
        effectiveOn: params.adjustDate,
        note: params.adjustNote || undefined,
      });
      params.setLeaveAdminResult("有給調整を登録しました。");
      await params.onRefresh();
    } catch (error) {
      params.setLeaveAdminResult(error instanceof Error ? error.message : "有給調整に失敗しました。");
    }
  }

  async function applyWorkProcedureFilters() {
    try {
      const workProcedures = await loadWorkProcedures({
        status: params.workProcedureStatus || undefined,
        employeeCode: params.workProcedureEmployeeCode || undefined,
        departmentName: params.workProcedureDepartmentName || undefined,
        leaveTypeCode: params.workProcedureLeaveTypeCode || undefined,
        requestCategory: params.workProcedureRequestCategory || undefined,
        timeLeaveType: params.workProcedureTimeLeaveType || undefined,
        from: params.workProcedureFrom || undefined,
        to: params.workProcedureTo || undefined,
      });

      params.setDashboard((previous) => ({
        ...previous,
        workProcedures,
      }));
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "届出一覧の絞り込みに失敗しました。");
    }
  }

  async function resetWorkProcedureFilters() {
    params.setWorkProcedureStatus("PENDING");
    params.setWorkProcedureEmployeeCode("");
    params.setWorkProcedureDepartmentName("");
    params.setWorkProcedureLeaveTypeCode("");
    params.setWorkProcedureRequestCategory("");
    params.setWorkProcedureTimeLeaveType("");
    params.setWorkProcedureFrom(currentMonthStartValue());
    params.setWorkProcedureTo(currentMonthEndValue());

    try {
      const workProcedures = await loadWorkProcedures({ status: "PENDING" });
      params.setDashboard((previous) => ({
        ...previous,
        workProcedures,
      }));
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "届出一覧の再読込に失敗しました。");
    }
  }

  return {
    applyWorkProcedureFilters,
    handleAdjustPaidLeave,
    handleBulkWorkProcedureDecision,
    handleGrantPaidLeave,
    handleWorkProcedureDecision,
    resetWorkProcedureFilters,
  };
}
