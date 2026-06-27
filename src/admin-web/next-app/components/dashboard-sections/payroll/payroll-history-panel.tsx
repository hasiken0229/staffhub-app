import { DataTable } from "@/components/data-table";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

type PayrollHistoryPanelProps = {
  data: PayrollSectionProps["data"];
  actions: PayrollSectionProps["actions"];
  formatters: PayrollSectionProps["formatters"];
};

export function PayrollHistoryPanel({ data, actions, formatters }: PayrollHistoryPanelProps) {
  return (
    <DataTable
      id="payroll-history"
      title="CSV・PDF履歴"
      rows={data.filteredPayrollHistory}
      emptyMessage="履歴はまだありません"
      columns={[
        { key: "createdAt", header: "実行日時", render: (row) => formatters.formatDateTime(row.createdAt) },
        { key: "importType", header: "区分", render: (row) => formatters.formatImportType(row.importType) },
        { key: "sourceFileName", header: "ファイル", render: (row) => row.downloadFileName ?? row.sourceFileName },
        { key: "targetPeriod", header: "対象期間", render: (row) => row.targetPeriod ?? "-" },
        { key: "successCount", header: "成功", render: (row) => row.successCount },
        { key: "errorCount", header: "失敗", render: (row) => row.errorCount },
        { key: "downloadAvailable", header: "再取得", render: (row) => (row.downloadAvailable ? "可" : "不可") },
        {
          key: "action",
          header: "操作",
          render: (row) =>
            row.downloadAvailable ? (
              <button
                type="button"
                className="table-action"
                onClick={() => void actions.onFileHistoryDownload(row.id, row.downloadFileName ?? row.sourceFileName)}
              >
                再取得
              </button>
            ) : (
              "-"
            ),
        },
      ]}
    />
  );
}
