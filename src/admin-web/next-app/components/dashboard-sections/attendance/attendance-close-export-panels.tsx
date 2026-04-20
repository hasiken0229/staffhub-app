import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";
import { formatCheckItems } from "@/components/dashboard-sections/attendance/attendance-section-utils";

type AttendanceMonthClosePanelProps = {
  dashboard: AttendanceSectionProps["data"]["dashboard"];
  attendanceCloseResult: string;
  formatters: Pick<AttendanceSectionProps["formatters"], "formatDateTime" | "formatCloseStatus">;
  onAttendanceMonthClose: AttendanceSectionProps["actions"]["onAttendanceMonthClose"];
};

type AttendanceExportPanelProps = {
  reportMonth: string;
  reportFrom: string;
  reportTo: string;
  actions: Pick<
    AttendanceSectionProps["actions"],
    "onDownloadMonthlyAttendanceCsv" | "onDownloadDailyAttendanceCsv" | "onDownloadDailyAttendancePdf"
  >;
};

export function AttendanceMonthClosePanel({
  dashboard,
  attendanceCloseResult,
  formatters,
  onAttendanceMonthClose,
}: AttendanceMonthClosePanelProps) {
  const monthlyClose = dashboard.attendanceMonthlyClose;
  const monthClosePrecheck = dashboard.attendanceMonthClosePrecheck;
  const payrollWarnings = monthClosePrecheck?.payrollWarnings ?? [];

  return (
    <section id="attendance-close" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>月締め</h3>
        </div>
      </div>
      <div className="stack-form">
        <p className="compact-empty">
          対象月: {monthlyClose.targetYearMonth} / 状態: {formatters.formatCloseStatus(monthlyClose.status)}
        </p>
        <p className="compact-empty">
          日次件数 {monthlyClose.dailyCount} 件 / 締め済み {monthlyClose.closedDailyCount} 件 / 未締め {monthlyClose.openDailyCount} 件
        </p>
        <p className="compact-empty">
          承認待ち {monthlyClose.pendingApprovalCount} 件 / 給与取込バッチ {monthlyClose.payrollBatchCount} 件
        </p>
        <p className="compact-empty">締め済み月は打刻、日次再計算、勤怠承認の更新を止めます。</p>
        {monthlyClose.closedAt ? (
          <p className="compact-empty">
            最終締め: {formatters.formatDateTime(monthlyClose.closedAt)}
            {monthlyClose.closedByName ? ` / ${monthlyClose.closedByName}` : ""}
          </p>
        ) : null}
        {monthlyClose.reopenedAt ? (
          <p className="compact-empty">
            最終締め解除: {formatters.formatDateTime(monthlyClose.reopenedAt)}
            {monthlyClose.reopenedByName ? ` / ${monthlyClose.reopenedByName}` : ""}
          </p>
        ) : null}
        {monthlyClose.note ? <p className="compact-empty">メモ: {monthlyClose.note}</p> : null}
        <div className="summary-strip">
          <div>
            <span className="detail-label">月締前チェック</span>
            <strong>{monthClosePrecheck?.canClose === false ? "未処理あり" : "完了"}</strong>
            <p className="compact-empty">{formatCheckItems(monthClosePrecheck?.blockers)}</p>
          </div>
          <div>
            <span className="detail-label">給与連携前チェック</span>
            <strong>{monthClosePrecheck?.payrollReady ? "準備完了" : "確認が必要"}</strong>
            <p className="compact-empty">{formatCheckItems(monthClosePrecheck?.payrollBlockers)}</p>
          </div>
          <div>
            <span className="detail-label">給与連携メモ</span>
            <strong>{payrollWarnings.length > 0 ? "注意あり" : "注意なし"}</strong>
            <p className="compact-empty">{formatCheckItems(payrollWarnings)}</p>
          </div>
        </div>
      </div>
      <div className="button-row">
        {monthlyClose.status === "CLOSED" ? (
          <button type="button" className="secondary" onClick={() => void onAttendanceMonthClose("OPEN")}>
            締めを解除する
          </button>
        ) : (
          <button type="button" onClick={() => void onAttendanceMonthClose("CLOSED")} disabled={monthClosePrecheck?.canClose === false}>
            この月を締める
          </button>
        )}
      </div>
      {monthlyClose.status !== "CLOSED" && monthClosePrecheck?.canClose === false ? (
        <p className="compact-empty">月締前チェックの未処理項目を解消してから月締めしてください。</p>
      ) : null}
      {attendanceCloseResult ? <p className="feedback">{attendanceCloseResult}</p> : null}
    </section>
  );
}

export function AttendanceExportPanel({ reportMonth, reportFrom, reportTo, actions }: AttendanceExportPanelProps) {
  return (
    <section id="attendance-export" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>日次勤怠の出力</h3>
        </div>
      </div>
      <div className="button-row">
        <button type="button" onClick={() => void actions.onDownloadMonthlyAttendanceCsv(reportMonth)}>
          月次CSV
        </button>
        <button type="button" className="secondary" onClick={() => void actions.onDownloadDailyAttendanceCsv(reportFrom, reportTo)}>
          日次CSV
        </button>
        <button type="button" className="secondary" onClick={() => void actions.onDownloadDailyAttendancePdf(reportMonth)}>
          日次PDF
        </button>
      </div>
    </section>
  );
}
