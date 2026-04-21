import { useEffect, useMemo, useState, type FormEvent } from "react";
import type { LeaveDayUnit, LeaveHalfDayType } from "@/types";
import { DailyEditRequestForm } from "./employee-portal/daily-edit-request-form";
import { EmployeePortalLists } from "./employee-portal/employee-portal-lists";
import { EmployeePortalShell } from "./employee-portal/employee-portal-shell";
import type { EmployeePortalLeaveType, EmployeePortalSectionProps, LeaveRequestCategory, TimeLeaveType } from "./employee-portal/employee-portal-types";
import { toDateInputValue } from "./employee-portal/employee-portal-utils";
import { LeaveRequestForm, type LeaveBalancePreview } from "./employee-portal/leave-request-form";

const DEFAULT_STANDARD_DAY_MINUTES = 480;

export function EmployeePortalSection(props: EmployeePortalSectionProps) {
  const employeeName = props.data.employeePortal.home.employee?.name ?? props.data.currentUserName ?? "職員";
  const leaveTypes = props.data.employeePortal.home.leaveTypes ?? [];
  const today = useMemo(() => toDateInputValue(new Date()), []);
  const [leaveTypeCode, setLeaveTypeCode] = useState(leaveTypes[0]?.code ?? "");
  const [startDate, setStartDate] = useState(today);
  const [endDate, setEndDate] = useState(today);
  const [dayUnit, setDayUnit] = useState<LeaveDayUnit>("FULL");
  const [halfDayType, setHalfDayType] = useState<LeaveHalfDayType>("AM");
  const [requestCategory, setRequestCategory] = useState<LeaveRequestCategory>("LEAVE");
  const [timeLeaveType, setTimeLeaveType] = useState<TimeLeaveType>("PAID_HOURLY");
  const [targetDate, setTargetDate] = useState(today);
  const [startTime, setStartTime] = useState("09:00");
  const [endTime, setEndTime] = useState("10:00");
  const [reason, setReason] = useState("");
  const [editTargetDate, setEditTargetDate] = useState(today);
  const [editClockInTime, setEditClockInTime] = useState("09:00");
  const [editClockOutTime, setEditClockOutTime] = useState("18:00");
  const [editBreakStartTime, setEditBreakStartTime] = useState("12:00");
  const [editBreakEndTime, setEditBreakEndTime] = useState("13:00");
  const [editRemark, setEditRemark] = useState("");
  const [editEmployeeComment, setEditEmployeeComment] = useState("");
  const [formMessage, setFormMessage] = useState("");
  const [formError, setFormError] = useState("");
  const [editRequestMessage, setEditRequestMessage] = useState("");
  const [editRequestError, setEditRequestError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isEditRequestSubmitting, setIsEditRequestSubmitting] = useState(false);
  const selectedLeaveType = leaveTypes.find((item) => item.code === leaveTypeCode) ?? null;
  const allowsHalfDay = Boolean(selectedLeaveType?.allowsHalfDay);
  const leaveBalancePreview = useMemo(
    () =>
      buildLeaveBalancePreview({
        currentBalance: props.data.employeePortal.home.paidLeaveBalance ?? 0,
        pendingLeaveCount: props.data.employeePortal.home.pendingLeaveCount ?? 0,
        leaveTypes,
        selectedLeaveType,
        requestCategory,
        dayUnit,
        startDate,
        endDate,
        startTime,
        endTime,
        timeLeaveType,
      }),
    [
      props.data.employeePortal.home.paidLeaveBalance,
      props.data.employeePortal.home.pendingLeaveCount,
      leaveTypes,
      selectedLeaveType,
      requestCategory,
      dayUnit,
      startDate,
      endDate,
      startTime,
      endTime,
      timeLeaveType,
    ],
  );

  useEffect(() => {
    if (leaveTypes.length === 0) {
      if (leaveTypeCode !== "") {
        setLeaveTypeCode("");
      }
      return;
    }

    if (!leaveTypes.some((item) => item.code === leaveTypeCode)) {
      setLeaveTypeCode(leaveTypes[0].code);
    }
  }, [leaveTypeCode, leaveTypes]);

  useEffect(() => {
    if (!allowsHalfDay && dayUnit === "HALF") {
      setDayUnit("FULL");
      setHalfDayType("AM");
      setEndDate(startDate);
    }
  }, [allowsHalfDay, dayUnit, startDate]);

  function handleDayUnitChange(nextDayUnit: LeaveDayUnit) {
    setDayUnit(nextDayUnit);
    if (nextDayUnit === "HALF") {
      setEndDate(startDate);
    }
  }

  function handleStartDateChange(nextStartDate: string) {
    setStartDate(nextStartDate);
    if (dayUnit === "HALF") {
      setEndDate(nextStartDate);
    }
  }

  function resetLeaveRequestForm() {
    setLeaveTypeCode(leaveTypes[0]?.code ?? "");
    setRequestCategory("LEAVE");
    setStartDate(today);
    setEndDate(today);
    setTargetDate(today);
    setStartTime("09:00");
    setEndTime("10:00");
    setDayUnit("FULL");
    setHalfDayType("AM");
    setReason("");
    setFormError("");
    setFormMessage("");
  }

  function resetDailyEditRequestForm() {
    setEditTargetDate(today);
    setEditClockInTime("09:00");
    setEditClockOutTime("18:00");
    setEditBreakStartTime("12:00");
    setEditBreakEndTime("13:00");
    setEditRemark("");
    setEditEmployeeComment("");
    setEditRequestError("");
    setEditRequestMessage("");
  }

  async function handleLeaveRequestSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError("");
    setFormMessage("");

    if (requestCategory === "TIME_LEAVE") {
      if (!targetDate || !startTime || !endTime || endTime <= startTime) {
        setFormError("時間休暇の対象日と時間帯を入力してください。");
        return;
      }

      const [startHour, startMinute] = startTime.split(":").map(Number);
      const [endHour, endMinute] = endTime.split(":").map(Number);
      const quantityMinutes = endHour * 60 + endMinute - (startHour * 60 + startMinute);
      if (quantityMinutes <= 0) {
        setFormError("終了時刻は開始時刻より後にしてください。");
        return;
      }

      setIsSubmitting(true);
      try {
        await props.actions.onLeaveRequestCreate({
          requestCategory: "TIME_LEAVE",
          timeLeaveType,
          targetDate,
          startTime,
          endTime,
          quantityMinutes,
          reason,
        });
        setFormMessage("時間休暇申請を登録しました。一覧を更新しています。");
        setReason("");
      } catch (error) {
        setFormError(error instanceof Error ? error.message : "時間休暇申請の登録に失敗しました。");
      } finally {
        setIsSubmitting(false);
      }
      return;
    }

    if (!leaveTypeCode) {
      setFormError("休暇区分を選択してください。");
      return;
    }

    if (!startDate || !endDate) {
      setFormError("開始日と終了日を入力してください。");
      return;
    }

    if (dayUnit === "HALF" && !allowsHalfDay) {
      setFormError("選択した休暇区分は半日申請に対応していません。");
      return;
    }

    const normalizedEndDate = dayUnit === "HALF" ? startDate : endDate;
    if (normalizedEndDate < startDate) {
      setFormError("終了日は開始日以降にしてください。");
      return;
    }

    setIsSubmitting(true);

    try {
      await props.actions.onLeaveRequestCreate({
        requestCategory: "LEAVE",
        leaveTypeCode,
        startDate,
        endDate: normalizedEndDate,
        dayUnit,
        halfDayType: dayUnit === "HALF" ? halfDayType : null,
        reason,
      });

      setFormMessage("休暇申請を登録しました。一覧を更新しています。");
      setReason("");
      setDayUnit("FULL");
      setHalfDayType("AM");
      setEndDate(startDate);
    } catch (error) {
      setFormError(error instanceof Error ? error.message : "休暇申請の登録に失敗しました。");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleDailyEditRequestSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setEditRequestError("");
    setEditRequestMessage("");

    if (!editTargetDate || !editClockInTime || !editClockOutTime) {
      setEditRequestError("対象日、出勤、退勤を入力してください。");
      return;
    }

    setIsEditRequestSubmitting(true);
    try {
      await props.actions.onAttendanceDailyEditRequestCreate({
        targetDate: editTargetDate,
        clockInTime: editClockInTime,
        clockOutTime: editClockOutTime,
        breaks: editBreakStartTime && editBreakEndTime ? [{ startTime: editBreakStartTime, endTime: editBreakEndTime }] : [],
        remark: editRemark,
        employeeComment: editEmployeeComment,
      });
      setEditRequestMessage("日次修正申請を登録しました。");
      setEditEmployeeComment("");
    } catch (error) {
      setEditRequestError(error instanceof Error ? error.message : "日次修正申請の登録に失敗しました。");
    } finally {
      setIsEditRequestSubmitting(false);
    }
  }

  return (
    <EmployeePortalShell employeeName={employeeName} data={props.data} actions={props.actions} formatters={props.formatters}>
      <LeaveRequestForm
        leaveTypes={leaveTypes}
        selectedLeaveType={selectedLeaveType}
        balancePreview={leaveBalancePreview}
        allowsHalfDay={allowsHalfDay}
        values={{
          leaveTypeCode,
          startDate,
          endDate,
          dayUnit,
          halfDayType,
          requestCategory,
          timeLeaveType,
          targetDate,
          startTime,
          endTime,
          reason,
        }}
        formError={formError}
        formMessage={formMessage}
        isPending={props.data.isPending}
        isSubmitting={isSubmitting}
        onSubmit={(event) => void handleLeaveRequestSubmit(event)}
        onReset={resetLeaveRequestForm}
        onLeaveTypeCodeChange={setLeaveTypeCode}
        onStartDateChange={handleStartDateChange}
        onEndDateChange={setEndDate}
        onDayUnitChange={handleDayUnitChange}
        onHalfDayTypeChange={setHalfDayType}
        onRequestCategoryChange={setRequestCategory}
        onTimeLeaveTypeChange={setTimeLeaveType}
        onTargetDateChange={setTargetDate}
        onStartTimeChange={setStartTime}
        onEndTimeChange={setEndTime}
        onReasonChange={setReason}
      />

      <DailyEditRequestForm
        values={{
          editTargetDate,
          editClockInTime,
          editClockOutTime,
          editBreakStartTime,
          editBreakEndTime,
          editRemark,
          editEmployeeComment,
        }}
        requestCount={props.data.employeePortal.attendanceDailyEditRequests?.length ?? 0}
        editRequestError={editRequestError}
        editRequestMessage={editRequestMessage}
        isPending={props.data.isPending}
        isEditRequestSubmitting={isEditRequestSubmitting}
        onSubmit={(event) => void handleDailyEditRequestSubmit(event)}
        onReset={resetDailyEditRequestForm}
        onEditTargetDateChange={setEditTargetDate}
        onEditClockInTimeChange={setEditClockInTime}
        onEditClockOutTimeChange={setEditClockOutTime}
        onEditBreakStartTimeChange={setEditBreakStartTime}
        onEditBreakEndTimeChange={setEditBreakEndTime}
        onEditRemarkChange={setEditRemark}
        onEditEmployeeCommentChange={setEditEmployeeComment}
      />

      <EmployeePortalLists data={props.data} actions={props.actions} formatters={props.formatters} />
    </EmployeePortalShell>
  );
}

type LeaveBalancePreviewInput = {
  currentBalance: number;
  pendingLeaveCount: number;
  leaveTypes: EmployeePortalLeaveType[];
  selectedLeaveType: EmployeePortalLeaveType | null;
  requestCategory: LeaveRequestCategory;
  dayUnit: LeaveDayUnit;
  startDate: string;
  endDate: string;
  startTime: string;
  endTime: string;
  timeLeaveType: TimeLeaveType;
};

function buildLeaveBalancePreview(input: LeaveBalancePreviewInput): LeaveBalancePreview {
  const balanceType =
    input.requestCategory === "TIME_LEAVE"
      ? input.leaveTypes.find((leaveType) => leaveType.code === timeLeaveTypeToLeaveTypeCode(input.timeLeaveType))
      : input.selectedLeaveType;
  const requestedDays =
    input.requestCategory === "TIME_LEAVE"
      ? calculateTimeLeaveDays(input.startTime, input.endTime)
      : calculateLeaveDays(input.startDate, input.dayUnit === "HALF" ? input.startDate : input.endDate, input.dayUnit);
  const consumesBalance =
    input.requestCategory === "TIME_LEAVE" && input.timeLeaveType === "PAID_HOURLY"
      ? balanceType?.requiresBalance ?? true
      : Boolean(balanceType?.requiresBalance);
  const projectedBalance = roundDays(input.currentBalance - (consumesBalance ? requestedDays : 0));
  const isOverBalance = consumesBalance && requestedDays > 0 && projectedBalance < 0;

  return {
    currentBalance: roundDays(input.currentBalance),
    requestedDays,
    projectedBalance,
    consumesBalance,
    isOverBalance,
    note: buildBalanceNote({
      consumesBalance,
      isOverBalance,
      requestedDays,
      pendingLeaveCount: input.pendingLeaveCount,
      requestCategory: input.requestCategory,
      timeLeaveType: input.timeLeaveType,
    }),
  };
}

function buildBalanceNote(input: {
  consumesBalance: boolean;
  isOverBalance: boolean;
  requestedDays: number;
  pendingLeaveCount: number;
  requestCategory: LeaveRequestCategory;
  timeLeaveType: TimeLeaveType;
}) {
  if (!input.consumesBalance) {
    return "この休暇区分は有給残数を消費しません。";
  }

  if (input.requestedDays <= 0) {
    return input.requestCategory === "TIME_LEAVE" ? "時間帯を入力すると申請量を表示します。" : "日付を入力すると申請量を表示します。";
  }

  if (input.isOverBalance) {
    return "有給残数を超えています。日数や休暇区分を確認してください。";
  }

  if (input.pendingLeaveCount > 0) {
    return `承認待ちの申請が ${input.pendingLeaveCount} 件あります。最終的な残数判定は送信時に再確認されます。`;
  }

  if (input.requestCategory === "TIME_LEAVE" && input.timeLeaveType === "PAID_HOURLY") {
    return "時間有給は8時間を1日として換算した目安です。最終的な残数判定は送信時に再確認されます。";
  }

  return "送信前の目安です。最終的な残数判定は送信時に再確認されます。";
}

function calculateLeaveDays(startDate: string, endDate: string, dayUnit: LeaveDayUnit) {
  if (dayUnit === "HALF") {
    return 0.5;
  }

  const startTime = dateInputToTime(startDate);
  const endTime = dateInputToTime(endDate);
  if (startTime === null || endTime === null || endTime < startTime) {
    return 0;
  }

  return roundDays((endTime - startTime) / 86_400_000 + 1);
}

function calculateTimeLeaveDays(startTime: string, endTime: string) {
  const minutes = timeInputToMinutes(endTime) - timeInputToMinutes(startTime);
  if (minutes <= 0) {
    return 0;
  }

  return roundDays(minutes / DEFAULT_STANDARD_DAY_MINUTES);
}

function timeLeaveTypeToLeaveTypeCode(timeLeaveType: TimeLeaveType) {
  return timeLeaveType === "PAID_HOURLY" ? "PAID" : "SPECIAL";
}

function dateInputToTime(value: string) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) {
    return null;
  }

  const [, year, month, day] = match;
  return Date.UTC(Number(year), Number(month) - 1, Number(day));
}

function timeInputToMinutes(value: string) {
  const match = /^(\d{2}):(\d{2})$/.exec(value);
  if (!match) {
    return 0;
  }

  const [, hour, minute] = match;
  return Number(hour) * 60 + Number(minute);
}

function roundDays(value: number) {
  return Math.round(value * 100) / 100;
}
