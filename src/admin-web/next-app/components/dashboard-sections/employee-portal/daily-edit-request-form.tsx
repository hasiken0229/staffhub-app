import type { FormEvent } from "react";
import { withBasePath } from "@/lib/base-path";
import { formatDurationMinutes } from "@/lib/api/formatters";
import type { AttendanceDaily } from "@/types";

type DailyEditRequestFormValues = {
  editTargetMonth: string;
  editTargetDate: string;
  editClockInTime: string;
  editClockOutTime: string;
  editBreakStartTime: string;
  editBreakEndTime: string;
  editRemark: string;
  editEmployeeComment: string;
};

type DailyEditRequestFormProps = {
  values: DailyEditRequestFormValues;
  attendanceDaily: AttendanceDaily[];
  requestCount: number;
  editRequestError: string;
  editRequestMessage: string;
  isPending: boolean;
  isEditRequestSubmitting: boolean;
  isAttendanceDailyLoading: boolean;
  isDailyEditFormOpen: boolean;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onReset: () => void;
  onEditTargetMonthChange: (value: string) => void;
  onDailyRowEdit: (row: AttendanceDaily) => void;
  onDailyRowSubmit: (row: AttendanceDaily) => void;
  onEditTargetDateChange: (value: string) => void;
  onEditClockInTimeChange: (value: string) => void;
  onEditClockOutTimeChange: (value: string) => void;
  onEditBreakStartTimeChange: (value: string) => void;
  onEditBreakEndTimeChange: (value: string) => void;
  onEditRemarkChange: (value: string) => void;
  onEditEmployeeCommentChange: (value: string) => void;
};

export function DailyEditRequestForm(props: DailyEditRequestFormProps) {
  const { values } = props;
  const editPanelId = "attendance-daily-edit-panel";

  function handleDailyRowEdit(row: AttendanceDaily) {
    props.onDailyRowEdit(row);
    window.setTimeout(() => {
      document.getElementById(editPanelId)?.scrollIntoView({ behavior: "smooth", block: "start" });
    }, 0);
  }

  return (
    <section className="panel action-panel section-enter delay-2">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">勤怠修正申請</p>
          <h3>月次勤怠から修正を申請する</h3>
        </div>
        <span className="panel-meta">{props.requestCount} 件</span>
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
        <span>{props.isAttendanceDailyLoading ? "読込中..." : `${props.attendanceDaily.length} 日`}</span>
      </div>

      <div className="attendance-edit-table-wrap">
        <table className="attendance-edit-table">
          <thead>
            <tr>
              <th>日付</th>
              <th>勤務区分</th>
              <th>出勤時刻<br />打刻/補正</th>
              <th>退勤時刻<br />打刻/補正</th>
              <th>休憩時間</th>
              <th>時間有給休暇</th>
              <th>子の看護休暇<br />時間休暇</th>
              <th>介護休暇<br />時間休暇</th>
              <th>備考</th>
              <th>申請</th>
            </tr>
          </thead>
          <tbody>
            {props.attendanceDaily.length === 0 ? (
              <tr>
                <td colSpan={10} className="table-empty-cell">
                  対象月の日次勤怠はありません
                </td>
              </tr>
            ) : (
              props.attendanceDaily.map((row) => (
                <tr key={`${row.employeeId}-${row.targetDate}`} className={row.id === null ? "is-missing-row" : undefined}>
                  <td>
                    <div className="attendance-edit-date-cell">
                      <span>{formatDayCell(row.targetDate)}</span>
                      <button
                        type="button"
                        className="icon-table-action"
                        title="この日付をフォームで修正"
                        aria-label={`${formatDayCell(row.targetDate)}を修正`}
                        onClick={() => handleDailyRowEdit(row)}
                        disabled={props.isEditRequestSubmitting}
                      >
                        <img src={withBasePath("/icons/attendance-edit-pencil.png")} alt="" aria-hidden="true" />
                      </button>
                    </div>
                  </td>
                  <td>{row.workStyleName ?? row.scheduleName ?? "実績未登録"}</td>
                  <td>{formatPunchPair(row.rawClockInAt, row.clockInAt)}</td>
                  <td>{formatPunchPair(row.rawClockOutAt, row.clockOutAt)}</td>
                  <td>{formatBreak(row)}</td>
                  <td>{formatMinutes(row.hourPaidLeaveMinutes)}</td>
                  <td>{formatMinutes(row.childCareLeaveMinutes)}</td>
                  <td>{formatMinutes(row.nursingCareLeaveMinutes)}</td>
                  <td>{row.remark ?? "-"}</td>
                  <td>
                    <button
                      type="button"
                      className="table-action"
                      onClick={() => props.onDailyRowSubmit(row)}
                      disabled={props.isEditRequestSubmitting || !(row.clockInAt ?? row.rawClockInAt) || !(row.clockOutAt ?? row.rawClockOutAt)}
                    >
                      申請
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {props.isDailyEditFormOpen ? (
        <div id={editPanelId} className="attendance-edit-form-panel">
          <div className="attendance-edit-form-header">
            <span>{formatDayCell(values.editTargetDate)}</span>
            <strong>修正内容</strong>
          </div>
          <form className="stack-form" onSubmit={props.onSubmit}>
            <div className="form-grid">
              <label>
                対象日
                <input
                  type="date"
                  value={values.editTargetDate}
                  onChange={(event) => props.onEditTargetDateChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              </label>
              <label>
                出勤
                <input
                  type="time"
                  value={values.editClockInTime}
                  onChange={(event) => props.onEditClockInTimeChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              </label>
              <label>
                退勤
                <input
                  type="time"
                  value={values.editClockOutTime}
                  onChange={(event) => props.onEditClockOutTimeChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              </label>
              <label>
                休憩開始
                <input
                  type="time"
                  value={values.editBreakStartTime}
                  onChange={(event) => props.onEditBreakStartTimeChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              </label>
              <label>
                休憩終了
                <input
                  type="time"
                  value={values.editBreakEndTime}
                  onChange={(event) => props.onEditBreakEndTimeChange(event.target.value)}
                  disabled={props.isEditRequestSubmitting}
                />
              </label>
            </div>
            <label>
              備考
              <textarea
                rows={3}
                value={values.editRemark}
                onChange={(event) => props.onEditRemarkChange(event.target.value)}
                disabled={props.isEditRequestSubmitting}
              />
            </label>
            <label>
              申請コメント
              <textarea
                rows={3}
                value={values.editEmployeeComment}
                onChange={(event) => props.onEditEmployeeCommentChange(event.target.value)}
                disabled={props.isEditRequestSubmitting}
              />
            </label>
            <div className="button-row">
              <button type="submit" disabled={props.isEditRequestSubmitting || props.isPending}>
                {props.isEditRequestSubmitting ? "申請を送信中..." : "修正申請を送信する"}
              </button>
              <button type="button" className="secondary" onClick={props.onReset} disabled={props.isEditRequestSubmitting}>
                入力をリセット
              </button>
            </div>
          </form>
        </div>
      ) : null}
      {props.editRequestError ? <p className="banner">{props.editRequestError}</p> : null}
      {props.editRequestMessage ? <p className="feedback">{props.editRequestMessage}</p> : null}
    </section>
  );
}

function formatDayCell(value: string) {
  const [, , month = "", day = ""] = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value) ?? [];
  if (!month || !day) {
    return value;
  }

  return `${Number(day).toString().padStart(2, "0")} ${weekdayLabel(value)}`;
}

function weekdayLabel(value: string) {
  const date = new Date(`${value}T00:00:00`);
  return ["日", "月", "火", "水", "木", "金", "土"][date.getDay()] ?? "";
}

function formatTime(value?: string | null) {
  if (!value) {
    return "-";
  }

  const match = /T(\d{2}):(\d{2})/.exec(value) ?? /^(\d{2}):(\d{2})/.exec(value);
  return match ? `${match[1]}:${match[2]}` : value;
}

function formatPunchPair(raw?: string | null, corrected?: string | null) {
  const rawTime = formatTime(raw);
  const correctedTime = formatTime(corrected);

  return (
    <span className="attendance-two-line-time">
      <span>{rawTime}</span>
      <span>{correctedTime}</span>
    </span>
  );
}

function formatBreak(row: AttendanceDaily) {
  const breakLabels = row.breaks
    ?.filter((breakRow) => breakRow.startTime || breakRow.endTime)
    .map((breakRow) => `${breakRow.startTime ?? "-"}-${breakRow.endTime ?? "-"}`);
  if (breakLabels && breakLabels.length > 0) {
    return breakLabels.join(" / ");
  }

  if (row.breakMinutes != null && row.breakMinutes > 0) {
    return formatMinutes(row.breakMinutes);
  }

  return "-";
}

function formatMinutes(value?: number | null) {
  return formatDurationMinutes(value ?? 0);
}
