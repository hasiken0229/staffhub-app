import { FieldError, ValidationSummary, type FieldErrors } from "@/components/form-field-errors";
import type { FormEvent } from "react";
import type { LeaveDayUnit, LeaveHalfDayType } from "@/types";
import type { EmployeePortalLeaveType, LeaveRequestCategory, TimeLeaveType } from "./employee-portal-types";

type LeaveRequestFormValues = {
  leaveTypeCode: string;
  startDate: string;
  endDate: string;
  dayUnit: LeaveDayUnit;
  halfDayType: LeaveHalfDayType;
  requestCategory: LeaveRequestCategory;
  timeLeaveType: TimeLeaveType;
  targetDate: string;
  startTime: string;
  endTime: string;
  reason: string;
};

type LeaveRequestFormProps = {
  leaveTypes: EmployeePortalLeaveType[];
  selectedLeaveType: EmployeePortalLeaveType | null;
  balancePreview: LeaveBalancePreview;
  allowsHalfDay: boolean;
  values: LeaveRequestFormValues;
  formError: string;
  fieldErrors?: FieldErrors;
  unknownResult?: boolean;
  isReconciling?: boolean;
  onReconcileResult?: () => void;
  balanceAsOf?: string;
  hourlyEffectiveOn?: string;
  isBalanceLoading?: boolean;
  balanceLoadError?: string;
  balanceAvailable?: boolean;
  onRetryBalance?: () => void;
  formMessage: string;
  isPending: boolean;
  isSubmitting: boolean;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onReset: () => void;
  onLeaveTypeCodeChange: (value: string) => void;
  onStartDateChange: (value: string) => void;
  onEndDateChange: (value: string) => void;
  onDayUnitChange: (value: LeaveDayUnit) => void;
  onHalfDayTypeChange: (value: LeaveHalfDayType) => void;
  onRequestCategoryChange: (value: LeaveRequestCategory) => void;
  onTimeLeaveTypeChange: (value: TimeLeaveType) => void;
  onTargetDateChange: (value: string) => void;
  onStartTimeChange: (value: string) => void;
  onEndTimeChange: (value: string) => void;
  onReasonChange: (value: string) => void;
};

export type LeaveBalancePreview = {
  workdays?: import("@/lib/api/employee-balance").LeaveWorkdayPreview;
  currentBalance: number;
  requestedMinutes?: number;
  availableMinutes?: number | null;
  projectedMinutes?: number | null;
  requestedDays: number;
  projectedBalance: number;
  consumesBalance: boolean;
  isOverBalance: boolean;
  requiresPeriodCheck?: boolean;
  standardDayMinutes: number | null;
  note: string;
};

export function LeaveRequestForm(props: LeaveRequestFormProps) {
  const { values } = props;

  return (
    <section id="leave-request-form" className="panel action-panel section-enter delay-2">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">休暇申請</p>
          <h3>休暇を申請する</h3>
        </div>
        <span className="panel-meta">
          {props.selectedLeaveType
            ? `${props.selectedLeaveType.name} / 半日申請${props.selectedLeaveType.allowsHalfDay ? "可" : "不可"}`
            : "休暇区分を選択してください"}
        </span>
      </div>

      <form className="stack-form" onSubmit={props.onSubmit}>
        <ValidationSummary errors={props.fieldErrors} />
            {props.unknownResult ? <div className="banner" role="alert"><p>送信結果が不明です。申請状況を確認するまで再送を停止しています。</p><button type="button" className="secondary" disabled={props.isReconciling} onClick={props.onReconcileResult}>{props.isReconciling ? "確認中..." : "申請状況を再取得して確認"}</button></div> : null}
        <div className="button-row">
          <button
            type="button"
            className={values.requestCategory === "LEAVE" ? "" : "secondary"}
            onClick={() => props.onRequestCategoryChange("LEAVE")}
          >
            1日休暇
          </button>
          <button
            type="button"
            className={values.requestCategory === "TIME_LEAVE" ? "" : "secondary"}
            onClick={() => props.onRequestCategoryChange("TIME_LEAVE")}
          >
            時間休暇
          </button>
        </div>

        {values.requestCategory === "LEAVE" ? (
          <div className="form-grid">
            <label>
              休暇区分
              <select
                value={values.leaveTypeCode} name="leaveTypeCode" aria-invalid={Boolean(props.fieldErrors?.["leaveTypeCode"])} aria-describedby={props.fieldErrors?.["leaveTypeCode"] ? "error-leaveTypeCode" : undefined}
                onChange={(event) => props.onLeaveTypeCodeChange(event.target.value)}
                disabled={props.isSubmitting}
              >
                {props.leaveTypes.length === 0 ? <option value="">休暇区分を読込中です</option> : null}
                {props.leaveTypes.map((leaveType) => (
                  <option key={leaveType.code} value={leaveType.code}>
                    {leaveType.name}
                  </option>
                ))}
              </select>
            <FieldError errors={props.fieldErrors} field="leaveTypeCode" />
            </label>

            <label>
              申請単位
              <select
                value={values.dayUnit} name="dayUnit" aria-invalid={Boolean(props.fieldErrors?.["dayUnit"])} aria-describedby={props.fieldErrors?.["dayUnit"] ? "error-dayUnit" : undefined}
                onChange={(event) => props.onDayUnitChange(event.target.value as LeaveDayUnit)}
                disabled={props.isSubmitting || !props.allowsHalfDay}
              >
                <option value="FULL">全日</option>
                <option value="HALF">半日</option>
              </select>
            <FieldError errors={props.fieldErrors} field="dayUnit" />
            </label>

            <label>
              開始日
              <input
                type="date"
                value={values.startDate} name="startDate" aria-invalid={Boolean(props.fieldErrors?.["startDate"])} aria-describedby={props.fieldErrors?.["startDate"] ? "error-startDate" : undefined}
                onChange={(event) => props.onStartDateChange(event.target.value)}
                disabled={props.isSubmitting}
              />
            <FieldError errors={props.fieldErrors} field="startDate" />
            </label>

            <label>
              終了日
              <input
                type="date"
                name="endDate" aria-invalid={Boolean(props.fieldErrors?.endDate)} aria-describedby={props.fieldErrors?.endDate ? "error-endDate" : undefined}
                min={values.startDate || undefined}
                value={values.dayUnit === "HALF" ? values.startDate : values.endDate}
                onChange={(event) => props.onEndDateChange(event.target.value)}
                disabled={props.isSubmitting || values.dayUnit === "HALF"}
              />
            <FieldError errors={props.fieldErrors} field="endDate" />
            </label>

            {values.dayUnit === "HALF" ? (
              <label>
                半日区分
                <select
                  value={values.halfDayType} name="halfDayType" aria-invalid={Boolean(props.fieldErrors?.["halfDayType"])} aria-describedby={props.fieldErrors?.["halfDayType"] ? "error-halfDayType" : undefined}
                  onChange={(event) => props.onHalfDayTypeChange(event.target.value as LeaveHalfDayType)}
                  disabled={props.isSubmitting}
                >
                  <option value="AM">午前</option>
                  <option value="PM">午後</option>
                </select>
              <FieldError errors={props.fieldErrors} field="halfDayType" />
            </label>
            ) : null}
          </div>
        ) : (
          <div className="form-grid">
            <label>
              時間休暇種別
              <select
                value={values.timeLeaveType} name="timeLeaveType" aria-invalid={Boolean(props.fieldErrors?.["timeLeaveType"])} aria-describedby={props.fieldErrors?.["timeLeaveType"] ? "error-timeLeaveType" : undefined}
                onChange={(event) => props.onTimeLeaveTypeChange(event.target.value as TimeLeaveType)}
                disabled={props.isSubmitting}
              >
                <option value="PAID_HOURLY">時間有給</option>
                <option value="CHILD_CARE_HOURLY">子の看護（時間）</option>
                <option value="NURSING_CARE_HOURLY">介護（時間）</option>
              </select>
            <FieldError errors={props.fieldErrors} field="timeLeaveType" />
            </label>
            <label>
              対象日
              <input
                type="date"
                value={values.targetDate} name="targetDate" aria-invalid={Boolean(props.fieldErrors?.["targetDate"])} aria-describedby={props.fieldErrors?.["targetDate"] ? "error-targetDate" : undefined}
                onChange={(event) => props.onTargetDateChange(event.target.value)}
                disabled={props.isSubmitting}
              />
            <FieldError errors={props.fieldErrors} field="targetDate" />
            </label>
            <label>
              開始時刻
              <input
                type="time"
                value={values.startTime} name="startTime" aria-invalid={Boolean(props.fieldErrors?.["startTime"])} aria-describedby={props.fieldErrors?.["startTime"] ? "error-startTime" : undefined}
                onChange={(event) => props.onStartTimeChange(event.target.value)}
                disabled={props.isSubmitting}
              />
            <FieldError errors={props.fieldErrors} field="startTime" />
            </label>
            <label>
              終了時刻
              <input
                type="time"
                value={values.endTime} name="endTime" aria-invalid={Boolean(props.fieldErrors?.["endTime"])} aria-describedby={props.fieldErrors?.["endTime"] ? "error-endTime" : undefined}
                onChange={(event) => props.onEndTimeChange(event.target.value)}
                disabled={props.isSubmitting}
              />
            <FieldError errors={props.fieldErrors} field="endTime" />
            </label>
          </div>
        )}

        <div className={props.balancePreview.isOverBalance ? "leave-balance-preview is-warning" : "leave-balance-preview"} aria-busy={props.isBalanceLoading}>
          <p>残高の照会日：{props.balanceAsOf ?? "-"}（申請中の予約を控除）</p>
          {props.isBalanceLoading ? <p role="status">対象日時点の残高を読込中...</p> : props.balanceLoadError ? <div role="alert"><p>{props.balanceLoadError}</p><button type="button" className="secondary" onClick={props.onRetryBalance}>残高を再取得</button></div> : props.balanceAvailable === false ? <p role="alert">対象日時点の残高を確認できません。{props.hourlyEffectiveOn ? `時間有給は基準日 ${props.hourlyEffectiveOn} の翌日以降を選択してください。` : "時間有給の付与状況を管理者に確認してください。"}</p> : <>
          <div className="summary-strip">
            {values.requestCategory === "TIME_LEAVE" ? <>
              {values.timeLeaveType === "PAID_HOURLY" ? <div><span className="detail-label">申請可能な時間有給</span><strong>{formatHourMinutes(props.balancePreview.availableMinutes)}</strong></div> : null}
              <div><span className="detail-label">今回の申請量</span><strong>{formatHourMinutes(props.balancePreview.requestedMinutes)}</strong></div>
              {values.timeLeaveType === "PAID_HOURLY" ? <div><span className="detail-label">申請後の時間有給（目安）</span><strong>{formatHourMinutes(props.balancePreview.projectedMinutes)}</strong></div> : null}
            </> : <>
              <div><span className="detail-label">{props.balancePreview.requiresPeriodCheck ? "開始日時点の申請可能日数" : "申請可能な有給日数"}</span><strong>{formatDays(props.balancePreview.currentBalance)}日</strong></div>
              <div><span className="detail-label">今回の申請量</span><strong>{formatDays(props.balancePreview.requestedDays)}日</strong></div>
              <div><span className="detail-label">{props.balancePreview.requiresPeriodCheck ? "期間全体の申請後残高" : "申請後の目安"}</span><strong>{props.balancePreview.requiresPeriodCheck ? "送信時に確認" : `${formatDays(props.balancePreview.projectedBalance)}日`}</strong></div>
            </>}
          </div><p>{props.balancePreview.note}</p>
          {props.balancePreview.workdays && <div>
            <p>消化対象日：{props.balancePreview.workdays.dates.join("、") || "該当なし"}</p>
            {!!props.balancePreview.workdays.excludedDates.length && <p>登録済みの公休（消化対象外）：{props.balancePreview.workdays.excludedDates.join("、")}</p>}
            {!!props.balancePreview.workdays.unconfirmedDates.length && <p>勤務予定未登録の日を含みます。勤務日であることを確認してください：{props.balancePreview.workdays.unconfirmedDates.join("、")}</p>}
          </div>}</>}
        </div>

        <label>
          申請理由
          <textarea
            rows={4}
            value={values.reason} name="reason" aria-invalid={Boolean(props.fieldErrors?.["reason"])} aria-describedby={props.fieldErrors?.["reason"] ? "error-reason" : undefined}
            onChange={(event) => props.onReasonChange(event.target.value)}
            placeholder="通院、私用、家庭都合など"
            disabled={props.isSubmitting}
          />
        <FieldError errors={props.fieldErrors} field="reason" />
            </label>

        <div className="button-row">
          <button
            type="submit"
            disabled={
              props.isSubmitting || props.unknownResult ||
              ((values.requestCategory === "TIME_LEAVE" && values.timeLeaveType === "PAID_HOURLY" || values.requestCategory === "LEAVE" && props.selectedLeaveType?.requiresBalance) && (props.isBalanceLoading || Boolean(props.balanceLoadError) || props.balanceAvailable === false)) ||
              props.isPending ||
              props.balancePreview.isOverBalance || props.balancePreview.workdays?.dates.length === 0 ||
              (values.requestCategory === "LEAVE" && props.leaveTypes.length === 0)
            }
          >
            {props.isSubmitting ? "申請を送信中..." : "この内容で申請する"}
          </button>
          <button type="button" className="secondary" onClick={props.onReset} disabled={props.isSubmitting}>
            入力をリセット
          </button>
        </div>
      </form>

      {props.formError ? <p className="banner" role="alert">{props.formError}</p> : null}
      {props.formMessage ? <p className="feedback" role="status">{props.formMessage}</p> : null}
    </section>
  );
}

function formatDays(value: number) {
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/0+$/, "").replace(/\.$/, "");
}

function formatStandardDayMinutes(value: number | null) {
  if (value === null) {
    return "確認中";
  }

  const hours = Math.floor(value / 60);
  const minutes = value % 60;
  return minutes === 0 ? `${hours}時間` : `${hours}時間${minutes}分`;
}

function formatHourMinutes(value?: number | null) { return value === null || value === undefined ? "未取得" : `${Math.floor(value / 60)}時間${value % 60 ? `${value % 60}分` : ""}`; }
