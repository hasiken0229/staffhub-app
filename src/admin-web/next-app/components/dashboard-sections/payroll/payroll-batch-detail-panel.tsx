import { DataTable } from "@/components/data-table";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";
import { formatCurrency } from "@/components/dashboard-sections/payroll/payroll-utils";

type PayrollBatchDetailPanelProps = {
  data: PayrollSectionProps["data"];
  form: PayrollSectionProps["form"];
  actions: PayrollSectionProps["actions"];
  formatters: PayrollSectionProps["formatters"];
};

export function PayrollBatchDetailPanel({ data, form, actions, formatters }: PayrollBatchDetailPanelProps) {
  if (!data.selectedPayrollBatchDetail) {
    return null;
  }

  const detail = data.selectedPayrollBatchDetail;

  return (
    <>
      <section id="payroll-batch-detail" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <p className="panel-kicker">取込詳細</p>
            <h3>
              {detail.statementTypeLabel} {detail.targetYearMonth}
            </h3>
          </div>
          <div className="button-row">
            <button
              type="button"
              className="table-action"
              onClick={() =>
                void actions.onExportPayrollBatch(
                  detail.id,
                  `${detail.statementType === "BONUS" ? "bonus" : "payroll"}_batch_${detail.targetYearMonth}.zip`,
                )
              }
            >
              一括PDF出力
            </button>
            <button type="button" className="table-action danger" onClick={() => void actions.onDeletePayrollBatch(detail.id)}>
              取込バッチ削除
            </button>
          </div>
        </div>

        <div className="detail-grid">
          <div>
            <span className="detail-label">データ定義</span>
            <strong>{detail.definitionName}</strong>
          </div>
          <div>
            <span className="detail-label">対象月</span>
            <strong>{detail.targetYearMonth}</strong>
          </div>
          <div>
            <span className="detail-label">対象期間</span>
            <strong>
              {formatters.formatDateOnly(detail.periodStartOn)} 〜 {formatters.formatDateOnly(detail.periodEndOn)}
            </strong>
          </div>
          <div>
            <span className="detail-label">支給日</span>
            <strong>{formatters.formatMonthDay(detail.payDate)}</strong>
          </div>
          <div>
            <span className="detail-label">公開日</span>
            <strong>{formatters.formatMonthDay(detail.publishDate)}</strong>
          </div>
          <div>
            <span className="detail-label">件数</span>
            <strong>{detail.successCount} 件</strong>
          </div>
        </div>

        <div className="panel-toolbar panel-toolbar-stacked">
          <div className="filter-grid filter-grid-compact">
            <label>
              職員番号
              <input
                value={form.payrollBatchEmployeeCodeFilter}
                onChange={(event) => actions.onPayrollBatchEmployeeCodeFilterChange(event.target.value)}
              />
            </label>
            <label>
              氏名
              <input
                value={form.payrollBatchEmployeeNameFilter}
                onChange={(event) => actions.onPayrollBatchEmployeeNameFilterChange(event.target.value)}
              />
            </label>
          </div>
          <div className="button-row">
            <button type="button" className="table-action" onClick={() => void actions.onSearchPayrollBatchDetail()}>
              検索
            </button>
            <button
              type="button"
              className="table-action secondary"
              onClick={() => {
                actions.onPayrollBatchEmployeeCodeFilterChange("");
                actions.onPayrollBatchEmployeeNameFilterChange("");
                void actions.onOpenPayrollBatchDetail(detail.id);
              }}
            >
              条件解除
            </button>
          </div>
        </div>
      </section>

      <DataTable
        title="取込明細一覧"
        rows={detail.items}
        emptyMessage="明細データはありません"
        columns={[
          { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
          { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
          { key: "grossAmount", header: "支給合計", render: (row) => formatCurrency(row.grossAmount) },
          { key: "deductionAmount", header: "控除合計", render: (row) => formatCurrency(row.deductionAmount) },
          { key: "netAmount", header: "差引支給額", render: (row) => formatCurrency(row.netAmount) },
          {
            key: "action",
            header: "操作",
            render: (row) =>
              row.statementId ? (
                <div className="button-row">
                  <button type="button" className="table-action" onClick={() => void actions.onLoadAdminPayrollDetail(row.statementId!)}>
                    個票詳細
                  </button>
                  <button
                    type="button"
                    className="table-action"
                    onClick={() => void actions.onAdminPayrollDownload(row.statementId!, row.originalFileName ?? undefined)}
                  >
                    PDF保存
                  </button>
                </div>
              ) : (
                "-"
              ),
          },
        ]}
      />

      <DataTable
        title="取込エラー"
        rows={detail.errors}
        emptyMessage="取込エラーはありません"
        columns={[
          { key: "line", header: "行", render: (row) => row.line },
          { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode ?? "-" },
          { key: "message", header: "内容", render: (row) => row.message },
        ]}
      />
    </>
  );
}
