import { DataTable } from "@/components/data-table";
import { ApprovalStatusBadge } from "@/components/status-badge";
import type { LeaveRequest } from "@/types";
import type { LeaveSectionProps } from "./leave-section-types";
import { formatLeavePeriod, formatRequestCategory, formatTimeLeaveType } from "./leave-section-utils";

type LeaveRequestsPanelProps = {
  workProcedures: LeaveRequest[];
  pendingProcedures: LeaveRequest[];
  selectedPendingIds: number[];
  allPendingSelected: boolean;
  decisionComment: string;
  decisionResult: string;
  actions: LeaveSectionProps["actions"];
  formatters: Pick<LeaveSectionProps["formatters"], "formatDateOnly" | "formatApprovalStatus">;
  onOpenProcedureDetail: (id: number) => void;
  onToggleProcedureSelection: (id: number) => void;
  onToggleAllPendingProcedures: () => void;
  onBulkDecisionForSelected: (decision: "approve" | "return") => Promise<void>;
};

export function LeaveRequestsPanel({
  workProcedures,
  pendingProcedures,
  selectedPendingIds,
  allPendingSelected,
  decisionComment,
  decisionResult,
  actions,
  formatters,
  onOpenProcedureDetail,
  onToggleProcedureSelection,
  onToggleAllPendingProcedures,
  onBulkDecisionForSelected,
}: LeaveRequestsPanelProps) {
  return (
    <>
      <section className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <p className="panel-kicker">一括操作</p>
            <h3>届出の一括処理</h3>
          </div>
          <span className="panel-meta">選択中 {selectedPendingIds.length} 件</span>
        </div>
        <div className="summary-strip">
          <div>
            <span className="detail-label">表示中</span>
            <strong>{workProcedures.length} 件</strong>
          </div>
          <div>
            <span className="detail-label">承認待ち</span>
            <strong>{pendingProcedures.length} 件</strong>
          </div>
          <div>
            <span className="detail-label">一括対象</span>
            <strong>{selectedPendingIds.length} 件</strong>
          </div>
        </div>
        <label>
          判定コメント
          <textarea rows={3} value={decisionComment} onChange={(event) => actions.onDecisionCommentChange(event.target.value)} />
        </label>
        <div className="button-row">
          <button type="button" onClick={() => void onBulkDecisionForSelected("approve")} disabled={selectedPendingIds.length === 0}>
            選択中を一括承認
          </button>
          <button type="button" className="secondary" onClick={() => void onBulkDecisionForSelected("return")} disabled={selectedPendingIds.length === 0}>
            選択中を一括差戻し
          </button>
        </div>
        {decisionResult ? <p className="feedback">{decisionResult}</p> : null}
      </section>
      <DataTable
        id="leave-requests"
        title="届出承認一覧"
        rows={workProcedures}
        rowClassName={(row) => (selectedPendingIds.includes(row.id) ? "table-row-selected" : row.status !== "PENDING" ? "table-row-muted" : undefined)}
        columns={[
          {
            key: "select",
            header: (
              <label className="table-checkbox" aria-label={allPendingSelected ? "承認待ちの届出選択を解除" : "承認待ちの届出を全選択"}>
                <input type="checkbox" checked={allPendingSelected} onChange={onToggleAllPendingProcedures} disabled={pendingProcedures.length === 0} />
              </label>
            ),
            render: (row) => (
              <label className="table-checkbox" aria-label={`届出 ${row.id} を選択`}>
                <input
                  type="checkbox"
                  checked={selectedPendingIds.includes(row.id)}
                  onChange={() => onToggleProcedureSelection(row.id)}
                  disabled={row.status !== "PENDING"}
                />
              </label>
            ),
          },
          { key: "id", header: "ID", render: (row) => row.id },
          { key: "employeeName", header: "申請者", render: (row) => row.employee?.name ?? "-" },
          { key: "requestCategory", header: "カテゴリ", render: (row) => formatRequestCategory(row.requestCategory) },
          { key: "leaveTypeName", header: "区分", render: (row) => row.requestCategory === "TIME_LEAVE" ? formatTimeLeaveType(row.timeLeaveType) : row.leaveTypeName },
          { key: "period", header: "期間", render: (row) => formatLeavePeriod(row, formatters.formatDateOnly) },
          { key: "quantity", header: "分数", render: (row) => row.requestCategory === "TIME_LEAVE" ? `${row.quantityMinutes ?? 0}分` : `${row.quantityDays ?? "-"}日` },
          { key: "status", header: "状態", render: (row) => <ApprovalStatusBadge value={row.status} format={formatters.formatApprovalStatus} /> },
          {
            key: "actions",
            header: "操作",
            render: (row) => (
              <div className="button-row">
                <button type="button" className="table-action" onClick={() => onOpenProcedureDetail(row.id)}>
                  詳細
                </button>
                <button type="button" className="table-action" onClick={() => void actions.onWorkProcedureDecision(row.id, "approve")} disabled={row.status !== "PENDING"}>
                  承認
                </button>
                <button type="button" className="table-action" onClick={() => void actions.onWorkProcedureDecision(row.id, "return")} disabled={row.status !== "PENDING"}>
                  差戻し
                </button>
              </div>
            ),
          },
        ]}
      />
    </>
  );
}
