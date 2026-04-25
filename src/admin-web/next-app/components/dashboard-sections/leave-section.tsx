import { useState } from "react";
import { loadWorkProcedureDetail } from "@/lib/api";
import type { LeaveRequest } from "@/types";
import { LeaveBalancesPanel } from "./leave/leave-balances-panel";
import { LeaveFiltersPanel } from "./leave/leave-filters-panel";
import { LeaveGrantAdjustPanel } from "./leave/leave-grant-adjust-panel";
import { LeaveRequestDetailPanel } from "./leave/leave-request-detail-panel";
import { LeaveRequestsPanel } from "./leave/leave-requests-panel";
import type { LeaveSectionProps } from "./leave/leave-section-types";

export function LeaveSection(props: LeaveSectionProps) {
  const activePanel = props.data.activePanel || "leave-filters";
  const [selectedProcedure, setSelectedProcedure] = useState<LeaveRequest | null>(null);
  const [selectedProcedureIds, setSelectedProcedureIds] = useState<number[]>([]);
  const [detailMessage, setDetailMessage] = useState("");
  const workProcedures = props.data.dashboard.workProcedures;
  const pendingProcedures = workProcedures.filter((row) => row.status === "PENDING");
  const selectedPendingIds = selectedProcedureIds.filter((id) => pendingProcedures.some((row) => row.id === id));
  const allPendingSelected = pendingProcedures.length > 0 && pendingProcedures.every((row) => selectedPendingIds.includes(row.id));

  async function openProcedureDetail(id: number) {
    try {
      setDetailMessage("");
      setSelectedProcedure(await loadWorkProcedureDetail(id));
    } catch (error) {
      setDetailMessage(error instanceof Error ? error.message : "届出詳細の読み込みに失敗しました。");
    }
  }

  function toggleProcedureSelection(id: number) {
    setSelectedProcedureIds((current) => (current.includes(id) ? current.filter((selectedId) => selectedId !== id) : [...current, id]));
  }

  function toggleAllPendingProcedures() {
    setSelectedProcedureIds(allPendingSelected ? [] : pendingProcedures.map((row) => row.id));
  }

  async function bulkDecisionForSelected(decision: "approve" | "return") {
    await props.actions.onBulkWorkProcedureDecision(decision, selectedPendingIds);
    setSelectedProcedureIds([]);
  }

  return (
    <section className="stack-section section-enter delay-3">
      {activePanel === "leave-filters" ? (
        <LeaveFiltersPanel dashboard={props.data.dashboard} filters={props.filters} actions={props.actions} />
      ) : null}

      {activePanel === "leave-requests" ? (
        <LeaveRequestsPanel
          workProcedures={workProcedures}
          pendingProcedures={pendingProcedures}
          selectedPendingIds={selectedPendingIds}
          allPendingSelected={allPendingSelected}
          decisionComment={props.form.decisionComment}
          decisionResult={props.data.decisionResult}
          actions={props.actions}
          formatters={props.formatters}
          onOpenProcedureDetail={(id) => void openProcedureDetail(id)}
          onToggleProcedureSelection={toggleProcedureSelection}
          onToggleAllPendingProcedures={toggleAllPendingProcedures}
          onBulkDecisionForSelected={bulkDecisionForSelected}
        />
      ) : null}

      {activePanel === "leave-requests" && selectedProcedure ? (
        <LeaveRequestDetailPanel
          selectedProcedure={selectedProcedure}
          formatters={props.formatters}
          onClose={() => setSelectedProcedure(null)}
          onWorkProcedureDecision={props.actions.onWorkProcedureDecision}
        />
      ) : null}
      {detailMessage ? <p className="feedback">{detailMessage}</p> : null}

      {activePanel === "leave-balances" ? (
        <LeaveBalancesPanel dashboard={props.data.dashboard} formatters={props.formatters} />
      ) : null}

      {activePanel === "leave-grant-adjust" ? (
        <LeaveGrantAdjustPanel
          dashboard={props.data.dashboard}
          form={props.form}
          actions={props.actions}
          leaveAdminResult={props.data.leaveAdminResult}
          decisionResult={props.data.decisionResult}
        />
      ) : null}
    </section>
  );
}
