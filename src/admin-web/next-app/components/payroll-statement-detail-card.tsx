import { DataTable } from "@/components/data-table";
import type { PayrollStatementDetail } from "@/types";

type PayrollStatementDetailCardProps = {
  detail: PayrollStatementDetail;
  mode: "admin" | "employee";
  onAdminPayrollDownload: (statementId: number, fileName?: string) => Promise<void>;
  onPayrollDownload: (statementId: number, fileName?: string) => Promise<void>;
  onDeletePayrollStatement: (statementId: number) => Promise<void>;
  formatDateOnly: (value?: string | null) => string;
  formatMonthDay: (value?: string | null) => string;
};

export function PayrollStatementDetailCard(props: PayrollStatementDetailCardProps) {
  function formatCurrency(value?: number | null) {
    if (value == null) {
      return "-";
    }

    return `${value.toLocaleString("ja-JP")} 円`;
  }

  const payDateLabel = props.formatMonthDay(props.detail.payDate || props.detail.publishedAt);
  const periodLabel =
    props.detail.periodStartOn && props.detail.periodEndOn
      ? `${props.formatDateOnly(props.detail.periodStartOn)} 〜 ${props.formatDateOnly(props.detail.periodEndOn)}`
      : "-";

  return (
    <section className="panel action-panel payroll-detail-card">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">個票詳細</p>
          <h3>
            {props.detail.employeeName} / {props.detail.statementTypeLabel} {props.detail.targetYearMonth}
          </h3>
        </div>
        <div className="button-row">
          <button
            type="button"
            className="table-action"
            onClick={() =>
              props.mode === "admin"
                ? void props.onAdminPayrollDownload(props.detail.id, props.detail.originalFileName)
                : void props.onPayrollDownload(props.detail.id, props.detail.originalFileName)
            }
          >
            PDF保存
          </button>
          {props.mode === "admin" && props.detail.deleteAvailable ? (
            <button
              type="button"
              className="table-action danger"
              onClick={() => void props.onDeletePayrollStatement(props.detail.id)}
            >
              削除する
            </button>
          ) : null}
        </div>
      </div>

      <div className="detail-grid">
        <div>
          <span className="detail-label">社員番号</span>
          <strong>{props.detail.employeeCode}</strong>
        </div>
        <div>
          <span className="detail-label">氏名</span>
          <strong>{props.detail.employeeName}</strong>
        </div>
        <div>
          <span className="detail-label">明細区分</span>
          <strong>{props.detail.statementTypeLabel}</strong>
        </div>
        <div>
          <span className="detail-label">対象月</span>
          <strong>{props.detail.targetYearMonth}</strong>
        </div>
        <div>
          <span className="detail-label">対象期間</span>
          <strong>{periodLabel}</strong>
        </div>
        <div>
          <span className="detail-label">支給日</span>
          <strong>{payDateLabel}</strong>
        </div>
      </div>

      <div className="summary-strip">
        <div>
          <span className="detail-label">総支給額</span>
          <strong>{formatCurrency(props.detail.grossAmount)}</strong>
        </div>
        <div>
          <span className="detail-label">控除額計</span>
          <strong>{formatCurrency(props.detail.deductionAmount)}</strong>
        </div>
        <div>
          <span className="detail-label">差引支給</span>
          <strong>{formatCurrency(props.detail.netAmount)}</strong>
        </div>
      </div>

      <div className="split">
        <DataTable
          title="支給"
          rows={props.detail.sections.pay}
          emptyMessage="支給項目はありません"
          columns={[
            { key: "itemLabel", header: "項目", render: (row) => row.itemLabel },
            { key: "amount", header: "金額", render: (row) => row.formattedAmount },
          ]}
        />
        <DataTable
          title="控除"
          rows={props.detail.sections.deduction}
          emptyMessage="控除項目はありません"
          columns={[
            { key: "itemLabel", header: "項目", render: (row) => row.itemLabel },
            { key: "amount", header: "金額", render: (row) => row.formattedAmount },
          ]}
        />
      </div>

      <div className="split">
        <DataTable
          title="その他"
          rows={props.detail.sections.summary}
          emptyMessage="集計項目はありません"
          columns={[
            { key: "itemLabel", header: "項目", render: (row) => row.itemLabel },
            { key: "amount", header: "金額", render: (row) => row.formattedAmount },
          ]}
        />
        <section className="panel detail-note-panel">
          <div className="panel-header">
            <div>
              <p className="panel-kicker">備考</p>
              <h3>明細メモ</h3>
            </div>
          </div>
          <p className="detail-note-text">{props.detail.remarks || "備考はありません。"}</p>
          {props.detail.legacyMode ? (
            <p className="detail-legacy-note">
              旧形式の明細です。画面内の明細表は簡易表示となりますが、PDF保存は利用できます。
            </p>
          ) : null}
        </section>
      </div>
    </section>
  );
}
