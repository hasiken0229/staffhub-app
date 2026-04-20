import { useEffect, useMemo, useState, type FormEvent } from "react";
import type { LeaveDayUnit, LeaveHalfDayType } from "@/types";
import { DailyEditRequestForm } from "./employee-portal/daily-edit-request-form";
import { EmployeePortalLists } from "./employee-portal/employee-portal-lists";
import { EmployeePortalShell } from "./employee-portal/employee-portal-shell";
import type { EmployeePortalSectionProps, LeaveRequestCategory, TimeLeaveType } from "./employee-portal/employee-portal-types";
import { toDateInputValue } from "./employee-portal/employee-portal-utils";
import { LeaveRequestForm } from "./employee-portal/leave-request-form";

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
    <EmployeePortalShell employeeName={employeeName} data={props.data} actions={props.actions}>
      <LeaveRequestForm
        leaveTypes={leaveTypes}
        selectedLeaveType={selectedLeaveType}
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
