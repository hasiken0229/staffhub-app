import { DailyAttendanceGraph } from "@/components/daily-attendance-graph";
import { DataTable } from "@/components/data-table";
import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";
import { formatErrorHistory, formatHandlingStatus } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import { ApprovalStatusBadge, ReceiveStatusBadge } from "@/components/status-badge";
import type { AttendanceDaily, AttendanceErrorReportRow } from "@/types";

type AttendanceTablesPanelProps = {
  activePanel: string;
  data: AttendanceSectionProps["data"];
  filters: AttendanceSectionProps["filters"];
  actions: Pick<
    AttendanceSectionProps["actions"],
    | "onAttendanceDecision"
    | "onAttendanceDailyEditRequestDecision"
    | "onBulkAttendanceDecision"
    | "onAttendanceErrorStatus"
    | "onAttendanceDecisionCommentChange"
  >;
  formatters: AttendanceSectionProps["formatters"];
  onOpenDailyEditor: (id?: number | null) => Promise<void>;
};

export function AttendanceTablesPanel({ activePanel, data, filters, actions, formatters, onOpenDailyEditor }: AttendanceTablesPanelProps) {
  if (activePanel === "attendance-daily-list") {
    const dailyRows = [...data.dashboard.dailyGrid].sort((left, right) => Number(hasDailyAlert(right)) - Number(hasDailyAlert(left)));

    return (
      <DataTable
        id="attendance-daily-list"
        title="日次勤怠一覧"
        rows={dailyRows}
        emptyMessage="対象月の日次勤怠はありません"
        rowClassName={(row) => (hasDailyAlert(row) ? "table-row-alert" : undefined)}
        columns={[
          { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
          { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
          { key: "targetDate", header: "日付", render: (row) => formatters.formatDateOnly(row.targetDate) },
          { key: "workStyleName", header: "勤務区分", render: (row) => row.workStyleName ?? "-" },
          { key: "clockInAt", header: "出勤", render: (row) => formatters.formatTimeOnly(row.clockInAt) },
          { key: "clockOutAt", header: "退勤", render: (row) => formatters.formatTimeOnly(row.clockOutAt) },
          { key: "breakMinutes", header: "休憩", render: (row) => `${row.breakMinutes ?? 0}分` },
          { key: "graph", header: "勤務グラフ", render: (row) => <DailyAttendanceGraph row={row} /> },
          { key: "alerts", header: "アラート", render: (row) => row.alertSummary ?? "-" },
          { key: "manual", header: "補正", render: (row) => (row.isManuallyEdited ? "手動" : "-") },
          { key: "approvalStatus", header: "承認", render: (row) => <ApprovalStatusBadge value={row.approvalStatus} format={formatters.formatApprovalStatus} /> },
          { key: "closeStatus", header: "月締", render: (row) => formatters.formatCloseStatus(row.closeStatus) },
          {
            key: "edit",
            header: "操作",
            render: (row) =>
              row.id ? (
                <button type="button" className="table-action" onClick={() => void onOpenDailyEditor(row.id)}>
                  修正
                </button>
              ) : (
                "-"
              ),
          },
        ]}
      />
    );
  }

  if (activePanel === "attendance-approvals") {
    return (
      <section id="attendance-approvals" className="split anchor-panel">
        <DataTable
          title="勤怠承認待ち"
          rows={data.dashboard.attendanceApprovals}
          emptyMessage="承認待ちの日次勤怠はありません"
          columns={[
            { key: "targetDate", header: "日付", render: (row) => formatters.formatDateOnly(row.targetDate) },
            { key: "employeeName", header: "職員", render: (row) => row.employeeName },
            { key: "workMinutes", header: "勤務", render: (row) => formatters.formatWorkMinutes(row.workMinutes) },
            { key: "overtimeMinutes", header: "残業", render: (row) => `${row.overtimeMinutes ?? 0}分` },
            { key: "alerts", header: "アラート", render: (row) => row.alertSummary ?? "-" },
            {
              key: "actions",
              header: "操作",
              render: (row) => (
                <div className="button-row">
                  <button type="button" className="table-action" onClick={() => row.id && void actions.onAttendanceDecision(row.id, "approve")} disabled={!row.id}>
                    承認
                  </button>
                  <button type="button" className="table-action" onClick={() => row.id && void actions.onAttendanceDecision(row.id, "return")} disabled={!row.id}>
                    差戻し
                  </button>
                </div>
              ),
            },
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>勤怠承認コメント</h3>
            </div>
          </div>
          <div className="button-row">
            <button type="button" onClick={() => void actions.onBulkAttendanceDecision("approve")}>
              表示中を一括承認
            </button>
            <button type="button" className="secondary" onClick={() => void actions.onBulkAttendanceDecision("return")}>
              表示中を一括差戻し
            </button>
          </div>
          <label>
            コメント
            <textarea
              rows={5}
              value={filters.attendanceDecisionComment}
              onChange={(event) => actions.onAttendanceDecisionCommentChange(event.target.value)}
            />
          </label>
          {data.attendanceDecisionResult ? <p className="feedback">{data.attendanceDecisionResult}</p> : null}
        </section>
      </section>
    );
  }

  if (activePanel === "attendance-errors") {
    return (
      <section id="attendance-errors" className="stack-section anchor-panel">
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>勤怠エラー対応コメント</h3>
            </div>
          </div>
          <label>
            コメント
            <textarea
              rows={3}
              value={filters.attendanceDecisionComment}
              onChange={(event) => actions.onAttendanceDecisionCommentChange(event.target.value)}
            />
          </label>
        </section>
        <DataTable
          id="attendance-errors"
          title="勤怠エラーレポート"
          rows={data.dashboard.attendanceErrors ?? []}
          emptyMessage="該当する勤怠エラーはありません"
          rowClassName={(row) => errorRowClassName(row)}
          columns={[
            { key: "targetDate", header: "日付", render: (row) => formatters.formatDateOnly(row.targetDate) },
            { key: "errorName", header: "エラー名", render: (row) => row.errorName },
            { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
            { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
            { key: "departmentName", header: "部門", render: (row) => row.departmentName ?? "-" },
            { key: "locationName", header: "拠点", render: (row) => row.locationName ?? "-" },
            { key: "approvalStatus", header: "承認", render: (row) => <ApprovalStatusBadge value={row.approvalStatus} format={formatters.formatApprovalStatus} /> },
            { key: "handlingStatus", header: "対応", render: (row) => formatHandlingStatus(row.handlingStatus) },
            { key: "history", header: "対応履歴", render: (row) => formatErrorHistory(row, formatters.formatDateTime) },
            {
              key: "actions",
              header: "操作",
              render: (row) => (
                <div className="button-row">
                  <button type="button" className="table-action" onClick={() => void onOpenDailyEditor(row.dailyId)}>
                    詳細
                  </button>
                  <button type="button" className="table-action" onClick={() => void actions.onAttendanceErrorStatus(row, "IN_PROGRESS")}>
                    対応中
                  </button>
                  <button
                    type="button"
                    className="table-action"
                    onClick={() => void actions.onAttendanceErrorStatus(row, row.handlingStatus === "RESOLVED" ? "OPEN" : "RESOLVED")}
                  >
                    {row.handlingStatus === "RESOLVED" ? "未対応へ" : "対応済み"}
                  </button>
                  <button type="button" className="table-action" onClick={() => void actions.onAttendanceErrorStatus(row, "IGNORED")}>
                    対象外
                  </button>
                </div>
              ),
            },
          ]}
        />
      </section>
    );
  }

  if (activePanel === "attendance-month-close-status") {
    return (
      <DataTable
        id="attendance-month-close-status"
        title="月締状況レポート"
        rows={data.dashboard.attendanceMonthCloseStatus ?? []}
        emptyMessage="月締状況の対象職員はありません"
        columns={[
          { key: "employeeCode", header: "職員番号", render: (row) => row.employee.employeeCode },
          { key: "name", header: "氏名", render: (row) => row.employee.name },
          { key: "departmentName", header: "部門", render: (row) => row.employee.departmentName ?? "-" },
          { key: "locationName", header: "拠点", render: (row) => row.employee.locationName ?? "-" },
          { key: "unsubmittedCount", header: "未申請（未登録）", render: (row) => `${row.unsubmittedCount}日` },
          { key: "pendingCount", header: "承認待ち", render: (row) => `${row.pendingCount}日` },
          { key: "returnedCount", header: "差戻し", render: (row) => `${row.returnedCount}日` },
          { key: "approvedCount", header: "承認済み", render: (row) => `${row.approvedCount}日` },
          { key: "closeStatus", header: "月締", render: (row) => formatters.formatCloseStatus(row.closeStatus) },
        ]}
      />
    );
  }

  if (activePanel === "attendance-edit-requests") {
    return (
      <DataTable
        id="attendance-edit-requests"
        title="日次修正申請"
        rows={data.dashboard.attendanceDailyEditRequests ?? []}
        emptyMessage="未処理の日次修正申請はありません"
        columns={[
          { key: "createdAt", header: "申請日時", render: (row) => formatters.formatDateTime(row.createdAt) },
          { key: "targetDate", header: "対象日", render: (row) => formatters.formatDateOnly(row.targetDate) },
          { key: "employeeCode", header: "職員番号", render: (row) => row.employee.employeeCode },
          { key: "employeeName", header: "氏名", render: (row) => row.employee.name },
          { key: "clockIn", header: "出勤", render: (row) => `${row.clockInTime ?? "-"}${row.clockInNextDay ? " 翌日" : ""}` },
          { key: "clockOut", header: "退勤", render: (row) => `${row.clockOutTime ?? "-"}${row.clockOutNextDay ? " 翌日" : ""}` },
          { key: "workType", header: "勤務区分", render: (row) => row.workTypeName ?? "-" },
          { key: "comment", header: "申請コメント", render: (row) => row.employeeComment ?? "-" },
          {
            key: "actions",
            header: "操作",
            render: (row) => (
              <div className="button-row">
                <button type="button" className="table-action" onClick={() => void actions.onAttendanceDailyEditRequestDecision(row.id, "approve")}>
                  承認反映
                </button>
                <button type="button" className="table-action" onClick={() => void actions.onAttendanceDailyEditRequestDecision(row.id, "return")}>
                  差戻し
                </button>
              </div>
            ),
          },
        ]}
      />
    );
  }

  if (activePanel === "attendance-events") {
    return (
      <DataTable
        id="attendance-events"
        title="打刻履歴"
        rows={data.dashboard.attendance}
        columns={[
          { key: "occurredAt", header: "時刻", render: (row) => formatters.formatDateTime(row.occurredAt) },
          { key: "employeeName", header: "職員", render: (row) => row.employeeName ?? "-" },
          { key: "eventType", header: "種別", render: (row) => formatters.formatEventType(row.eventType) },
          { key: "receiveStatus", header: "状態", render: (row) => <ReceiveStatusBadge value={row.receiveStatus} format={formatters.formatReceiveStatus} /> },
          { key: "deviceName", header: "端末", render: (row) => row.deviceName },
          { key: "cardUid", header: "カードUID", render: (row) => row.cardUid },
        ]}
      />
    );
  }

  return null;
}

function hasDailyAlert(row: AttendanceDaily): boolean {
  return Boolean(row.alertSummary && row.alertSummary !== "-") || Boolean(row.alerts?.length);
}

function errorRowClassName(row: AttendanceErrorReportRow): string {
  if (row.handlingStatus === "RESOLVED" || row.handlingStatus === "IGNORED") {
    return "table-row-muted";
  }

  return "table-row-alert";
}
