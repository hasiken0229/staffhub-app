import { useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";
import { formatCheckItem, formatCheckItems } from "@/components/dashboard-sections/attendance/attendance-section-utils";

type AttendanceMonthClosePanelProps = {
  dashboard: AttendanceSectionProps["data"]["dashboard"];
  attendanceCloseResult: string;
  attendanceIsLoading: boolean;
  attendanceMonthDataFresh: boolean;
  attendanceLoadError: string;
  targetMonth: string;
  formatters: Pick<AttendanceSectionProps["formatters"], "formatDateTime" | "formatCloseStatus">;
  onAttendanceMonthClose: AttendanceSectionProps["actions"]["onAttendanceMonthClose"];
  onReviewBlocker?: (code: string) => void;
};

type AttendanceExportPanelProps = {
  employeeCode?: string;
  departmentName?: string;
  targetMonth: string;
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
  attendanceIsLoading,
  attendanceMonthDataFresh,
  attendanceLoadError,
  targetMonth,
  formatters,
  onAttendanceMonthClose,
  onReviewBlocker,
}: AttendanceMonthClosePanelProps) {
  const [pendingCloseStatus, setPendingCloseStatus] = useState<"OPEN" | "CLOSED" | null>(null);
  const monthlyClose = dashboard.attendanceMonthlyClose;
  const monthClosePrecheck = dashboard.attendanceMonthClosePrecheck;
  const payrollWarnings = monthClosePrecheck?.payrollWarnings ?? [];
  const canClose = attendanceMonthDataFresh && monthClosePrecheck?.canClose === true;
  const checkStatus = attendanceLoadError
    ? "取得失敗"
    : attendanceIsLoading
      ? "確認中"
      : !attendanceMonthDataFresh
        ? "未確認"
        : monthClosePrecheck?.canClose === true
          ? "完了"
          : "未処理あり";

  const blockers = formatCheckItems(monthClosePrecheck?.blockers) || "なし";
  const payrollBlockers = formatCheckItems(monthClosePrecheck?.payrollBlockers) || "なし";

  return (
    <section id="attendance-close" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>月締め</h3>
        </div>
      </div>
      <div className="stack-form">
        <p className="compact-empty">
          対象月: {targetMonth} / 状態: {attendanceMonthDataFresh ? formatters.formatCloseStatus(monthlyClose.status) : "未確認"}
        </p>
        <p className="compact-empty">
          日次件数 {attendanceMonthDataFresh ? `${monthlyClose.dailyCount} 件` : "未取得"} / 締め済み {attendanceMonthDataFresh ? `${monthlyClose.closedDailyCount} 件` : "未取得"} / 未締め {attendanceMonthDataFresh ? `${monthlyClose.openDailyCount} 件` : "未取得"}
        </p>
        <p className="compact-empty">
          承認待ち {attendanceMonthDataFresh ? `${monthlyClose.pendingApprovalCount} 件` : "未取得"} / 給与取込バッチ {attendanceMonthDataFresh ? `${monthlyClose.payrollBatchCount} 件` : "未取得"}
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
            <strong>{checkStatus}</strong>
            <p className="compact-empty">{attendanceLoadError || (attendanceIsLoading ? "対象月の勤怠・月締前チェックを取得しています。" : attendanceMonthDataFresh ? formatCheckItems(monthClosePrecheck?.blockers) : "対象月の確認結果は未取得です。")}</p>
          </div>
          <div>
            <span className="detail-label">給与連携前チェック</span>
            <strong>{!attendanceMonthDataFresh ? "未確認" : monthClosePrecheck?.payrollReady ? "準備完了" : "確認が必要"}</strong>
            {attendanceMonthDataFresh && monthClosePrecheck?.payrollBlockers?.length ? (
              <ul>{monthClosePrecheck.payrollBlockers.map(item => <li key={item.code}>{formatCheckItem(item)}　{item.message}</li>)}</ul>
            ) : <p className="compact-empty">{attendanceMonthDataFresh ? "未処理項目はありません。" : "対象月の確認結果は未取得です。"}</p>}
          </div>
          <div>
            <span className="detail-label">給与連携の追加注意事項</span>
            <strong>{attendanceMonthDataFresh ? (payrollWarnings.length > 0 ? "追加の注意あり" : "追加の注意事項なし") : "未確認"}</strong>
            {attendanceMonthDataFresh && payrollWarnings.length ? (
              <ul>{payrollWarnings.map(item => <li key={item.code + item.message}>{formatCheckItem(item)}　{item.message}</li>)}</ul>
            ) : <p className="compact-empty">{attendanceMonthDataFresh ? "追加の注意事項はありません。月締め・給与連携の可否は上のチェック結果を確認してください。" : "対象月の確認結果を取得していません。"}</p>}
          </div>
        </div>
        {attendanceMonthDataFresh && (monthClosePrecheck?.blockers.length ?? 0) > 0 ? (
          <div aria-label="月締めできない理由">
            <p>人日は職員ごとの日数の合計、暦日はカレンダー上の日数です。</p>
            <ul className="stack-form">{monthClosePrecheck?.blockers.map(item => <li key={item.code}>
              <strong>{formatCheckItem(item)}</strong>{item.message ? <p className="compact-empty">{item.message}</p> : null}
              {onReviewBlocker ? <button type="button" className="text-link-button" onClick={() => onReviewBlocker(item.code)}>{item.label}の職員・日付を確認</button> : null}
            </li>)}</ul>
          </div>
        ) : null}
      </div>
      <div className="button-row">
        {monthlyClose.status === "CLOSED" ? (
          <button type="button" className="secondary" onClick={() => setPendingCloseStatus("OPEN")} disabled={!attendanceMonthDataFresh}>
            締めを解除する
          </button>
        ) : (
          <button type="button" onClick={() => setPendingCloseStatus("CLOSED")} disabled={!canClose}>
            この月を締める
          </button>
        )}
      </div>
      {!canClose ? (
        <p className="compact-empty">{!attendanceMonthDataFresh ? "対象月の確認結果が取得できるまで、月締めできません。" : "月締前チェックの未処理項目を解消してから月締めしてください。"}</p>
      ) : null}
      {attendanceCloseResult ? <p className="feedback">{attendanceCloseResult}</p> : null}
      <ConfirmDialog
        open={pendingCloseStatus !== null}
        title={pendingCloseStatus === "OPEN" ? "月締めを解除しますか？" : "この月を締めますか？"}
        message={pendingCloseStatus === "OPEN"
          ? `対象月: ${targetMonth}。解除後は勤怠の編集・承認を再開できます。`
          : `対象月: ${targetMonth}／日次件数: ${monthlyClose.dailyCount}件／承認待ち: ${monthlyClose.pendingApprovalCount}件／未処理: ${blockers}／給与連携: ${payrollBlockers}。締め後は打刻・日次再計算・勤怠承認の更新が停止します。`}
        confirmLabel={pendingCloseStatus === "OPEN" ? "締めを解除" : "この月を締める"}
        onCancel={() => setPendingCloseStatus(null)}
        onConfirm={() => {
          const status = pendingCloseStatus;
          setPendingCloseStatus(null);
          if (status) {
            void onAttendanceMonthClose(status);
          }
        }}
      />
    </section>
  );
}

export function AttendanceExportPanel({ targetMonth, reportFrom, reportTo, employeeCode, departmentName, actions }: AttendanceExportPanelProps) {
  const [pendingExport, setPendingExport] = useState<{ label: string; format: string; action: () => Promise<void> } | null>(null);
  const filters = { employeeCode, departmentName };
  const scope = `対象月: ${targetMonth}／職員番号: ${employeeCode || "すべて"}／部門: ${departmentName || "すべて"}`;

  return (
    <section id="attendance-export" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>日次勤怠の出力</h3>
          <p>{scope}（職員番号・部門は検索と同じ部分一致、承認状態はすべて）</p>
          <p>日次CSVの期間: {reportFrom} - {reportTo}</p>
        </div>
      </div>
      <div className="button-row">
        <button
          type="button"
          onClick={() => setPendingExport({ label: "月次CSV", format: "CSV", action: () => actions.onDownloadMonthlyAttendanceCsv(targetMonth, filters) })}
        >
          月次CSV
        </button>
        <button
          type="button"
          className="secondary"
          onClick={() => setPendingExport({ label: "日次CSV", format: "CSV", action: () => actions.onDownloadDailyAttendanceCsv(reportFrom, reportTo, filters) })}
        >
          日次CSV
        </button>
        <button
          type="button"
          className="secondary"
          onClick={() => setPendingExport({ label: "日次PDF", format: "PDF", action: () => actions.onDownloadDailyAttendancePdf(targetMonth, filters) })}
        >
          日次PDF
        </button>
      </div>
      <ConfirmDialog
        open={pendingExport !== null}
        title={`${pendingExport?.label ?? "勤怠データ"}を出力しますか？`}
        message={`${scope}／対象期間: ${pendingExport?.label === "日次CSV" ? `${reportFrom} - ${reportTo}` : `${targetMonth}の1日から月末`}／承認状態: すべて／形式: ${pendingExport?.format ?? "-"}`}
        confirmLabel="出力する"
        onCancel={() => setPendingExport(null)}
        onConfirm={() => {
          const exportAction = pendingExport;
          setPendingExport(null);
          if (exportAction) {
            void exportAction.action();
          }
        }}
      />
    </section>
  );
}
