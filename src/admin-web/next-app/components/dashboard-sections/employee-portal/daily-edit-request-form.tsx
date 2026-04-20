import type { FormEvent } from "react";

type DailyEditRequestFormValues = {
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
  requestCount: number;
  editRequestError: string;
  editRequestMessage: string;
  isPending: boolean;
  isEditRequestSubmitting: boolean;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onReset: () => void;
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

  return (
    <section className="panel action-panel section-enter delay-2">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">Daily Edit Request</p>
          <h3>日次勤怠の修正を申請する</h3>
        </div>
        <span className="panel-meta">{props.requestCount} 件</span>
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
      {props.editRequestError ? <p className="banner">{props.editRequestError}</p> : null}
      {props.editRequestMessage ? <p className="feedback">{props.editRequestMessage}</p> : null}
    </section>
  );
}
