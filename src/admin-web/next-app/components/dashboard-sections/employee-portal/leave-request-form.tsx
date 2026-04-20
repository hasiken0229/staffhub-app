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
  allowsHalfDay: boolean;
  values: LeaveRequestFormValues;
  formError: string;
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

export function LeaveRequestForm(props: LeaveRequestFormProps) {
  const { values } = props;

  return (
    <section className="panel action-panel section-enter delay-2">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">Leave Request</p>
          <h3>休暇を申請する</h3>
        </div>
        <span className="panel-meta">
          {props.selectedLeaveType
            ? `${props.selectedLeaveType.name} / 半日申請${props.selectedLeaveType.allowsHalfDay ? "可" : "不可"}`
            : "休暇区分を選択してください"}
        </span>
      </div>

      <form className="stack-form" onSubmit={props.onSubmit}>
        <div className="button-row">
          <button
            type="button"
            className={values.requestCategory === "LEAVE" ? "" : "secondary"}
            onClick={() => props.onRequestCategoryChange("LEAVE")}
          >
            通常休暇
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
                value={values.leaveTypeCode}
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
            </label>

            <label>
              申請単位
              <select
                value={values.dayUnit}
                onChange={(event) => props.onDayUnitChange(event.target.value as LeaveDayUnit)}
                disabled={props.isSubmitting || !props.allowsHalfDay}
              >
                <option value="FULL">全日</option>
                <option value="HALF">半日</option>
              </select>
            </label>

            <label>
              開始日
              <input
                type="date"
                value={values.startDate}
                onChange={(event) => props.onStartDateChange(event.target.value)}
                disabled={props.isSubmitting}
              />
            </label>

            <label>
              終了日
              <input
                type="date"
                value={values.dayUnit === "HALF" ? values.startDate : values.endDate}
                onChange={(event) => props.onEndDateChange(event.target.value)}
                disabled={props.isSubmitting || values.dayUnit === "HALF"}
              />
            </label>

            {values.dayUnit === "HALF" ? (
              <label>
                半日区分
                <select
                  value={values.halfDayType}
                  onChange={(event) => props.onHalfDayTypeChange(event.target.value as LeaveHalfDayType)}
                  disabled={props.isSubmitting}
                >
                  <option value="AM">午前</option>
                  <option value="PM">午後</option>
                </select>
              </label>
            ) : null}
          </div>
        ) : (
          <div className="form-grid">
            <label>
              時間休暇種別
              <select
                value={values.timeLeaveType}
                onChange={(event) => props.onTimeLeaveTypeChange(event.target.value as TimeLeaveType)}
                disabled={props.isSubmitting}
              >
                <option value="PAID_HOURLY">時間有給</option>
                <option value="CHILD_CARE_HOURLY">子の看護（時間）</option>
                <option value="NURSING_CARE_HOURLY">介護（時間）</option>
              </select>
            </label>
            <label>
              対象日
              <input
                type="date"
                value={values.targetDate}
                onChange={(event) => props.onTargetDateChange(event.target.value)}
                disabled={props.isSubmitting}
              />
            </label>
            <label>
              開始時刻
              <input
                type="time"
                value={values.startTime}
                onChange={(event) => props.onStartTimeChange(event.target.value)}
                disabled={props.isSubmitting}
              />
            </label>
            <label>
              終了時刻
              <input
                type="time"
                value={values.endTime}
                onChange={(event) => props.onEndTimeChange(event.target.value)}
                disabled={props.isSubmitting}
              />
            </label>
          </div>
        )}

        <label>
          申請理由
          <textarea
            rows={4}
            value={values.reason}
            onChange={(event) => props.onReasonChange(event.target.value)}
            placeholder="通院、私用、家庭都合など"
            disabled={props.isSubmitting}
          />
        </label>

        <div className="button-row">
          <button
            type="submit"
            disabled={props.isSubmitting || props.isPending || (values.requestCategory === "LEAVE" && props.leaveTypes.length === 0)}
          >
            {props.isSubmitting ? "申請を送信中..." : "この内容で申請する"}
          </button>
          <button type="button" className="secondary" onClick={props.onReset} disabled={props.isSubmitting}>
            入力をリセット
          </button>
        </div>
      </form>

      {props.formError ? <p className="banner">{props.formError}</p> : null}
      {props.formMessage ? <p className="feedback">{props.formMessage}</p> : null}
    </section>
  );
}
