import { DataTable } from "@/components/data-table";
import { CareLeaveBalancesPanel } from "./care-leave-balances-panel";
import { LeaveSourceReviewPanel } from "./leave-source-review-panel";
import type { LeaveSectionProps } from "./leave-section-types";

type LeaveBalancesPanelProps = {
  dashboard: LeaveSectionProps["data"]["dashboard"];
  formatters: Pick<LeaveSectionProps["formatters"], "formatLeaveLedgerEntryType">;
  onSaved: () => Promise<void>;
};

export function LeaveBalancesPanel({ dashboard, formatters, onSaved }: LeaveBalancesPanelProps) {
  return (
    <><DataTable
      id="leave-balances"
      title="有給残数一覧"
      rows={dashboard.paidLeaveReport}
      emptyMessage="有給データはありません"
      columns={[
        { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
        { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
        { key: "departmentName", header: "所属", render: (row) => row.departmentName ?? "-" },
        { key: "currentBalance", header: "残数", render: (row) => `${row.currentBalance}日` },
        { key: "futureApprovedDays", header: "将来の承認済み日数", render: (row) => `${row.futureApprovedDays ?? 0}日` },
        { key: "expiryNeedsReview", header: "付与期限の確認", render: (row) => row.expiryNeedsReview ? "移行付与の期限を要確認" : "-" },
        { key: "hourly", header: "今年度の時間有給", render: (row) => row.hourlyBalance ? `${(row.hourlyBalance.remainingAsOfMinutes ?? row.hourlyBalance.remainingMinutes) / 60}時間（将来承認済み ${(row.hourlyBalance.futureApprovedMinutes ?? 0) / 60}時間・申請可能 ${row.hourlyBalance.availableMinutes / 60}時間）` : "未設定" },
        { key: "hourlyExpiry", header: "時間有給の期限", render: (row) => row.hourlyBalance?.expiresOn ?? "-" },
        { key: "latestEntryType", header: "最新区分", render: (row) => formatters.formatLeaveLedgerEntryType(row.latestEntryType) },
      ]}
    /><LeaveSourceReviewPanel employees={dashboard.employees} onSaved={onSaved} /><CareLeaveBalancesPanel /></>
  );
}
