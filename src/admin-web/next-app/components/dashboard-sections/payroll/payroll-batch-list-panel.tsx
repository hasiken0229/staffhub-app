import { DataTable } from "@/components/data-table";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

type PayrollBatchListPanelProps = {
  data: PayrollSectionProps["data"];
  form: PayrollSectionProps["form"];
  actions: PayrollSectionProps["actions"];
  formatters: PayrollSectionProps["formatters"];
};

export function PayrollBatchListPanel({ data, form, actions, formatters }: PayrollBatchListPanelProps) {
  return (
    <>
      <section id="payroll-batches" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <p className="panel-kicker">一覧</p>
            <h3>{data.payrollTypeLabel}の取込バッチ</h3>
          </div>
          <span className="panel-meta">{data.filteredPayrollBatches.length} 件</span>
        </div>
        <div className="panel-toolbar">
          <label className="compact-field">
            対象月
            <input
              type="month"
              value={form.payrollBatchTargetMonthFilter}
              onChange={(event) => actions.onPayrollBatchTargetMonthFilterChange(event.target.value)}
            />
          </label>
          <div className="button-row">
            <button type="button" className="table-action secondary" onClick={() => actions.onPayrollBatchTargetMonthFilterChange("")}>
              解除
            </button>
          </div>
        </div>
        {form.payrollBatchResult ? <p className="feedback">{form.payrollBatchResult}</p> : null}
      </section>

      <DataTable
        title="取込バッチ一覧"
        rows={data.filteredPayrollBatches}
        emptyMessage="取込バッチはまだありません"
        columns={[
          { key: "targetYearMonth", header: "対象月", render: (row) => row.targetYearMonth },
          { key: "definitionName", header: "データ定義", render: (row) => row.definitionName },
          {
            key: "period",
            header: "対象期間",
            render: (row) => `${formatters.formatDateOnly(row.periodStartOn)} 〜 ${formatters.formatDateOnly(row.periodEndOn)}`,
          },
          { key: "payDate", header: "支給日", render: (row) => formatters.formatMonthDay(row.payDate) },
          { key: "publishDate", header: "公開日", render: (row) => formatters.formatMonthDay(row.publishDate) },
          { key: "successCount", header: "件数", render: (row) => row.successCount },
          { key: "status", header: "状態", render: (row) => formatters.formatPayrollBatchStatus(row.status) },
          {
            key: "action",
            header: "操作",
            render: (row) => (
              <div className="button-row">
                <button type="button" className="table-action" onClick={() => void actions.onOpenPayrollBatchDetail(row.id)}>
                  詳細
                </button>
                <button
                  type="button"
                  className="table-action"
                  onClick={() =>
                    void actions.onExportPayrollBatch(
                      row.id,
                      `${row.statementType === "BONUS" ? "bonus" : "payroll"}_batch_${row.targetYearMonth}.zip`,
                    )
                  }
                >
                  一括PDF出力
                </button>
              </div>
            ),
          },
        ]}
      />
    </>
  );
}
