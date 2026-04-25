import { DataTable } from "@/components/data-table";
import type { LeaveSectionProps } from "./leave-section-types";

type LeaveBalancesPanelProps = {
  dashboard: LeaveSectionProps["data"]["dashboard"];
  formatters: Pick<LeaveSectionProps["formatters"], "formatLeaveLedgerEntryType">;
};

export function LeaveBalancesPanel({ dashboard, formatters }: LeaveBalancesPanelProps) {
  return (
    <DataTable
      id="leave-balances"
      title="有給残数一覧"
      rows={dashboard.paidLeaveReport}
      emptyMessage="有給データはありません"
      columns={[
        { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
        { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
        { key: "departmentName", header: "所属", render: (row) => row.departmentName ?? "-" },
        { key: "currentBalance", header: "残数", render: (row) => `${row.currentBalance}日` },
        { key: "latestEntryType", header: "最新区分", render: (row) => formatters.formatLeaveLedgerEntryType(row.latestEntryType) },
      ]}
    />
  );
}
