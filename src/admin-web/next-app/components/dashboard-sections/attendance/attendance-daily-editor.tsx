import { useEffect, useRef, useState, type Dispatch, type SetStateAction } from "react";
import { formatApprovalStatus } from "@/lib/api/formatters";
import { DataTable } from "@/components/data-table";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { FieldError, ValidationSummary, type FieldErrors } from "@/components/form-field-errors";
import { isNextDay, isoToTime } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import { attendanceDayLabels } from "./attendance-month-review-panel";
import { AttendanceBreakReview } from "./attendance-break-review";
import { BreakOvertimeReview } from "./break-overtime-review";
import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";
import type { AttendanceDailyBreak, AttendanceDailyDetail, AttendanceDailyHistory, BreakOvertimeConfirmation } from "@/types";

type AttendanceDailyEditorProps = {
  editingDaily: AttendanceDailyDetail;
  editingBreaks: AttendanceDailyBreak[];
  historyRows: AttendanceDailyHistory[];
  activeEditTab: "detail" | "edit" | "history";
  busy?: boolean;
  fieldErrors?: FieldErrors;
  editError: string;
  editMessage: string;
  dashboard: AttendanceSectionProps["data"]["dashboard"];
  formatters: Pick<AttendanceSectionProps["formatters"], "formatDateOnly" | "formatDateTime" | "formatTimeOnly">;
  onClose: () => void;
  onSave: () => Promise<void>;
  onResetManualEdit: () => Promise<void>;
  onApplyBreakOvertime: (id: number, inputs: BreakOvertimeConfirmation) => Promise<void>;
  onActiveEditTabChange: (value: "detail" | "edit" | "history") => void;
  setEditingDaily: Dispatch<SetStateAction<AttendanceDailyDetail | null>>;
  setEditingBreaks: Dispatch<SetStateAction<AttendanceDailyBreak[]>>;
  setEditingClock: (field: "clockInAt" | "clockOutAt", time: string, nextDay?: boolean) => void;
};

export function AttendanceDailyEditor({
  editingDaily,
  editingBreaks,
  historyRows,
  activeEditTab,
  editError,
  editMessage,
  dashboard,
  formatters,
  onClose,
  onSave,
  onResetManualEdit,
  onApplyBreakOvertime,
  onActiveEditTabChange,
  setEditingDaily,
  setEditingBreaks,
  setEditingClock,
  fieldErrors,
  busy,
}: AttendanceDailyEditorProps) {
  const [confirmReset, setConfirmReset] = useState(false);
  const closed = editingDaily.closeStatus === "CLOSED";
  const rawSpan = Date.parse(editingDaily.rawClockOutAt ?? "") - Date.parse(editingDaily.rawClockInAt ?? "");
  const rawWorkMinutes = Math.floor(rawSpan / 60000) - Math.max(0, editingDaily.breakMinutes ?? 0);
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => { heading.current?.focus(); }, [editingDaily.id]);
  return (
    <section className="panel action-panel anchor-panel daily-edit-panel">
      <div className="panel-header">
        <div>
          <h3 tabIndex={-1} ref={heading}>
            日次勤怠の詳細 / {editingDaily.employeeCode} {editingDaily.employeeName}
          </h3>
          <p className="compact-empty">
            {formatters.formatDateOnly(editingDaily.targetDate)} / 原本出勤 {formatters.formatTimeOnly(editingDaily.rawClockInAt)} / 原本退勤{" "}
            {formatters.formatTimeOnly(editingDaily.rawClockOutAt)}
          </p>
        </div>
        <button type="button" className="secondary" onClick={onClose} disabled={busy}>
          閉じる
        </button>
      </div>

      <div className="button-row">
        <button type="button" className={activeEditTab === "detail" ? "" : "secondary"} onClick={() => onActiveEditTabChange("detail")} disabled={busy}>詳細・採用根拠</button>
        <button type="button" className={activeEditTab === "edit" ? "" : "secondary"} onClick={() => onActiveEditTabChange("edit")} disabled={busy || closed}>
          編集
        </button>
        <button type="button" className={activeEditTab === "history" ? "" : "secondary"} onClick={() => onActiveEditTabChange("history")}>
          履歴
        </button>
      </div>

      {activeEditTab === "detail" ? <div className="stack-form">
        <p>状況: {attendanceDayLabels[editingDaily.dayState??""]??"記録あり"} / 承認: {formatApprovalStatus(editingDaily.approvalStatus)} / {closed?"締め済み":"未締め"}</p>
        {editingDaily.approvalStatus === "PENDING" && editingDaily.approvalReviewReason && <p>確認理由　{editingDaily.approvalReviewReason}</p>}
        <table><thead><tr><th>項目</th><th>原打刻</th><th>採用時刻</th></tr></thead><tbody><tr><th>出勤</th><td>{formatters.formatDateTime(editingDaily.rawClockInAt)}</td><td>{formatters.formatDateTime(editingDaily.clockInAt)}</td></tr><tr><th>退勤</th><td>{formatters.formatDateTime(editingDaily.rawClockOutAt)}</td><td>{formatters.formatDateTime(editingDaily.clockOutAt)}</td></tr></tbody></table>
        <p>勤務区分: {editingDaily.scheduleName??"未指定"} / 計算・保存休憩 {editingDaily.breakMinutes??0}分 / 実働 {editingDaily.workMinutes??"未確定"}分 / {editingDaily.breakReview?.source === "ADMIN_RULE" ? "勤務ルール適用" : editingDaily.isManuallyEdited?"手動補正あり":"自動計算・移行値"}</p>
        <AttendanceBreakReview review={editingDaily.breakReview}/>
        {editingDaily.breakOvertimeReview && <p role="status">{editingDaily.breakOvertimeReview.confirmationSource === "ADMIN_RULE" ? "勤務ルールで計上した勤怠" : "確認済みの勤怠"}　休憩 {editingDaily.breakOvertimeReview.breakMinutes}分　実働 {editingDaily.breakOvertimeReview.workMinutes}分　時間外 {editingDaily.breakOvertimeReview.overtimeMinutes}分</p>}
        {!closed && !editingDaily.breakOvertimeReview && editingDaily.approvalStatus === "PENDING"
          && editingDaily.breakReview?.needsConfirmation && rawWorkMinutes >= 480 &&
          <BreakOvertimeReview key={editingDaily.id} dailyId={editingDaily.id} busy={busy} onApply={onApplyBreakOvertime}/>}
        {(editingDaily.adoptionReasons??[]).map((reason,index)=><p key={index} className="compact-empty">{reason}</p>)}
        <p>{editingDaily.breakOvertimeReview ? "再計上前の保存休憩区間" : "計算・保存休憩区間"}: {(editingDaily.breaks??[]).map(row=>`${row.startNextDay?"翌日 ":""}${row.startTime}～${row.endNextDay?"翌日 ":""}${row.endTime}`).join(" / ")||"なし"}</p>
        <p>備考: {editingDaily.remark||"なし"}</p><p>所属長コメント: {editingDaily.supervisorComment||"なし"}</p><p>承認コメント: {editingDaily.approvalComment||"なし"}</p>
        {closed?<p>締め済みのため閲覧のみです。</p>:null}
      </div> : null}

      {activeEditTab === "edit" ? (
        <fieldset className="stack-form" disabled={busy || closed} style={{border:0,padding:0,margin:0,minWidth:0}}>
          <ValidationSummary errors={fieldErrors}/>
          <p className="compact-empty">変更した項目だけ保存します。時刻・休憩を変更しない場合は秒・翌日指定・自動計算を維持します。</p>
          <div className="form-grid">
            <label>
              勤務区分
              <select
                name="workTypeId" aria-invalid={Boolean(fieldErrors?.workTypeId)} aria-describedby="error-workTypeId"
                value={editingDaily.workTypeId ?? ""}
                onChange={(event) =>
                  setEditingDaily((current) => current && { ...current, workTypeId: event.target.value ? Number(event.target.value) : null })
                }
              >
                <option value="">未指定</option>
                {dashboard.systemMasters.workTypes.map((workType) => (
                  <option key={workType.id} value={workType.id}>
                    {workType.name}
                  </option>
                ))}
              </select>
              <FieldError errors={fieldErrors} field="workTypeId"/>
            </label>
            <label>
              出勤
              <input
                type="time"
                name="clockInTime" aria-invalid={Boolean(fieldErrors?.clockInTime)} aria-describedby="error-clockInTime"
                value={isoToTime(editingDaily.clockInAt)}
                onChange={(event) => setEditingClock("clockInAt", event.target.value)}
              />
              <FieldError errors={fieldErrors} field="clockInTime"/>
              <span className="table-checkbox"><input type="checkbox" aria-label="出勤は翌日" checked={isNextDay(editingDaily.clockInAt,editingDaily.targetDate)} onChange={event=>setEditingClock("clockInAt",isoToTime(editingDaily.clockInAt),event.target.checked)}/><span>翌日</span></span>
            </label>
            <label>
              退勤
              <input
                type="time"
                name="clockOutTime" aria-invalid={Boolean(fieldErrors?.clockOutTime)} aria-describedby="error-clockOutTime"
                value={isoToTime(editingDaily.clockOutAt)}
                onChange={(event) => setEditingClock("clockOutAt", event.target.value)}
              />
              <FieldError errors={fieldErrors} field="clockOutTime"/>
              <span className="table-checkbox"><input type="checkbox" aria-label="退勤は翌日" aria-describedby="overnight-edit-help" checked={isNextDay(editingDaily.clockOutAt,editingDaily.targetDate)} onChange={event=>setEditingClock("clockOutAt",isoToTime(editingDaily.clockOutAt),event.target.checked)}/><span>翌日</span></span>
            </label>
            <label>
              申請承認
              <select
                name="approvalStatus" aria-invalid={Boolean(fieldErrors?.approvalStatus)} aria-describedby="error-approvalStatus"
                value={editingDaily.approvalStatus ?? "PENDING"}
                onChange={(event) => setEditingDaily((current) => current && { ...current, approvalStatus: event.target.value })}
              >
                {(editingDaily.approvalStatus === "NOT_REQUIRED" || editingDaily.approvalStatus === "NOT_DUE") && <option value={editingDaily.approvalStatus} disabled>{formatApprovalStatus(editingDaily.approvalStatus)}</option>}
                <option value="PENDING">承認待ち</option>
                <option value="APPROVED">承認済み</option>
                <option value="RETURNED">差戻し</option>
              </select>
              <FieldError errors={fieldErrors} field="approvalStatus"/>
            </label>
          </div>

          <p id="overnight-edit-help" className="compact-empty">日跨ぎ勤務は原則なく、自動判定の対象外です。例外は管理者が勤務開始日の日次で退勤の「翌日」を指定して個別修正してください。翌日側の勤怠は原打刻を残して別途確認し、締め済みの場合は先に月締めを解除してください。勤務時間を自動で別の日へ移すことはありません。</p>
          <fieldset className="stack-form" name="breaks" tabIndex={-1} aria-invalid={Boolean(fieldErrors?.breaks)} aria-describedby="error-breaks" style={{border:0,padding:0,margin:0,minWidth:0}}>
            <legend>休憩区間</legend>
            <AttendanceBreakReview review={editingDaily.breakReview} savedOnly/>
            <p className="compact-empty">自動設定の区間は取得時刻の記録ではありません。実際に取得した休憩を確認してから修正してください。</p>
            <FieldError errors={fieldErrors} field="breaks"/>
            {editingBreaks.map((breakRow, index) => (
              <div className="form-grid" key={index}>
                <label>
                  休憩{index + 1} 開始
                  <input
                    type="time"
                    name={`breaks.${index}.startTime`} aria-invalid={Boolean(fieldErrors?.[`breaks.${index}.startTime`])} aria-describedby={`error-breaks.${index}.startTime`}
                    value={breakRow.startTime ?? ""}
                    onChange={(event) =>
                      setEditingBreaks((items) =>
                        items.map((item, itemIndex) => (itemIndex === index ? { ...item, startTime: event.target.value } : item)),
                      )
                    }
                  />
                  <FieldError errors={fieldErrors} field={`breaks.${index}.startTime`}/>
                  <span className="table-checkbox"><input type="checkbox" aria-label={`休憩${index+1}開始は翌日`} checked={Boolean(breakRow.startNextDay)} onChange={event=>setEditingBreaks(items=>items.map((item,i)=>i===index?{...item,startNextDay:event.target.checked}:item))}/><span>翌日</span></span>
                </label>
                <label>
                  休憩{index + 1} 終了
                  <input
                    type="time"
                    name={`breaks.${index}.endTime`} aria-invalid={Boolean(fieldErrors?.[`breaks.${index}.endTime`])} aria-describedby={`error-breaks.${index}.endTime`}
                    value={breakRow.endTime ?? ""}
                    onChange={(event) =>
                      setEditingBreaks((items) =>
                        items.map((item, itemIndex) => (itemIndex === index ? { ...item, endTime: event.target.value } : item)),
                      )
                    }
                  />
                  <FieldError errors={fieldErrors} field={`breaks.${index}.endTime`}/>
                  <span className="table-checkbox"><input type="checkbox" aria-label={`休憩${index+1}終了は翌日`} checked={Boolean(breakRow.endNextDay)} onChange={event=>setEditingBreaks(items=>items.map((item,i)=>i===index?{...item,endNextDay:event.target.checked}:item))}/><span>翌日</span></span>
                </label>
              </div>
            ))}
            <div className="button-row">
              <button type="button" className="secondary" onClick={() => setEditingBreaks((items) => [...items, { startTime: "", endTime: "" }])}>
                休憩を追加
              </button>
              <button type="button" className="secondary" onClick={() => setEditingBreaks((items) => items.slice(0, -1))} disabled={editingBreaks.length === 0}>
                休憩を削除
              </button>
            </div>
          </fieldset>

          <label>
            備考
            <textarea
              name="remark" maxLength={255} aria-invalid={Boolean(fieldErrors?.remark)} aria-describedby="error-remark"
              rows={3}
              value={editingDaily.remark ?? ""}
              onChange={(event) => setEditingDaily((current) => current && { ...current, remark: event.target.value })}
            />
            <FieldError errors={fieldErrors} field="remark"/>
          </label>
          <label>
            所属長コメント
            <textarea
              name="supervisorComment" aria-invalid={Boolean(fieldErrors?.supervisorComment)} aria-describedby="error-supervisorComment"
              rows={3}
              value={editingDaily.supervisorComment ?? ""}
              onChange={(event) => setEditingDaily((current) => current && { ...current, supervisorComment: event.target.value })}
            />
            <FieldError errors={fieldErrors} field="supervisorComment"/>
          </label>
          <label>
            承認コメント
            <textarea
              name="approvalComment" aria-invalid={Boolean(fieldErrors?.approvalComment)} aria-describedby="error-approvalComment"
              rows={3}
              value={editingDaily.approvalComment ?? ""}
              onChange={(event) => setEditingDaily((current) => current && { ...current, approvalComment: event.target.value })}
            />
            <FieldError errors={fieldErrors} field="approvalComment"/>
          </label>
          <div className="button-row">
            <button type="button" onClick={() => void onSave()}>
              {busy?"保存中…":"保存"}
            </button>
            <button type="button" className="secondary" onClick={() => setConfirmReset(true)} disabled={!editingDaily.isManuallyEdited}>
              手動補正を解除
            </button>
          </div>
        </fieldset>
      ) : activeEditTab === "history" ? (
        <DataTable
          title="日次勤怠履歴"
          rows={historyRows}
          emptyMessage="履歴はありません"
          columns={[
            { key: "actedAt", header: "操作日時", render: (row) => formatters.formatDateTime(row.actedAt) },
            { key: "fieldLabel", header: "項目", render: (row) => row.fieldLabel },
            { key: "oldValue", header: "変更前", render: (row) => row.oldValue ?? "-" },
            { key: "newValue", header: "変更後", render: (row) => row.newValue ?? "-" },
            { key: "actorRole", header: "操作者権限", render: (row) => row.actorRole ?? "-" },
            { key: "actorEmployeeCode", header: "操作者職員番号", render: (row) => row.actorEmployeeCode ?? "-" },
            { key: "actorName", header: "操作者名", render: (row) => row.actorName ?? "-" },
          ]}
        />
      ) : null}
      {editError ? <p className="banner">{editError}</p> : null}
      {editMessage ? <p className="feedback">{editMessage}</p> : null}
      <ConfirmDialog open={confirmReset} title="手動補正を解除しますか？" message={`${formatters.formatDateOnly(editingDaily.targetDate)} ${editingDaily.employeeName}。採用時刻・休憩を原打刻と勤務ルールから再計算し、確認の要否を判定します。`} confirmLabel="解除して再計算" onCancel={()=>setConfirmReset(false)} onConfirm={()=>{setConfirmReset(false);void onResetManualEdit();}}/>
    </section>
  );
}
