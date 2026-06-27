import { DataTable } from "@/components/data-table";
import { ApprovalStatusBadge, ReadStatusBadge } from "@/components/status-badge";
import { formatDurationMinutes } from "@/lib/api/formatters";
import type { EmployeePortalTab } from "../employee-portal-section";
import type { EmployeePortalSectionProps } from "./employee-portal-types";

type EmployeePortalListsProps = {
  data: EmployeePortalSectionProps["data"];
  actions: Pick<
    EmployeePortalSectionProps["actions"],
    "onLoadEmployeePayrollDetail" | "onNotificationRead" | "onPayrollDownload"
  >;
  formatters: EmployeePortalSectionProps["formatters"];
  activeTab: Extract<EmployeePortalTab, "requests" | "payroll" | "notices" | "ledger">;
};

export function EmployeePortalLists(props: EmployeePortalListsProps) {
  if (props.activeTab === "requests") {
    return (
      <section className="portal-list-group section-enter delay-2" aria-label="申請状況">
        <DataTable
          id="employee-leave-list"
          title="休暇申請の一覧"
          rows={props.data.employeePortal.leaveRequests}
          emptyMessage="休暇申請はまだありません"
          columns={[
            {
              key: "leaveTypeName",
              header: "区分",
              render: (row) => (row.requestCategory === "TIME_LEAVE" ? "時間休暇" : row.leaveTypeName),
            },
            {
              key: "period",
              header: "期間",
              render: (row) =>
                row.requestCategory === "TIME_LEAVE"
                  ? `${props.formatters.formatDateOnly(row.targetDate)} ${row.startTime ?? ""}-${row.endTime ?? ""}`
                  : `${props.formatters.formatDateOnly(row.startDate)} - ${props.formatters.formatDateOnly(row.endDate)}`,
            },
            {
              key: "quantityDays",
              header: "日数/分",
              render: (row) => (row.requestCategory === "TIME_LEAVE" ? formatDurationMinutes(row.quantityMinutes ?? null) : `${row.quantityDays ?? "-"}日`),
            },
            { key: "status", header: "状態", render: (row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} /> },
          ]}
        />

        <DataTable
          title="日次修正申請の一覧"
          rows={props.data.employeePortal.attendanceDailyEditRequests ?? []}
          emptyMessage="日次修正申請はまだありません"
          columns={[
            { key: "targetDate", header: "対象日", render: (row) => props.formatters.formatDateOnly(row.targetDate) },
            { key: "time", header: "出退勤", render: (row) => `${row.clockInTime ?? "-"} - ${row.clockOutTime ?? "-"}` },
            { key: "status", header: "状態", render: (row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} /> },
            { key: "comment", header: "コメント", render: (row) => row.decisionComment ?? row.employeeComment ?? "-" },
          ]}
        />
      </section>
    );
  }

  if (props.activeTab === "payroll") {
    return (
      <section className="portal-list-group section-enter delay-2" aria-label="給与明細">
        <DataTable
          id="employee-payroll-list"
          title="給与・賞与明細"
          rows={props.data.employeePortal.payroll}
          emptyMessage="公開済みの明細はありません"
          columns={[
            { key: "statementTypeLabel", header: "種別", render: (row) => row.statementTypeLabel ?? "-" },
            { key: "targetYearMonth", header: "対象年月", render: (row) => row.targetYearMonth },
            { key: "payDate", header: "支給日", render: (row) => props.formatters.formatMonthDay(row.payDate ?? row.publishedAt) },
            { key: "publishedAt", header: "公開日時", render: (row) => props.formatters.formatDateTime(row.publishedAt) },
            {
              key: "download",
              header: "操作",
              render: (row) => (
                <div className="button-row">
                  <button type="button" className="table-action" onClick={() => void props.actions.onLoadEmployeePayrollDetail(row.id)}>
                    明細を見る
                  </button>
                  <button type="button" className="table-action" onClick={() => void props.actions.onPayrollDownload(row.id, row.originalFileName)}>
                    PDF保存
                  </button>
                </div>
              ),
            },
          ]}
        />
        {props.data.selectedPayrollDetailCard ? <section className="section-enter delay-3">{props.data.selectedPayrollDetailCard}</section> : null}
      </section>
    );
  }

  if (props.activeTab === "notices") {
    return (
      <section className="portal-list-group section-enter delay-2" aria-label="通知">
        <DataTable
          id="employee-notices-list"
          title="お知らせ"
          rows={props.data.employeePortal.notifications}
          emptyMessage="お知らせはありません"
          columns={[
            { key: "title", header: "件名", render: (row) => row.title },
            { key: "sentAt", header: "配信日時", render: (row) => props.formatters.formatDateTime(row.sentAt) },
            { key: "sourceType", header: "種別", render: (row) => formatNotificationSourceType(row.sourceType) },
            {
              key: "read",
              header: "既読",
              render: (row) =>
                row.isRead ? (
                  <ReadStatusBadge isRead={row.isRead} />
                ) : (
                  <button type="button" className="table-action" onClick={() => void props.actions.onNotificationRead(row.id, row.sourceType)}>
                    既読にする
                  </button>
                ),
            },
          ]}
        />
      </section>
    );
  }

  return (
    <section className="portal-list-group section-enter delay-2" aria-label="有給台帳">
      <DataTable
        title="有給台帳"
        rows={props.data.employeePortal.leaveLedger}
        emptyMessage="有給台帳はまだありません"
        columns={[
          { key: "occurredOn", header: "日付", render: (row) => props.formatters.formatDateOnly(row.occurredOn) },
          { key: "entryType", header: "区分", render: (row) => props.formatters.formatLeaveLedgerEntryType(row.entryType) },
          { key: "daysDelta", header: "増減", render: (row) => `${row.daysDelta}日` },
          { key: "balanceAfter", header: "残高", render: (row) => `${row.balanceAfter ?? "-"}日` },
          { key: "sourceType", header: "元データ", render: (row) => formatLedgerSourceType(row.sourceType) },
          { key: "note", header: "備考", render: (row) => row.note ?? "-" },
        ]}
      />
    </section>
  );
}

function formatNotificationSourceType(value?: string | null) {
  return (
    {
      PERSONAL: "個別通知",
      NOTICE: "お知らせ",
      PAYROLL: "給与明細",
      BONUS: "賞与明細",
    }[value ?? ""] ?? value ?? "-"
  );
}

function formatLedgerSourceType(value?: string | null) {
  return (
    {
      LEAVE_REQUEST: "休暇申請",
      PAID_LEAVE_GRANT: "有給付与",
      PAID_LEAVE_ADJUSTMENT: "有給調整",
      PERSONAL: "個別通知",
    }[value ?? ""] ?? value ?? "-"
  );
}
