import { useState } from "react";
import { DataTable } from "@/components/data-table";
import { ApprovalStatusBadge } from "@/components/status-badge";
import { loadWorkProcedureDetail } from "@/lib/api";
import type { DashboardData, LeaveRequest } from "@/types";

type LeaveSectionProps = {
  data: {
    dashboard: DashboardData;
    decisionResult: string;
    leaveAdminResult: string;
    activePanel: string;
  };
  filters: {
    workProcedureStatus: string;
    workProcedureEmployeeCode: string;
    workProcedureDepartmentName: string;
    workProcedureLeaveTypeCode: string;
    workProcedureRequestCategory: string;
    workProcedureTimeLeaveType: string;
    workProcedureFrom: string;
    workProcedureTo: string;
  };
  form: {
    grantEmployeeId: string;
    grantDays: string;
    grantDate: string;
    grantExpiresOn: string;
    grantNote: string;
    adjustType: "ADJUST_PLUS" | "ADJUST_MINUS";
    adjustDays: string;
    adjustDate: string;
    adjustNote: string;
    decisionComment: string;
  };
  actions: {
    onWorkProcedureStatusChange: (value: string) => void;
    onWorkProcedureEmployeeCodeChange: (value: string) => void;
    onWorkProcedureDepartmentNameChange: (value: string) => void;
    onWorkProcedureLeaveTypeCodeChange: (value: string) => void;
    onWorkProcedureRequestCategoryChange: (value: string) => void;
    onWorkProcedureTimeLeaveTypeChange: (value: string) => void;
    onWorkProcedureFromChange: (value: string) => void;
    onWorkProcedureToChange: (value: string) => void;
    onGrantEmployeeIdChange: (value: string) => void;
    onGrantDaysChange: (value: string) => void;
    onGrantDateChange: (value: string) => void;
    onGrantExpiresOnChange: (value: string) => void;
    onGrantNoteChange: (value: string) => void;
    onAdjustTypeChange: (value: "ADJUST_PLUS" | "ADJUST_MINUS") => void;
    onAdjustDaysChange: (value: string) => void;
    onAdjustDateChange: (value: string) => void;
    onAdjustNoteChange: (value: string) => void;
    onDecisionCommentChange: (value: string) => void;
    onApplyWorkProcedureFilters: () => Promise<void>;
    onResetWorkProcedureFilters: () => Promise<void>;
    onWorkProcedureDecision: (id: number, decision: "approve" | "return") => Promise<void>;
    onBulkWorkProcedureDecision: (decision: "approve" | "return", selectedIds?: number[]) => Promise<void>;
    onGrantPaidLeave: () => Promise<void>;
    onAdjustPaidLeave: () => Promise<void>;
  };
  formatters: {
    formatDateOnly: (value?: string | null) => string;
    formatDateTime: (value?: string | null) => string;
    formatApprovalStatus: (value?: string | null) => string;
    formatLeaveLedgerEntryType: (value?: string | null) => string;
  };
};

function formatRequestCategory(value?: string | null) {
  return value === "TIME_LEAVE" ? "時間休暇" : "通常休暇";
}

function formatTimeLeaveType(value?: string | null) {
  return {
    PAID_HOURLY: "時間有給",
    CHILD_CARE_HOURLY: "子の看護（時間）",
    NURSING_CARE_HOURLY: "介護（時間）",
  }[value ?? ""] ?? value ?? "-";
}

function formatLeavePeriod(row: LeaveRequest, formatDateOnly: (value?: string | null) => string) {
  if (row.requestCategory === "TIME_LEAVE") {
    return `${formatDateOnly(row.targetDate)} ${row.startTime ?? ""}-${row.endTime ?? ""}`;
  }

  return `${formatDateOnly(row.startDate)} - ${formatDateOnly(row.endDate)}`;
}

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
      <section id="leave-filters" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>届出の検索条件</h3>
          </div>
        </div>
        <div className="filter-grid">
          <label>
            状態
            <select value={props.filters.workProcedureStatus} onChange={(event) => props.actions.onWorkProcedureStatusChange(event.target.value)}>
              <option value="">すべて</option>
              <option value="PENDING">承認待ち</option>
              <option value="APPROVED">承認済み</option>
              <option value="RETURNED">差戻し</option>
              <option value="REJECTED">却下</option>
            </select>
          </label>
          <label>
            職員番号
            <input
              value={props.filters.workProcedureEmployeeCode}
              onChange={(event) => props.actions.onWorkProcedureEmployeeCodeChange(event.target.value)}
              placeholder="例: 132"
            />
          </label>
          <label>
            部門
            <select
              value={props.filters.workProcedureDepartmentName}
              onChange={(event) => props.actions.onWorkProcedureDepartmentNameChange(event.target.value)}
            >
              <option value="">すべて</option>
              {props.data.dashboard.systemMasters.departments.map((department) => (
                <option key={department.id} value={department.name}>
                  {department.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            休暇区分
            <select
              value={props.filters.workProcedureLeaveTypeCode}
              onChange={(event) => props.actions.onWorkProcedureLeaveTypeCodeChange(event.target.value)}
            >
              <option value="">すべて</option>
              {props.data.dashboard.systemMasters.leaveTypes.map((leaveType) => (
                <option key={leaveType.code} value={leaveType.code}>
                  {leaveType.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            届出カテゴリ
            <select
              value={props.filters.workProcedureRequestCategory}
              onChange={(event) => props.actions.onWorkProcedureRequestCategoryChange(event.target.value)}
            >
              <option value="">すべて</option>
              <option value="LEAVE">通常休暇</option>
              <option value="TIME_LEAVE">時間休暇</option>
            </select>
          </label>
          <label>
            時間休暇種別
            <select
              value={props.filters.workProcedureTimeLeaveType}
              onChange={(event) => props.actions.onWorkProcedureTimeLeaveTypeChange(event.target.value)}
            >
              <option value="">すべて</option>
              <option value="PAID_HOURLY">時間有給</option>
              <option value="CHILD_CARE_HOURLY">子の看護（時間）</option>
              <option value="NURSING_CARE_HOURLY">介護（時間）</option>
            </select>
          </label>
          <label>
            申請開始日
            <input type="date" value={props.filters.workProcedureFrom} onChange={(event) => props.actions.onWorkProcedureFromChange(event.target.value)} />
          </label>
          <label>
            申請終了日
            <input type="date" value={props.filters.workProcedureTo} onChange={(event) => props.actions.onWorkProcedureToChange(event.target.value)} />
          </label>
        </div>
        <div className="button-row">
          <button type="button" onClick={() => void props.actions.onApplyWorkProcedureFilters()}>
            条件で再表示
          </button>
          <button type="button" className="secondary" onClick={() => void props.actions.onResetWorkProcedureFilters()}>
            条件をクリア
          </button>
        </div>
      </section>
      ) : null}

      {activePanel === "leave-requests" ? (
        <>
          <section className="panel action-panel anchor-panel">
            <div className="panel-header">
              <div>
                <p className="panel-kicker">BULK ACTION</p>
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
              <textarea rows={3} value={props.form.decisionComment} onChange={(event) => props.actions.onDecisionCommentChange(event.target.value)} />
            </label>
            <div className="button-row">
              <button type="button" onClick={() => void bulkDecisionForSelected("approve")} disabled={selectedPendingIds.length === 0}>
                選択中を一括承認
              </button>
              <button type="button" className="secondary" onClick={() => void bulkDecisionForSelected("return")} disabled={selectedPendingIds.length === 0}>
                選択中を一括差戻し
              </button>
            </div>
            {props.data.decisionResult ? <p className="feedback">{props.data.decisionResult}</p> : null}
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
                    <input type="checkbox" checked={allPendingSelected} onChange={toggleAllPendingProcedures} disabled={pendingProcedures.length === 0} />
                  </label>
                ),
                render: (row) => (
                  <label className="table-checkbox" aria-label={`届出 ${row.id} を選択`}>
                    <input
                      type="checkbox"
                      checked={selectedPendingIds.includes(row.id)}
                      onChange={() => toggleProcedureSelection(row.id)}
                      disabled={row.status !== "PENDING"}
                    />
                  </label>
                ),
              },
              { key: "id", header: "ID", render: (row) => row.id },
              { key: "employeeName", header: "申請者", render: (row) => row.employee?.name ?? "-" },
              { key: "requestCategory", header: "カテゴリ", render: (row) => formatRequestCategory(row.requestCategory) },
              { key: "leaveTypeName", header: "区分", render: (row) => row.requestCategory === "TIME_LEAVE" ? formatTimeLeaveType(row.timeLeaveType) : row.leaveTypeName },
              {
                key: "period",
                header: "期間",
                render: (row) => formatLeavePeriod(row, props.formatters.formatDateOnly),
              },
              { key: "quantity", header: "分数", render: (row) => row.requestCategory === "TIME_LEAVE" ? `${row.quantityMinutes ?? 0}分` : `${row.quantityDays ?? "-"}日` },
              { key: "status", header: "状態", render: (row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} /> },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <div className="button-row">
                    <button type="button" className="table-action" onClick={() => void openProcedureDetail(row.id)}>
                      詳細
                    </button>
                    <button type="button" className="table-action" onClick={() => void props.actions.onWorkProcedureDecision(row.id, "approve")} disabled={row.status !== "PENDING"}>
                      承認
                    </button>
                    <button type="button" className="table-action" onClick={() => void props.actions.onWorkProcedureDecision(row.id, "return")} disabled={row.status !== "PENDING"}>
                      差戻し
                    </button>
                  </div>
                ),
              },
            ]}
          />
        </>
      ) : null}
      {activePanel === "leave-requests" && selectedProcedure ? (
        <section className="panel action-panel anchor-panel">
          <div className="panel-header">
            <div>
              <h3>届出詳細 #{selectedProcedure.id}</h3>
            </div>
            <button type="button" className="secondary table-action" onClick={() => setSelectedProcedure(null)}>
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
              <strong><ApprovalStatusBadge value={selectedProcedure.status} format={props.formatters.formatApprovalStatus} /></strong>
            </div>
            <div>
              <span className="detail-label">対象期間</span>
              <strong>{formatLeavePeriod(selectedProcedure, props.formatters.formatDateOnly)}</strong>
            </div>
            <div>
              <span className="detail-label">申請量</span>
              <strong>{selectedProcedure.requestCategory === "TIME_LEAVE" ? `${selectedProcedure.quantityMinutes ?? 0}分` : `${selectedProcedure.quantityDays ?? 0}日`}</strong>
            </div>
            <div>
              <span className="detail-label">申請日時</span>
              <strong>{props.formatters.formatDateTime(selectedProcedure.createdAt)}</strong>
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
              { key: "actedAt", header: "日時", render: (row) => props.formatters.formatDateTime(row.actedAt) },
              { key: "actionType", header: "操作", render: (row) => <ApprovalStatusBadge value={row.actionType} format={props.formatters.formatApprovalStatus} /> },
              { key: "actionByName", header: "操作者", render: (row) => row.actionByName },
              { key: "comment", header: "コメント", render: (row) => row.comment ?? "-" },
            ]}
          />
          {selectedProcedure.decisionComment ? (
            <p className="compact-empty">最新コメント: {selectedProcedure.decisionComment}</p>
          ) : null}
          <div className="button-row">
            <button type="button" onClick={() => void props.actions.onWorkProcedureDecision(selectedProcedure.id, "approve")} disabled={selectedProcedure.status !== "PENDING"}>
              この届出を承認
            </button>
            <button type="button" className="secondary" onClick={() => void props.actions.onWorkProcedureDecision(selectedProcedure.id, "return")} disabled={selectedProcedure.status !== "PENDING"}>
              この届出を差戻し
            </button>
          </div>
        </section>
      ) : null}
      {detailMessage ? <p className="feedback">{detailMessage}</p> : null}

      {activePanel === "leave-balances" ? (
        <DataTable
          id="leave-balances"
          title="有給残数一覧"
          rows={props.data.dashboard.paidLeaveReport}
          emptyMessage="有給データはありません"
          columns={[
            { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
            { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
            { key: "departmentName", header: "所属", render: (row) => row.departmentName ?? "-" },
            { key: "currentBalance", header: "残数", render: (row) => `${row.currentBalance}日` },
            { key: "latestEntryType", header: "最新区分", render: (row) => props.formatters.formatLeaveLedgerEntryType(row.latestEntryType) },
          ]}
        />
      ) : null}
      {activePanel === "leave-grant-adjust" ? (
        <section id="leave-grant-adjust" className="panel action-panel anchor-panel">
          <div className="panel-header">
            <div>
              <h3>有給付与・調整</h3>
            </div>
          </div>
          <label>
            対象職員
            <select value={props.form.grantEmployeeId} onChange={(event) => props.actions.onGrantEmployeeIdChange(event.target.value)}>
              {props.data.dashboard.employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.employeeCode} / {employee.name}
                </option>
              ))}
            </select>
          </label>
          <label>付与日数<input value={props.form.grantDays} onChange={(event) => props.actions.onGrantDaysChange(event.target.value)} /></label>
          <label>付与日<input type="date" value={props.form.grantDate} onChange={(event) => props.actions.onGrantDateChange(event.target.value)} /></label>
          <label>失効日<input type="date" value={props.form.grantExpiresOn} onChange={(event) => props.actions.onGrantExpiresOnChange(event.target.value)} /></label>
          <label>付与メモ<input value={props.form.grantNote} onChange={(event) => props.actions.onGrantNoteChange(event.target.value)} /></label>
          <button type="button" onClick={() => void props.actions.onGrantPaidLeave()}>付与を登録</button>

          <hr className="soft-divider" />

          <label>
            調整種別
            <select value={props.form.adjustType} onChange={(event) => props.actions.onAdjustTypeChange(event.target.value as "ADJUST_PLUS" | "ADJUST_MINUS")}>
              <option value="ADJUST_PLUS">残数を増やす</option>
              <option value="ADJUST_MINUS">残数を減らす</option>
            </select>
          </label>
          <label>調整日数<input value={props.form.adjustDays} onChange={(event) => props.actions.onAdjustDaysChange(event.target.value)} /></label>
          <label>反映日<input type="date" value={props.form.adjustDate} onChange={(event) => props.actions.onAdjustDateChange(event.target.value)} /></label>
          <label>調整メモ<input value={props.form.adjustNote} onChange={(event) => props.actions.onAdjustNoteChange(event.target.value)} /></label>
          <button type="button" className="secondary" onClick={() => void props.actions.onAdjustPaidLeave()}>調整を登録</button>

          <label>
            判定コメント
            <textarea rows={4} value={props.form.decisionComment} onChange={(event) => props.actions.onDecisionCommentChange(event.target.value)} />
          </label>
          {props.data.leaveAdminResult ? <p className="feedback">{props.data.leaveAdminResult}</p> : null}
          {props.data.decisionResult ? <p className="feedback">{props.data.decisionResult}</p> : null}
        </section>
      ) : null}
    </section>
  );
}
