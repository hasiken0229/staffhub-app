import { DataTable } from "@/components/data-table";
import { ApprovalStatusBadge, ReadStatusBadge } from "@/components/status-badge";
import type { EmployeePortalSectionProps } from "./employee-portal-types";
import { MobileRecordList } from "./mobile-record-list";

type EmployeePortalListsProps = {
  data: EmployeePortalSectionProps["data"];
  actions: Pick<
    EmployeePortalSectionProps["actions"],
    "onLoadEmployeePayrollDetail" | "onNotificationRead" | "onPayrollDownload"
  >;
  formatters: EmployeePortalSectionProps["formatters"];
};

export function EmployeePortalLists(props: EmployeePortalListsProps) {
  return (
    <>
      <div className="desktop-only">
        <section className="portal-list-group section-enter delay-2">
          <div className="panel-header">
            <div>
              <p className="panel-kicker">申請一覧</p>
              <h3>申請状況</h3>
            </div>
            <span className="panel-meta">
              休暇 {props.data.employeePortal.leaveRequests.length} 件 / 修正 {props.data.employeePortal.attendanceDailyEditRequests?.length ?? 0} 件
            </span>
          </div>
          <div className="split">
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
                render: (row) => (row.requestCategory === "TIME_LEAVE" ? `${row.quantityMinutes ?? "-"}分` : `${row.quantityDays ?? "-"}日`),
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
          </div>
        </section>

        <section className="portal-list-group section-enter delay-3">
          <div className="panel-header">
            <div>
              <p className="panel-kicker">明細・通知</p>
              <h3>明細・通知</h3>
            </div>
            <span className="panel-meta">
              明細 {props.data.employeePortal.payroll.length} 件 / 通知 {props.data.employeePortal.notifications.length} 件
            </span>
          </div>
          <div className="split">
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
          <DataTable
            id="employee-notices-list"
            title="お知らせ"
            rows={props.data.employeePortal.notifications}
            emptyMessage="お知らせはありません"
            columns={[
              { key: "title", header: "件名", render: (row) => row.title },
              { key: "sentAt", header: "配信日時", render: (row) => props.formatters.formatDateTime(row.sentAt) },
              { key: "sourceType", header: "種別", render: (row) => row.sourceType },
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
          </div>
        </section>

        <section className="portal-list-group section-enter delay-4">
          <div className="panel-header">
            <div>
              <p className="panel-kicker">有給台帳</p>
              <h3>有給台帳</h3>
            </div>
            <span className="panel-meta">{props.data.employeePortal.leaveLedger.length} 件</span>
          </div>
          <DataTable
            title="有給台帳"
            rows={props.data.employeePortal.leaveLedger}
            emptyMessage="有給台帳はまだありません"
            columns={[
              { key: "occurredOn", header: "日付", render: (row) => props.formatters.formatDateOnly(row.occurredOn) },
              { key: "entryType", header: "区分", render: (row) => props.formatters.formatLeaveLedgerEntryType(row.entryType) },
              { key: "daysDelta", header: "増減", render: (row) => `${row.daysDelta}日` },
              { key: "balanceAfter", header: "残高", render: (row) => `${row.balanceAfter ?? "-"}日` },
              { key: "note", header: "備考", render: (row) => row.note ?? "-" },
            ]}
          />
        </section>
      </div>

      <div className="mobile-only stack-section section-enter delay-2">
        <MobileRecordList
          id="employee-leave-list-mobile"
          title="休暇申請の一覧"
          rows={props.data.employeePortal.leaveRequests}
          emptyMessage="休暇申請はまだありません"
          renderTitle={(row) => (row.requestCategory === "TIME_LEAVE" ? "時間休暇" : row.leaveTypeName)}
          renderMeta={(row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} />}
          renderBody={(row) => row.reason ?? "申請理由の入力はありません"}
          renderFields={(row) => [
            {
              label: "期間",
              value:
                row.requestCategory === "TIME_LEAVE"
                  ? `${props.formatters.formatDateOnly(row.targetDate)} ${row.startTime ?? ""}-${row.endTime ?? ""}`
                  : `${props.formatters.formatDateOnly(row.startDate)} - ${props.formatters.formatDateOnly(row.endDate)}`,
            },
            {
              label: row.requestCategory === "TIME_LEAVE" ? "分数" : "日数",
              value: row.requestCategory === "TIME_LEAVE" ? `${row.quantityMinutes ?? "-"}分` : `${row.quantityDays ?? "-"}日`,
            },
            {
              label: "単位",
              value: row.dayUnit === "HALF" ? `半日${row.halfDayType ? ` (${row.halfDayType})` : ""}` : "全日",
            },
            {
              label: "申請日時",
              value: props.formatters.formatDateTime(row.createdAt),
            },
          ]}
        />

        <MobileRecordList
          title="日次修正申請の一覧"
          rows={props.data.employeePortal.attendanceDailyEditRequests ?? []}
          emptyMessage="日次修正申請はまだありません"
          renderTitle={(row) => props.formatters.formatDateOnly(row.targetDate)}
          renderMeta={(row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} />}
          renderBody={(row) => row.employeeComment ?? row.remark ?? "申請コメントの入力はありません"}
          renderFields={(row) => [
            {
              label: "出退勤",
              value: `${row.clockInTime ?? "-"} - ${row.clockOutTime ?? "-"}`,
            },
            {
              label: "休憩",
              value: row.breaks.length > 0 ? row.breaks.map((item) => `${item.startTime ?? "-"}-${item.endTime ?? "-"}`).join(" / ") : "-",
            },
            {
              label: "申請日時",
              value: props.formatters.formatDateTime(row.createdAt),
            },
          ]}
        />

        <MobileRecordList
          id="employee-payroll-list-mobile"
          title="給与・賞与明細"
          rows={props.data.employeePortal.payroll}
          emptyMessage="公開済みの明細はありません"
          renderTitle={(row) => `${row.statementTypeLabel ?? "明細"} / ${row.targetYearMonth}`}
          renderMeta={(row) => props.formatters.formatMonthDay(row.payDate ?? row.publishedAt)}
          renderFields={(row) => [
            {
              label: "種別",
              value: row.statementTypeLabel ?? "-",
            },
            {
              label: "対象年月",
              value: row.targetYearMonth,
            },
            {
              label: "公開日時",
              value: props.formatters.formatDateTime(row.publishedAt),
            },
            {
              label: "ファイル名",
              value: row.originalFileName,
            },
          ]}
          renderActions={(row) => (
            <>
              <button type="button" className="table-action" onClick={() => void props.actions.onLoadEmployeePayrollDetail(row.id)}>
                明細を見る
              </button>
              <button type="button" className="table-action" onClick={() => void props.actions.onPayrollDownload(row.id, row.originalFileName)}>
                PDF保存
              </button>
            </>
          )}
        />
      </div>

      {props.data.selectedPayrollDetailCard ? <section className="section-enter delay-3">{props.data.selectedPayrollDetailCard}</section> : null}

      <div className="mobile-only stack-section section-enter delay-3">
        <MobileRecordList
          id="employee-notices-list-mobile"
          title="お知らせ"
          rows={props.data.employeePortal.notifications}
          emptyMessage="お知らせはありません"
          renderTitle={(row) => row.title}
          renderMeta={(row) => <ReadStatusBadge isRead={row.isRead} />}
          renderBody={(row) => row.body}
          renderFields={(row) => [
            {
              label: "配信日時",
              value: props.formatters.formatDateTime(row.sentAt),
            },
            {
              label: "種別",
              value: row.sourceType,
            },
          ]}
          renderActions={(row) =>
            row.isRead ? null : (
              <button type="button" className="table-action" onClick={() => void props.actions.onNotificationRead(row.id, row.sourceType)}>
                既読にする
              </button>
            )
          }
        />

        <MobileRecordList
          title="有給台帳"
          rows={props.data.employeePortal.leaveLedger}
          emptyMessage="有給台帳はまだありません"
          renderTitle={(row) => props.formatters.formatLeaveLedgerEntryType(row.entryType)}
          renderMeta={(row) => `${row.balanceAfter ?? "-"}日`}
          renderBody={(row) => row.note ?? "備考はありません"}
          renderFields={(row) => [
            {
              label: "日付",
              value: props.formatters.formatDateOnly(row.occurredOn),
            },
            {
              label: "増減",
              value: `${row.daysDelta}日`,
            },
            {
              label: "残高",
              value: `${row.balanceAfter ?? "-"}日`,
            },
            {
              label: "元データ",
              value: row.sourceType,
            },
          ]}
        />
      </div>
    </>
  );
}
