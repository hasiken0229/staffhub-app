import type { Dispatch, SetStateAction } from "react";
import { loadAuditLogs } from "@/lib/api";
import type { DashboardData } from "@/types";

type UseAuditActionsParams = {
  auditActorFilter: string;
  auditActionFilter: string;
  auditFrom: string;
  auditTo: string;
  setAuditActorFilter: (value: string) => void;
  setAuditActionFilter: (value: string) => void;
  setAuditFrom: (value: string) => void;
  setAuditTo: (value: string) => void;
  setDashboard: Dispatch<SetStateAction<DashboardData>>;
  setErrorMessage: (value: string) => void;
};

export function useAuditActions(params: UseAuditActionsParams) {
  async function applyAuditFilters() {
    try {
      const auditLogs = await loadAuditLogs({
        actor: params.auditActorFilter || undefined,
        action: params.auditActionFilter || undefined,
        from: params.auditFrom || undefined,
        to: params.auditTo || undefined,
      });

      params.setDashboard((previous) => ({
        ...previous,
        auditLogs,
      }));
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "監査ログの読込に失敗しました。");
    }
  }

  async function resetAuditFilters() {
    params.setAuditActorFilter("");
    params.setAuditActionFilter("");
    params.setAuditFrom("");
    params.setAuditTo("");

    try {
      const auditLogs = await loadAuditLogs();
      params.setDashboard((previous) => ({
        ...previous,
        auditLogs,
      }));
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "監査ログの読込に失敗しました。");
    }
  }

  return {
    applyAuditFilters,
    resetAuditFilters,
  };
}
