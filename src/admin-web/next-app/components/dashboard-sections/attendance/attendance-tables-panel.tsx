import { useRef, useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { AttendanceMonthReviewPanel, attendanceDayLabels } from "./attendance-month-review-panel";
import { DataTable } from "@/components/data-table";
import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";
import { formatErrorHistory, formatHandlingStatus } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import { attendanceDecisionAvailable } from "@/lib/attendance-utils";
import { ApprovalStatusBadge, ReceiveStatusBadge } from "@/components/status-badge";
import type { AttendanceDaily, AttendanceDeviceStatus, AttendanceErrorReportRow } from "@/types";

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
    | "onRetryAttendanceLoad"
  >;
  formatters: AttendanceSectionProps["formatters"];
  onOpenDailyEditor: (id?: number | null) => Promise<void>;
};

export function AttendanceTablesPanel({ activePanel, data, filters, actions, formatters, onOpenDailyEditor }: AttendanceTablesPanelProps) {
  const [showBatch, setShowBatch] = useState(false);
  const [confirmation, setConfirmation] = useState<{title:string;message:string;action:()=>Promise<void>}|null>(null);
  const [busy, setBusy] = useState(false);
  const submitting=useRef(false);
  async function runConfirmed() { if (submitting.current||!confirmation) return; const pending=confirmation;submitting.current=true;setConfirmation(null);setBusy(true);try{await pending.action();}finally{submitting.current=false;setBusy(false);} }
  if (data.attendanceLoadError) {
    return (
      <div className="attendance-load-error" role="alert">
        <strong>勤怠の取得に失敗しました。</strong>
        <span>通信状況を確認して再試行してください。取得に成功するまで件数は確定表示しません。</span>
        <button type="button" className="secondary" onClick={() => void actions.onRetryAttendanceLoad()}>
          勤怠一覧を再試行
        </button>
      </div>
    );
  }

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
          { key: "breakMinutes", header: "休憩（計算・保存値）", render: (row) => <>{formatters.formatWorkMinutes(row.breakMinutes ?? 0)}{row.breakReview ? <><br/>{row.breakReview.sourceLabel}</> : null}</> },
          { key: "work", header: "実働", render: (row) => formatters.formatWorkMinutes(row.workMinutes) },
          { key: "alerts", header: "状況・確認", render: (row) => <>{attendanceDayLabels[row.dayState??""]??"記録あり"}{row.alertSummary&&row.alertSummary!=="-"?<><br/>{row.alertSummary}</>:null}{row.isManuallyEdited?<><br/>{row.breakReview?.source === "ADMIN_RULE" ? "勤務ルール適用" : "手動補正"}</>:null}</> },
          { key: "approvalStatus", header: "承認", render: (row) => <ApprovalStatusBadge value={row.approvalStatus} format={formatters.formatApprovalStatus} /> },
          { key: "closeStatus", header: "月締", render: (row) => formatters.formatCloseStatus(row.closeStatus) },
          {
            key: "edit",
            header: "操作",
            render: (row) =>
              row.id ? (
                <button type="button" className="table-action" onClick={() => void onOpenDailyEditor(row.id)}>
                  詳細
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
    const batchRows = data.dashboard.attendanceApprovals.filter(attendanceDecisionAvailable);
    return (
      <section id="attendance-approvals" className="split anchor-panel">
        <DataTable
          title="対象期間の勤怠承認・差戻し"
          rows={data.dashboard.attendanceApprovals}
          emptyMessage="検索条件に該当する日次勤怠はありません"
          columns={[
            { key: "targetDate", header: "日付", render: (row) => formatters.formatDateOnly(row.targetDate) },
            { key: "employeeName", header: "職員", render: (row) => row.employeeName },
            { key: "workMinutes", header: "勤務", render: (row) => formatters.formatWorkMinutes(row.workMinutes) },
            { key: "overtimeMinutes", header: "残業", render: (row) => formatters.formatWorkMinutes(row.overtimeMinutesOverride ?? row.overtimeMinutes ?? 0) },
            { key: "status", header: "承認状態", render: row => formatters.formatApprovalStatus(row.approvalStatus) },
            { key: "alerts", header: "確認理由", render: (row) => row.approvalReviewReason || row.alertSummary || "—" },
            {
              key: "actions",
              header: "操作",
              render: (row) => (
                <div className="button-row">
                  <button type="button" className="table-action" onClick={() => row.id && void onOpenDailyEditor(row.id)} disabled={busy || !row.id}>詳細</button>
                  <button type="button" className="table-action" onClick={() => row.id && setConfirmation({title:"この勤怠を承認しますか？",message:`${row.employeeName} ${row.targetDate} / 採用 ${formatters.formatTimeOnly(row.clockInAt)}～${formatters.formatTimeOnly(row.clockOutAt)} / 休憩 ${row.breakMinutes??0}分 / ${row.approvalReviewReason || row.alertSummary || "アラートなし"}`,action:()=>actions.onAttendanceDecision(row.id!, "approve")})} disabled={!attendanceDecisionAvailable(row)||busy}>
                    承認
                  </button>
                  <button type="button" className="table-action" onClick={() => row.id && setConfirmation({title:"この勤怠を差し戻しますか？",message:`${row.employeeName} ${row.targetDate}`,action:()=>actions.onAttendanceDecision(row.id!, "return")})} disabled={!attendanceDecisionAvailable(row)||busy}>
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
            <button type="button" onClick={() => setShowBatch(!showBatch)} disabled={busy}>
              一括承認の対象を確認
            </button>
            <button type="button" className="secondary" onClick={() => setConfirmation({title:"表示中の勤怠を一括差戻ししますか？",message:`${filters.attendanceEventFrom}～${filters.attendanceEventTo} / 職員 ${filters.attendanceFilterEmployeeCode||"全員"} / 部門 ${filters.attendanceFilterDepartmentName||"すべて"} / 対象 ${batchRows.length}件`,action:()=>actions.onBulkAttendanceDecision("return")})} disabled={busy||batchRows.length===0}>
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
        {showBatch?<AttendanceMonthReviewPanel targetMonth={filters.attendanceFilterMonth} formatters={formatters} onRefresh={actions.onRetryAttendanceLoad} onOpenDaily={onOpenDailyEditor}/>:null}
        <ConfirmDialog open={confirmation!==null} title={confirmation?.title??"確認"} message={confirmation?.message??""} confirmLabel="実行する" onCancel={()=>setConfirmation(null)} onConfirm={()=>void runConfirmed()}/>
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
          { key: "unsubmittedCount", header: "未登録の内訳", render: (row) => <>{row.unsubmittedCount}日{row.missingBreakdown?<><br/>勤務日候補 {row.missingBreakdown.workday} / 休日候補 {row.missingBreakdown.holiday}<br/>未到来 {row.missingBreakdown.future} / 当日 {row.missingBreakdown.today}</>:null}</> },
          { key: "pendingCount", header: "承認待ち", render: (row) => `${row.pendingCount}日` },
          { key: "returnedCount", header: "差戻し", render: (row) => `${row.returnedCount}日` },
          { key: "approvedCount", header: "承認済み", render: (row) => `${row.approvedCount}日` },
          { key: "closeStatus", header: "月締", render: (row) => formatters.formatCloseStatus(row.closeStatus) },
          { key: "days", header: "日付別", render: row=><details><summary>対象日を確認</summary>{(row.dayDetails??[]).filter(day=>!["APPROVED","NOT_REQUIRED","NOT_DUE"].includes(day.approvalStatus??"")).map(day=><p key={day.targetDate}>{day.targetDate} {attendanceDayLabels[day.state]??day.state} {day.approvalStatus?formatters.formatApprovalStatus(day.approvalStatus):""} {day.dailyId?<button type="button" className="table-action" onClick={()=>void onOpenDailyEditor(day.dailyId)}>詳細</button>:null}</p>)}</details> },
        ]}
      />
    );
  }

  if (activePanel === "attendance-edit-requests") {
    return (
      <><DataTable
        id="attendance-edit-requests"
        title="日次修正申請"
        rows={data.dashboard.attendanceDailyEditRequests ?? []}
        emptyMessage="未処理の日次修正申請はありません"
        columns={[
          { key: "createdAt", header: "申請日時", render: (row) => formatters.formatDateTime(row.createdAt) },
          { key: "targetDate", header: "対象日", render: (row) => formatters.formatDateOnly(row.targetDate) },
          { key: "employeeCode", header: "職員番号", render: (row) => row.employee.employeeCode },
          { key: "employeeName", header: "氏名", render: (row) => row.employee.name },
          { key: "clockIn", header: "出勤 現在→申請後", render: (row) => `${formatters.formatTimeOnly(row.currentDaily?.clockInAt)} → ${row.effectiveClockInAt !== undefined ? formatters.formatDateTime(row.effectiveClockInAt) : (row.clockInNextDay?"翌日 ":"")+(row.clockInTime??"-")}` },
          { key: "clockOut", header: "退勤 現在→申請後", render: (row) => `${formatters.formatTimeOnly(row.currentDaily?.clockOutAt)} → ${row.effectiveClockOutAt !== undefined ? formatters.formatDateTime(row.effectiveClockOutAt) : (row.clockOutNextDay?"翌日 ":"")+(row.clockOutTime??"-")}` },
          { key: "breaks", header: "休憩・実働 現在→申請後", render: row=><>休憩 {row.currentDaily?.breakMinutes??0} → {row.proposedBreakMinutes??0}分<br/>実働 {formatters.formatWorkMinutes(row.currentDaily?.workMinutes)} → {formatters.formatWorkMinutes(row.proposedWorkMinutes)}<details><summary>原打刻・休憩を確認</summary><p>原打刻 {formatters.formatDateTime(row.currentDaily?.rawClockInAt)}～{formatters.formatDateTime(row.currentDaily?.rawClockOutAt)}</p><p>申請休憩 {row.breaks.map(b=>`${b.startNextDay?"翌日 ":""}${b.startTime}～${b.endNextDay?"翌日 ":""}${b.endTime}`).join(" / ")||"なし"}</p><p>備考 {row.currentDaily?.remark??"なし"} → {row.remark??"なし"}</p></details></> },
          { key: "workType", header: "勤務区分", render: (row) => row.workTypeName ?? "-" },
          { key: "comment", header: "申請コメント・変更の確認", render: (row) => <>{row.employeeComment ?? "-"}{row.reviewConflict && <p role="alert">{row.reviewConflict}</p>}{row.reviewChanges?.map(change => <p key={change.label}>{change.label}　申請時 {change.original} ／ 現在 {change.current} ／ 申請値 {change.requested}</p>)}</> },
          {
            key: "actions",
            header: "操作",
            render: (row) => (
              <div className="button-row">
                <button type="button" className="table-action" disabled={busy || Boolean(row.reviewConflict)} onClick={() => setConfirmation({title:"この修正申請を承認して反映しますか？",message:`${row.employee.name} ${row.targetDate} / 実働 ${formatters.formatWorkMinutes(row.currentDaily?.workMinutes)} → ${formatters.formatWorkMinutes(row.proposedWorkMinutes)} / 休憩 ${row.currentDaily?.breakMinutes??0} → ${row.proposedBreakMinutes??0}分`,action:()=>actions.onAttendanceDailyEditRequestDecision(row.id,"approve")})}>
                  承認反映
                </button>
                <button type="button" className="table-action" disabled={busy} onClick={() => setConfirmation({title:"この修正申請を差し戻しますか？",message:`${row.employee.name} ${row.targetDate}`,action:()=>actions.onAttendanceDailyEditRequestDecision(row.id,"return")})}>
                  差戻し
                </button>
              </div>
            ),
          },
        ]}
      /><ConfirmDialog open={confirmation!==null} title={confirmation?.title??"確認"} message={confirmation?.message??""} confirmLabel="実行する" onCancel={()=>setConfirmation(null)} onConfirm={()=>void runConfirmed()}/></>
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

  if (activePanel === "attendance-devices") {
    return (
      <DataTable
        id="attendance-devices"
        title="打刻端末の状態"
        metaLabel="端末から受信した最新の状態"
        rows={data.dashboard.attendanceDevices ?? []}
        emptyMessage="登録済みの打刻端末はありません"
        rowClassName={(row) => deviceRowClassName(row)}
        columns={[
          { key: "name", header: "端末", render: (row) => row.name },
          { key: "deviceCode", header: "端末コード", render: (row) => row.deviceCode },
          { key: "locationName", header: "設置場所", render: (row) => row.locationName ?? "-" },
          { key: "status", header: "状態", render: (row) => formatDeviceStatus(row) },
          { key: "lastSeenAt", header: "最終通信", render: (row) => row.lastSeenAt ? formatters.formatDateTime(row.lastSeenAt) : "未通信" },
          { key: "pendingOfflineCount", header: "未送信", render: (row) => row.diagnosticsSchemaReady === false ? "未対応" : row.pendingOfflineCount === null ? "未確認" : `${row.pendingOfflineCount}件` },
          { key: "clockDriftSeconds", header: "時計ずれ", render: (row) => formatClockDrift(row.clockDriftSeconds) },
          { key: "readerStatus", header: "リーダー", render: (row) => row.readerStatus ?? "未確認" },
          { key: "appVersion", header: "アプリ", render: (row) => row.appVersion ?? "-" },
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

function formatDeviceStatus(row: AttendanceDeviceStatus): string {
  if (row.diagnosticsSchemaReady === false) {
    return `${row.isActive ? "" : "無効 / "}詳細診断未対応（DB更新待ち）`;
  }
  if (!row.isActive) {
    return "無効";
  }

  const issues: string[] = [];
  const absoluteDrift = Math.abs(row.clockDriftSeconds ?? 0);

  if (!row.lastSeenAt) {
    issues.push("未通信");
  }
  if (row.pendingOfflineCount === null) {
    issues.push("端末診断は未確認");
  } else if (row.pendingOfflineCount > 0) {
    issues.push(`未送信 ${row.pendingOfflineCount}件`);
  }
  if (row.clockDriftSeconds !== null && row.clockDriftSeconds !== undefined) {
    if (absoluteDrift > 120) {
      issues.push("時計ずれ 要確認");
    } else if (absoluteDrift > 30) {
      issues.push("時計ずれ 注意");
    }
  }

  return issues.length > 0 ? issues.join(" / ") : "正常";
}

function formatClockDrift(seconds?: number | null): string {
  if (seconds === null || seconds === undefined) {
    return "未確認";
  }

  const prefix = seconds > 0 ? "+" : "";
  return `${prefix}${seconds}秒`;
}

function deviceRowClassName(row: AttendanceDeviceStatus): string | undefined {
  if (!row.isActive) {
    return "table-row-muted";
  }

  if (row.diagnosticsSchemaReady === false || !row.lastSeenAt || row.pendingOfflineCount === null || row.pendingOfflineCount > 0 || Math.abs(row.clockDriftSeconds ?? 0) > 30) {
    return "table-row-alert";
  }

  return undefined;
}
