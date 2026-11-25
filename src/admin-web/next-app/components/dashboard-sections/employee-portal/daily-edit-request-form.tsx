import { FieldError, ValidationSummary, type FieldErrors } from "@/components/form-field-errors";
import { useLayoutEffect, useRef, type FormEvent } from "react";
import { EmployeePunchTime } from "./employee-punch-time";
import { formatDurationMinutes } from "@/lib/api/formatters";
import { employeeDayState, employeeDayStateLabel } from "@/lib/employee-attendance-state";
import type { AttendanceDaily, AttendanceDailyBreak } from "@/types";

type DailyEditRequestFormValues = {
  editTargetMonth: string;
  editTargetDate: string;
  editClockInTime: string;
  editClockOutTime: string;
  editBreakStartTime: string;
  editBreakEndTime: string;
  editClockInNextDay?: boolean;
  editClockOutNextDay?: boolean;
  editBreakStartNextDay?: boolean;
  editBreakEndNextDay?: boolean;
  editAdditionalBreaks?: AttendanceDailyBreak[];
  editRemark: string;
  editEmployeeComment: string;
};

type DailyEditRequestFormProps = {
  values: DailyEditRequestFormValues;
  attendanceDaily: AttendanceDaily[];
  attendanceDailyLoadError: string;
  requestCount: number;
  editRequestError: string;
  fieldErrors?: FieldErrors;
  unknownResult?: boolean;
  isReconciling?: boolean;
  onReconcileResult?: () => void;
  editRequestMessage: string;
  isPending: boolean;
  isEditRequestSubmitting: boolean;
  isAttendanceDailyLoading: boolean;
  isDailyEditFormOpen: boolean;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onReset: () => void;
  onEditTargetMonthChange: (value: string) => void;
  onRetryAttendanceDailyLoad: () => void;
  onDailyRowEdit: (row: AttendanceDaily) => void;
  onDailyRowSubmit: (row: AttendanceDaily) => void;
  onEditClockInTimeChange: (value: string) => void;
  onEditClockOutTimeChange: (value: string) => void;
  onEditBreakStartTimeChange: (value: string) => void;
  onEditBreakEndTimeChange: (value: string) => void;
  onEditClockInNextDayChange?: (value: boolean) => void;
  onEditClockOutNextDayChange?: (value: boolean) => void;
  onEditBreakStartNextDayChange?: (value: boolean) => void;
  onEditBreakEndNextDayChange?: (value: boolean) => void;
  onEditAdditionalBreaksChange?: (value: AttendanceDailyBreak[]) => void;
  onEditRemarkChange: (value: string) => void;
  onEditEmployeeCommentChange: (value: string) => void;
};

export function DailyEditRequestForm(props: DailyEditRequestFormProps) {
  const { values } = props;
  const sectionRef = useRef<HTMLElement>(null);
  const headingRef = useRef<HTMLHeadingElement>(null);
  const returnButton = useRef<HTMLButtonElement | null>(null);
  const focusRequested = useRef(false);
  const primaryBreakActive = Boolean(values.editBreakStartTime || values.editBreakEndTime);

  useLayoutEffect(() => {
    if (props.isDailyEditFormOpen && focusRequested.current) {
      headingRef.current?.focus();
      focusRequested.current = false;
    }
  }, [props.isDailyEditFormOpen, values.editTargetDate]);

  function handleDailyRowEdit(row: AttendanceDaily, trigger: HTMLButtonElement) {
    returnButton.current = trigger;
    focusRequested.current = true;
    props.onDailyRowEdit(row);
    if (props.isDailyEditFormOpen && values.editTargetDate === row.targetDate) {
      headingRef.current?.focus();
      focusRequested.current = false;
    }
  }

  function returnToDay() {
    props.onReset();
    const trigger = returnButton.current;
    trigger?.focus();
    if (!trigger || document.activeElement !== trigger) {
      const buttons = sectionRef.current?.querySelectorAll<HTMLButtonElement>("[data-edit-day]");
      Array.from(buttons ?? []).find((button) => button.dataset.editDay === values.editTargetDate && button.getClientRects().length)?.focus();
    }
  }

  return (
    <section ref={sectionRef} className="panel action-panel section-enter delay-2">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">勤怠修正申請</p>
          <h3>月次勤怠から修正を申請する</h3>
        </div>
        <span className="panel-meta">修正申請 {props.requestCount} 件</span>
      </div>

      <div className="attendance-edit-toolbar">
        <label>
          対象月
          <input
            type="month"
            value={values.editTargetMonth}
            onChange={(event) => props.onEditTargetMonthChange(event.target.value)}
            disabled={props.isEditRequestSubmitting || props.isAttendanceDailyLoading}
          />
        </label>
        <span>
          {props.isAttendanceDailyLoading
            ? "読込中..."
            : props.attendanceDailyLoadError
              ? "件数を確認できません"
              : `${props.attendanceDaily.length} 日`}
        </span>
      </div>

      {props.attendanceDailyLoadError ? (
        <div className="attendance-load-error" role="alert">
          <strong>勤怠の取得に失敗しました。</strong>
          <span>通信状況を確認して、一覧だけ再試行してください。申請の再送信は不要です。</span>
          <button type="button" className="secondary" onClick={props.onRetryAttendanceDailyLoad} disabled={props.isAttendanceDailyLoading}>
            {props.isAttendanceDailyLoading ? "再読込中..." : "勤怠一覧を再試行"}
          </button>
        </div>
      ) : null}

            {props.unknownResult ? <div className="banner" role="alert"><p>送信結果が不明です。申請状況を確認するまで再送を停止しています。</p><button type="button" className="secondary" disabled={props.isReconciling} onClick={props.onReconcileResult}>{props.isReconciling ? "確認中..." : "申請状況を再取得して確認"}</button></div> : null}

      <div className="attendance-edit-table-wrap">
        <table className="attendance-edit-table">
          <thead>
            <tr>
              <th>日付</th>
              <th>出勤</th>
              <th>退勤</th>
              <th>実働時間</th>
              <th>状態・詳細</th>
              <th>修正操作</th>
            </tr>
          </thead>
          <tbody>
            {!props.attendanceDailyLoadError && props.attendanceDaily.length === 0 ? (
              <tr>
                <td colSpan={6} className="table-empty-cell">
                  対象月の日次勤怠はありません
                </td>
              </tr>
            ) : props.attendanceDailyLoadError ? null : (
              props.attendanceDaily.map((row) => (
                <tr key={`${row.employeeId}-${row.targetDate}`} className={row.id === null ? "is-missing-row" : undefined}>
                  <td>{formatDayCell(row.targetDate)}</td>
                  <td><EmployeePunchTime raw={row.rawClockInAt} calculated={row.clockInAt} day={row.targetDate} /></td>
                  <td><EmployeePunchTime raw={row.rawClockOutAt} calculated={row.clockOutAt} day={row.targetDate} /></td>
                  <td>{formatDailyMinutes(row, row.workMinutes)}</td>
                  <td>
                    <span className="attendance-status-label">{formatAttendanceStatus(row)}</span>
                    <DailyAttendanceDetails row={row} />
                  </td>
                  <td>
                    <DailyAttendanceActions
                      row={row}
                      disabled={props.isEditRequestSubmitting}
                      onEdit={(button) => handleDailyRowEdit(row, button)}
                      onSubmit={() => props.onDailyRowSubmit(row)}
                    />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {!props.attendanceDailyLoadError ? (
        <div className="attendance-mobile-cards" aria-label="日次勤怠一覧">
          {props.attendanceDaily.length === 0 ? (
            <p className="attendance-mobile-empty">対象月の日次勤怠はありません</p>
          ) : (
            props.attendanceDaily.map((row) => (
              <article key={`mobile-${row.employeeId}-${row.targetDate}`} className={`attendance-day-card${isPunchMissing(row) ? " is-missing-row" : ""}`}>
                <div className="attendance-day-card-header">
                  <strong>{formatDayCell(row.targetDate)}</strong>
                  <span className="attendance-status-label">{formatAttendanceStatus(row)}</span>
                </div>
                <div className="attendance-day-card-times">
                  <span><small>出勤</small><EmployeePunchTime raw={row.rawClockInAt} calculated={row.clockInAt} day={row.targetDate} /></span>
                  <span><small>退勤</small><EmployeePunchTime raw={row.rawClockOutAt} calculated={row.clockOutAt} day={row.targetDate} /></span>
                  <span><small>実働</small>{formatDailyMinutes(row, row.workMinutes)}</span>
                </div>
                <DailyAttendanceDetails row={row} />
                <DailyAttendanceActions
                  row={row}
                  disabled={props.isEditRequestSubmitting}
                  onEdit={(button) => handleDailyRowEdit(row, button)}
                  onSubmit={() => props.onDailyRowSubmit(row)}
                />
              </article>
            ))
          )}
        </div>
      ) : null}

      {props.isDailyEditFormOpen ? (
        <div className="attendance-edit-form-panel">
          <div className="attendance-edit-form-header">
            <h4 tabIndex={-1} ref={headingRef}><time dateTime={values.editTargetDate}>{formatDayCell(values.editTargetDate)}</time> の修正内容</h4>
            <button type="button" className="secondary" onClick={returnToDay} disabled={props.isEditRequestSubmitting}>一覧へ戻る</button>
          </div>
          <form className="stack-form" onSubmit={props.onSubmit}>
            <ValidationSummary errors={props.fieldErrors} />
            <div className="form-grid">
              <label>
                出勤
                <input
                  type="time"
                  value={values.editClockInTime} name="clockInTime" aria-invalid={Boolean(props.fieldErrors?.["clockInTime"])} aria-describedby={props.fieldErrors?.["clockInTime"] ? "error-clockInTime" : undefined}
                  onChange={(event) => props.onEditClockInTimeChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              <FieldError errors={props.fieldErrors} field="clockInTime" />
            </label>
              <label>
                退勤
                <input
                  type="time"
                  value={values.editClockOutTime} name="clockOutTime" aria-invalid={Boolean(props.fieldErrors?.["clockOutTime"])} aria-describedby={props.fieldErrors?.["clockOutTime"] ? "error-clockOutTime" : undefined}
                  onChange={(event) => props.onEditClockOutTimeChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              <FieldError errors={props.fieldErrors} field="clockOutTime" />
            </label>
              <label>
                休憩開始
                <input
                  type="time"
                  value={values.editBreakStartTime} name={primaryBreakActive ? "breaks.0.startTime" : undefined} aria-invalid={primaryBreakActive && Boolean(props.fieldErrors?.["breaks.0.startTime"])} aria-describedby={primaryBreakActive && props.fieldErrors?.["breaks.0.startTime"] ? "error-breaks.0.startTime" : undefined}
                  onChange={(event) => props.onEditBreakStartTimeChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              {primaryBreakActive ? <FieldError errors={props.fieldErrors} field="breaks.0.startTime" /> : null}
            </label>
              <label>
                休憩終了
                <input
                  type="time"
                  value={values.editBreakEndTime} name={primaryBreakActive ? "breaks.0.endTime" : undefined} aria-invalid={primaryBreakActive && Boolean(props.fieldErrors?.["breaks.0.endTime"])} aria-describedby={primaryBreakActive && props.fieldErrors?.["breaks.0.endTime"] ? "error-breaks.0.endTime" : undefined}
                  onChange={(event) => props.onEditBreakEndTimeChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              {primaryBreakActive ? <FieldError errors={props.fieldErrors} field="breaks.0.endTime" /> : null}
            </label>
            </div>
            <div className="form-grid">
              <label className="checkbox-row"><input type="checkbox" checked={values.editClockInNextDay ?? false} onChange={(event) => props.onEditClockInNextDayChange?.(event.target.checked)} disabled={props.isEditRequestSubmitting} />出勤は翌日</label>
              <label className="checkbox-row"><input type="checkbox" checked={values.editClockOutNextDay ?? false} onChange={(event) => props.onEditClockOutNextDayChange?.(event.target.checked)} disabled={props.isEditRequestSubmitting} />退勤は翌日</label>
              <label className="checkbox-row"><input type="checkbox" checked={values.editBreakStartNextDay ?? false} onChange={(event) => props.onEditBreakStartNextDayChange?.(event.target.checked)} disabled={props.isEditRequestSubmitting} />休憩開始は翌日</label>
              <label className="checkbox-row"><input type="checkbox" checked={values.editBreakEndNextDay ?? false} onChange={(event) => props.onEditBreakEndNextDayChange?.(event.target.checked)} disabled={props.isEditRequestSubmitting} />休憩終了は翌日</label>
            </div>
            {(values.editAdditionalBreaks ?? []).map((item, index) => {
              const update = (change: Partial<AttendanceDailyBreak>) => props.onEditAdditionalBreaksChange?.((values.editAdditionalBreaks ?? []).map((row, at) => at === index ? { ...row, ...change } : row));
              const fieldIndex = primaryBreakActive ? index + 1 : index;
              return <fieldset key={index} disabled={props.isEditRequestSubmitting}>
                <legend>追加の休憩 {index + 2}</legend>
                <div className="form-grid">
                  <label>休憩 {index + 2} 開始<input type="time" value={item.startTime ?? ""} onChange={(event) => update({ startTime: event.target.value })} name={`breaks.${fieldIndex}.startTime`} aria-invalid={Boolean(props.fieldErrors?.[`breaks.${fieldIndex}.startTime`])} aria-describedby={props.fieldErrors?.[`breaks.${fieldIndex}.startTime`] ? `error-breaks.${fieldIndex}.startTime` : undefined} /><FieldError errors={props.fieldErrors} field={`breaks.${fieldIndex}.startTime`} /></label>
                  <label>休憩 {index + 2} 終了<input type="time" value={item.endTime ?? ""} onChange={(event) => update({ endTime: event.target.value })} name={`breaks.${fieldIndex}.endTime`} aria-invalid={Boolean(props.fieldErrors?.[`breaks.${fieldIndex}.endTime`])} aria-describedby={props.fieldErrors?.[`breaks.${fieldIndex}.endTime`] ? `error-breaks.${fieldIndex}.endTime` : undefined} /><FieldError errors={props.fieldErrors} field={`breaks.${fieldIndex}.endTime`} /></label>
                  <label className="checkbox-row"><input type="checkbox" checked={item.startNextDay ?? false} onChange={(event) => update({ startNextDay: event.target.checked })} />休憩 {index + 2} 開始は翌日</label>
                  <label className="checkbox-row"><input type="checkbox" checked={item.endNextDay ?? false} onChange={(event) => update({ endNextDay: event.target.checked })} />休憩 {index + 2} 終了は翌日</label>
                </div>
                <button type="button" className="secondary" onClick={() => props.onEditAdditionalBreaksChange?.((values.editAdditionalBreaks ?? []).filter((_, at) => at !== index))}>休憩 {index + 2} を削除</button>
              </fieldset>;
            })}
            <div className="button-row"><button type="button" className="secondary" disabled={props.isEditRequestSubmitting} onClick={() => props.onEditAdditionalBreaksChange?.([...(values.editAdditionalBreaks ?? []), { startTime: "", endTime: "", startNextDay: false, endNextDay: false }])}>休憩を追加</button></div>
            <label>
              備考
              <textarea
                rows={3}
                value={values.editRemark} name="remark" maxLength={255} aria-invalid={Boolean(props.fieldErrors?.["remark"])} aria-describedby={props.fieldErrors?.["remark"] ? "error-remark" : undefined}
                onChange={(event) => props.onEditRemarkChange(event.target.value)}
                disabled={props.isEditRequestSubmitting}
              />
            <FieldError errors={props.fieldErrors} field="remark" />
            </label>
            <label>
              申請コメント
              <textarea
                rows={3}
                value={values.editEmployeeComment} name="employeeComment" aria-invalid={Boolean(props.fieldErrors?.["employeeComment"])} aria-describedby={props.fieldErrors?.["employeeComment"] ? "error-employeeComment" : undefined}
                onChange={(event) => props.onEditEmployeeCommentChange(event.target.value)}
                disabled={props.isEditRequestSubmitting}
              />
            <FieldError errors={props.fieldErrors} field="employeeComment" />
            </label>
            <div className="button-row">
              <button type="submit" disabled={props.isEditRequestSubmitting || props.isPending}>
                {props.isEditRequestSubmitting ? "申請を送信中..." : "修正申請を送信する"}
              </button>
              <button type="button" className="secondary" onClick={returnToDay} disabled={props.isEditRequestSubmitting}>
                一覧へ戻る
              </button>
            </div>
          </form>
        </div>
      ) : null}
      {props.editRequestError ? <p className="banner" role="alert">{props.editRequestError}</p> : null}
      {props.editRequestMessage ? <p className="feedback" role="status">{props.editRequestMessage}</p> : null}
    </section>
  );
}

function formatDayCell(value: string) {
  const [, , month = "", day = ""] = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value) ?? [];
  if (!month || !day) {
    return value;
  }

  return `${value}（${weekdayLabel(value)}）`;
}

function weekdayLabel(value: string) {
  const date = new Date(`${value}T00:00:00`);
  return ["日", "月", "火", "水", "木", "金", "土"][date.getDay()] ?? "";
}

function isPunchMissing(row: AttendanceDaily) {
  return employeeDayState(row) === "MISSING";
}

function formatAttendanceStatus(row: AttendanceDaily) {
  if (employeeDayState(row) !== "COMPLETE") return employeeDayStateLabel(row);
  if (row.alertSummary && row.alertSummary !== "-") {
    return row.alertSummary;
  }
  if (row.approvalStatus === "NOT_REQUIRED") return "承認不要";
  if (row.approvalStatus === "NOT_DUE") return "勤務中・予定";
  if (row.approvalStatus === "APPROVED") {
    return "承認済み";
  }
  if (row.approvalStatus === "RETURNED") {
    return "差戻し";
  }
  if (row.approvalStatus === "PENDING") {
    return "承認待ち";
  }
  return "登録済み";
}

function DailyAttendanceActions({
  row,
  disabled,
  onEdit,
  onSubmit,
}: {
  row: AttendanceDaily;
  disabled: boolean;
  onEdit: (button: HTMLButtonElement) => void;
  onSubmit: () => void;
}) {
  const state = employeeDayState(row);
  if (["FUTURE", "NOT_DUE", "WORKING"].includes(state)) return <span className="muted">勤務予定の終了後に修正できます。お急ぎの場合は管理者へご連絡ください。</span>;
  if (isPunchMissing(row)) {
    return (
      <button type="button" className="table-action attendance-missing-action" data-edit-day={row.targetDate} onClick={(event) => onEdit(event.currentTarget)} disabled={disabled}>
        打刻漏れを修正
      </button>
    );
  }

  return (
    <div className="button-row attendance-row-actions">
      <button type="button" className="secondary" data-edit-day={row.targetDate} onClick={(event) => onEdit(event.currentTarget)} disabled={disabled}>
        修正する
      </button>
      <button type="button" className="table-action" onClick={onSubmit} disabled={disabled}>
        申請
      </button>
    </div>
  );
}

function DailyAttendanceDetails({ row }: { row: AttendanceDaily }) {
  return (
    <details className="attendance-row-details">
      <summary>詳細を見る</summary>
      <DailyAttendanceFields row={row} />
    </details>
  );
}

export function DailyAttendanceFields({ row }: { row: AttendanceDaily }) {
  return <dl>
        <div><dt>勤務区分</dt><dd>{row.workStyleName ?? row.scheduleName ?? "実績未登録"}</dd></div>
        <div><dt>出勤</dt><dd><EmployeePunchTime raw={row.rawClockInAt} calculated={row.clockInAt} day={row.targetDate} /></dd></div>
        <div><dt>退勤</dt><dd><EmployeePunchTime raw={row.rawClockOutAt} calculated={row.clockOutAt} day={row.targetDate} /></dd></div>
        <div><dt>総労働時間</dt><dd>{formatDailyMinutes(row, getDailyTotalMinutes(row))}</dd></div>
        <div><dt>休憩時間</dt><dd>{formatBreakMinutes(row)}</dd></div>
        <div><dt>時間外勤務</dt><dd>{formatDailyMinutes(row, getDailyOvertimeMinutes(row))}</dd></div>
        <div><dt>時間有給休暇</dt><dd>{formatMinutes(row.hourPaidLeaveMinutes)}</dd></div>
        <div><dt>子の看護休暇</dt><dd>{formatMinutes(row.childCareLeaveMinutes)}</dd></div>
        <div><dt>介護休暇</dt><dd>{formatMinutes(row.nursingCareLeaveMinutes)}</dd></div>
        <div><dt>備考</dt><dd>{row.remark ?? "-"}</dd></div>
      </dl>;
}

function formatBreakMinutes(row: AttendanceDaily) {
  return `${Math.max(0, row.breakMinutes ?? 0)}分`;
}

function formatDailyMinutes(row: AttendanceDaily, value?: number | null) {
  if (row.workMinutes === null || row.workMinutes === undefined) {
    return "-";
  }

  return formatMinutes(value);
}

function getDailyTotalMinutes(row: AttendanceDaily) {
  if (row.workMinutes === null || row.workMinutes === undefined) {
    return null;
  }

  return (row.workMinutes ?? 0) + (row.breakMinutes ?? 0);
}

function getDailyOvertimeMinutes(row: AttendanceDaily) {
  if (row.workMinutes === null || row.workMinutes === undefined) {
    return null;
  }

  return row.overtimeMinutesOverride ?? row.overtimeMinutes ?? null;
}

function formatMinutes(value?: number | null) {
  return value === null || value === undefined ? "-" : formatDurationMinutes(value);
}
