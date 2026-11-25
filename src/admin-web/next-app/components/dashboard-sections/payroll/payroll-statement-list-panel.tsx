import { formatStatementPayDate } from "@/lib/api/formatters";
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
      title={`登録済み${data.payrollTypeLabel}一覧`}
      rows={data.filteredPayrollStatements}
      emptyMessage="登録済み明細はまだありません"
      columns={[
        { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode ?? "-" },
        { key: "employeeName", header: "氏名", render: (row) => row.employeeName ?? "-" },
        { key: "targetYearMonth", header: "対象月", render: (row) => row.targetYearMonth },
        { key: "publication", header: "公開状態", render: (row) => !row.publishedAt ? "未公開" : new Date(row.publishedAt).getTime() > Date.now() ? "公開予定" : "公開中" },
        { key: "revision", header: "版・差替日時", render: (row) => <>{(row.revision ?? 1) > 1 ? `訂正版 第${row.revision}版` : "初版"}{row.replacedAt ? <><br />{formatters.formatDateTime(row.replacedAt)}</> : null}</> },
        { key: "viewed", header: "現版の閲覧", render: (row) => row.viewCount ? `${row.viewCount}回／${formatters.formatDateTime(row.lastViewedAt)}` : "未閲覧" },
        { key: "payDate", header: "支給日", render: (row) => formatStatementPayDate(row.payDate) },
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
