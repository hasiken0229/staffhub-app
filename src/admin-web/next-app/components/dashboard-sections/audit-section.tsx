import { DataTable } from "@/components/data-table";
import type { AuditLog } from "@/types";

type AuditSectionProps = {
  data: {
    auditLogs: AuditLog[];
    activePanel: string;
  };
  filters: {
    auditActorFilter: string;
    auditActionFilter: string;
    auditFrom: string;
    auditTo: string;
  };
  actions: {
    onAuditActorFilterChange: (value: string) => void;
    onAuditActionFilterChange: (value: string) => void;
    onAuditFromChange: (value: string) => void;
    onAuditToChange: (value: string) => void;
    onApplyAuditFilters: () => Promise<void>;
    onResetAuditFilters: () => Promise<void>;
  };
  formatters: {
    formatDateTime: (value?: string | null) => string;
  };
};

export function AuditSection(props: AuditSectionProps) {
  const activePanel = props.data.activePanel || "audit-filters";

  return (
    <section className="stack-section section-enter delay-3">
      {activePanel === "audit-filters" ? (
      <section id="audit-filters" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>監査ログの検索条件</h3>
          </div>
        </div>
        <div className="filter-grid">
          <label>
            実行者
            <input
              value={props.filters.auditActorFilter}
              onChange={(event) => props.actions.onAuditActorFilterChange(event.target.value)}
              placeholder="管理者名 / 職員番号 / 端末名"
            />
          </label>
          <label>
            操作種別
            <input
              value={props.filters.auditActionFilter}
              onChange={(event) => props.actions.onAuditActionFilterChange(event.target.value.toUpperCase())}
              placeholder="例: NOTICE_CREATED"
            />
          </label>
          <label>
            開始日
            <input type="date" value={props.filters.auditFrom} onChange={(event) => props.actions.onAuditFromChange(event.target.value)} />
          </label>
          <label>
            終了日
            <input type="date" value={props.filters.auditTo} onChange={(event) => props.actions.onAuditToChange(event.target.value)} />
          </label>
        </div>
        <div className="button-row">
          <button type="button" onClick={() => void props.actions.onApplyAuditFilters()}>
            条件で再表示
          </button>
          <button type="button" className="secondary" onClick={() => void props.actions.onResetAuditFilters()}>
            条件をクリア
          </button>
        </div>
      </section>
      ) : null}

      {activePanel === "audit-logs" ? (
        <DataTable
          id="audit-logs"
          title="監査ログ"
          rows={props.data.auditLogs}
          columns={[
            { key: "occurredAt", header: "時刻", render: (row) => props.formatters.formatDateTime(row.occurredAt) },
            {
              key: "actorLabel",
              header: "実行者",
              render: (row) => row.actorLabel ?? `${row.actorType ?? "-"} / ${row.actorId ?? "-"}`,
            },
            { key: "action", header: "種別", render: (row) => row.action },
            { key: "target", header: "対象", render: (row) => `${row.targetType} / ${row.targetId ?? "-"}` },
            { key: "detail", header: "詳細", render: (row) => row.detail },
            { key: "ipAddress", header: "IP", render: (row) => row.ipAddress ?? "-" },
          ]}
        />
      ) : null}
    </section>
  );
}
