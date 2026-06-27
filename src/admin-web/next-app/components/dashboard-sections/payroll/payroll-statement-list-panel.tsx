import { DataTable } from "@/components/data-table";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

type PayrollStatementListPanelProps = {
  data: PayrollSectionProps["data"];
  actions: PayrollSectionProps["actions"];
  formatters: PayrollSectionProps["formatters"];
};

export function PayrollStatementListPanel({ data, actions, formatters }: PayrollStatementListPanelProps) {
  return (
    <DataTable
      id="payroll-statements"
      title={`公開済み${data.payrollTypeLabel}一覧`}
      rows={data.filteredPayrollStatements}
      emptyMessage="公開済み明細はまだありません"
      columns={[
        { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode ?? "-" },
        { key: "employeeName", header: "氏名", render: (row) => row.employeeName ?? "-" },
        { key: "targetYearMonth", header: "対象月", render: (row) => row.targetYearMonth },
        { key: "payDate", header: "支給日", render: (row) => formatters.formatMonthDay(row.payDate ?? row.publishedAt) },
        { key: "definitionName", header: "データ定義", render: (row) => row.definitionName ?? "-" },
        { key: "publishedAt", header: "公開日時", render: (row) => formatters.formatDateTime(row.publishedAt) },
        {
          key: "action",
          header: "操作",
          render: (row) => (
            <div className="button-row">
              <button type="button" className="table-action" onClick={() => void actions.onLoadAdminPayrollDetail(row.id)}>
                個票詳細
              </button>
              <button type="button" className="table-action" onClick={() => void actions.onAdminPayrollDownload(row.id, row.originalFileName)}>
                PDF保存
              </button>
            </div>
          ),
        },
      ]}
    />
  );
}
