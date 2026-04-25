import { DataTable } from "@/components/data-table";
import { ApprovalStatusBadge } from "@/components/status-badge";
import type { LeaveRequest } from "@/types";
import type { LeaveSectionProps } from "./leave-section-types";
import { formatLeavePeriod, formatRequestCategory, formatTimeLeaveType } from "./leave-section-utils";

type LeaveRequestDetailPanelProps = {
  selectedProcedure: LeaveRequest;
  formatters: Pick<LeaveSectionProps["formatters"], "formatDateOnly" | "formatDateTime" | "formatApprovalStatus">;
  onClose: () => void;
  onWorkProcedureDecision: LeaveSectionProps["actions"]["onWorkProcedureDecision"];
};

export function LeaveRequestDetailPanel({ selectedProcedure, formatters, onClose, onWorkProcedureDecision }: LeaveRequestDetailPanelProps) {
  return (
    <section className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>届出詳細 #{selectedProcedure.id}</h3>
        </div>
        <button type="button" className="secondary table-action" onClick={onClose}>
          閉じる
        </button>
      </div>
      <div className="detail-grid">
        <div>
          <span className="detail-label">申請者</span>
          <strong>{selectedProcedure.employee?.employeeCode} / {selectedProcedure.employee?.name}</strong>
        </div>
        <div>
          <span className="detail-label">届出区分</span>
          <strong>{formatRequestCategory(selectedProcedure.requestCategory)} / {selectedProcedure.requestCategory === "TIME_LEAVE" ? formatTimeLeaveType(selectedProcedure.timeLeaveType) : selectedProcedure.leaveTypeName}</strong>
        </div>
        <div>
          <span className="detail-label">状態</span>
          <strong><ApprovalStatusBadge value={selectedProcedure.status} format={formatters.formatApprovalStatus} /></strong>
        </div>
        <div>
          <span className="detail-label">対象期間</span>
          <strong>{formatLeavePeriod(selectedProcedure, formatters.formatDateOnly)}</strong>
        </div>
        <div>
          <span className="detail-label">申請量</span>
          <strong>{selectedProcedure.requestCategory === "TIME_LEAVE" ? `${selectedProcedure.quantityMinutes ?? 0}分` : `${selectedProcedure.quantityDays ?? 0}日`}</strong>
        </div>
        <div>
          <span className="detail-label">申請日時</span>
          <strong>{formatters.formatDateTime(selectedProcedure.createdAt)}</strong>
        </div>
      </div>
      <section className="panel detail-note-panel">
        <span className="detail-label">申請理由</span>
        <p className="detail-note-text">{selectedProcedure.reason || "-"}</p>
      </section>
      <DataTable
        title="承認履歴"
        rows={selectedProcedure.actions ?? []}
        emptyMessage="承認履歴はありません"
        columns={[
          { key: "actedAt", header: "日時", render: (row) => formatters.formatDateTime(row.actedAt) },
          { key: "actionType", header: "操作", render: (row) => <ApprovalStatusBadge value={row.actionType} format={formatters.formatApprovalStatus} /> },
          { key: "actionByName", header: "操作者", render: (row) => row.actionByName },
          { key: "comment", header: "コメント", render: (row) => row.comment ?? "-" },
        ]}
      />
      {selectedProcedure.decisionComment ? (
        <p className="compact-empty">最新コメント: {selectedProcedure.decisionComment}</p>
      ) : null}
      <div className="button-row">
        <button type="button" onClick={() => void onWorkProcedureDecision(selectedProcedure.id, "approve")} disabled={selectedProcedure.status !== "PENDING"}>
          この届出を承認
        </button>
        <button type="button" className="secondary" onClick={() => void onWorkProcedureDecision(selectedProcedure.id, "return")} disabled={selectedProcedure.status !== "PENDING"}>
          この届出を差戻し
        </button>
      </div>
    </section>
  );
}
