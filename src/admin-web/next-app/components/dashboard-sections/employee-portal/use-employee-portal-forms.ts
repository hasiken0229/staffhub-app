import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { loadEmployeeAttendanceDaily, loadEmployeeAttendanceDailyEditRequests, loadEmployeeLeaveRequests, loadEmployeeStandardDayMinutes } from "@/lib/api";
import { ApiRequestError } from "@/lib/api/core";
import { loadEmployeeBalanceAtDate, type EmployeeBalanceAtDate } from "@/lib/api/employee-balance";
import type { FieldErrors } from "@/components/form-field-errors";
import type { AttendanceDaily, AttendanceDailyBreak, AttendanceDailyEditRequestCreatePayload } from "@/types";
import type { LeaveDayUnit, LeaveHalfDayType } from "@/types";
import type { EmployeePortalLeaveType, EmployeePortalSectionProps, LeaveRequestCategory, TimeLeaveType } from "./employee-portal-types";
import { toDateInputValue } from "./employee-portal-utils";
import type { LeaveBalancePreview } from "./leave-request-form";

type DailyEditRequestPayload = AttendanceDailyEditRequestCreatePayload;

type PendingEmployeePortalAction = {
  title: string;
  message: string;
  confirmLabel: string;
  execute: () => Promise<void>;
};

export function useEmployeePortalForms(props: EmployeePortalSectionProps, leaveActive = true) {
  const leaveTypes = props.data.employeePortal.home.leaveTypes ?? [];
  const today = props.data.employeePortal.home.todayAttendance?.targetDate ?? toDateInputValue(new Date());
  const currentMonth = useMemo(() => today.slice(0, 7), [today]);
  const [leaveTypeCode, setLeaveTypeCode] = useState(leaveTypes[0]?.code ?? "");
  const [startDate, setStartDate] = useState(today);
  const [endDate, setEndDate] = useState(today);
  const [dayUnit, setDayUnit] = useState<LeaveDayUnit>("FULL");
  const [halfDayType, setHalfDayType] = useState<LeaveHalfDayType>("AM");
  const [requestCategory, setRequestCategory] = useState<LeaveRequestCategory>("LEAVE");
  const [timeLeaveType, setTimeLeaveType] = useState<TimeLeaveType>("PAID_HOURLY");
  const [targetDate, setTargetDate] = useState(today);
  const [standardDayMinutes, setStandardDayMinutes] = useState<number | null>(null);
  const [startTime, setStartTime] = useState("09:00");
  const [endTime, setEndTime] = useState("10:00");
  const [reason, setReason] = useState("");
  const [editOriginal, setEditOriginal] = useState<DailyEditRequestPayload | null>(null);
  const [editTargetMonth, setEditTargetMonth] = useState(props.data.employeePortal.attendanceTargetMonth ?? currentMonth);
  const selectedMonthRef = useRef(editTargetMonth);
  const attendanceRequestSequence = useRef(0);
  const [editDailyRows, setEditDailyRows] = useState<AttendanceDaily[]>(props.data.employeePortal.attendanceDaily ?? []);
  const [editClockInTime, setEditClockInTime] = useState("09:00");
  const [editClockOutTime, setEditClockOutTime] = useState("18:00");
  const [editBreakStartTime, setEditBreakStartTime] = useState("12:00");
  const [editBreakEndTime, setEditBreakEndTime] = useState("13:00");
  const [editClockInNextDay, setEditClockInNextDay] = useState(false);
  const [editClockOutNextDay, setEditClockOutNextDay] = useState(false);
  const [editBreakStartNextDay, setEditBreakStartNextDay] = useState(false);
  const [editBreakEndNextDay, setEditBreakEndNextDay] = useState(false);
  const [editAdditionalBreaks, setEditAdditionalBreaks] = useState<AttendanceDailyBreak[]>([]);
  const [editRemark, setEditRemark] = useState("");
  const [editEmployeeComment, setEditEmployeeComment] = useState("");
  const [formMessage, setFormMessage] = useState("");
  const [formError, setFormError] = useState("");
  const [formFieldErrors, setFormFieldErrors] = useState<FieldErrors>({});
  const [editFieldErrors, setEditFieldErrors] = useState<FieldErrors>({});
  const [balanceAtDate, setBalanceAtDate] = useState<EmployeeBalanceAtDate | null>(null);
  const [balanceLoadError, setBalanceLoadError] = useState("");
  const [isBalanceLoading, setIsBalanceLoading] = useState(false);
  const [balanceReloadKey, setBalanceReloadKey] = useState(0);
  const balanceDate = requestCategory === "TIME_LEAVE" ? targetDate : startDate;
  const [editRequestMessage, setEditRequestMessage] = useState("");
  const [editRequestError, setEditRequestError] = useState("");
  const [attendanceDailyLoadError, setAttendanceDailyLoadError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isEditRequestSubmitting, setIsEditRequestSubmitting] = useState(false);
  const [isAttendanceDailyLoading, setIsAttendanceDailyLoading] = useState(false);
  const mutationInFlight = useRef(false);
  const [unknownResult, setUnknownResult] = useState(false);
  const [isReconciling, setIsReconciling] = useState(false);
  const [pendingEmployeePortalAction, setPendingEmployeePortalAction] = useState<PendingEmployeePortalAction | null>(null);
  const selectedLeaveType = leaveTypes.find((item) => item.code === leaveTypeCode) ?? null;
  const balanceThrough = requestCategory === "LEAVE" && selectedLeaveType?.requiresBalance && endDate >= startDate
    ? (dayUnit === "HALF" ? startDate : endDate) : undefined;
  const allowsHalfDay = Boolean(selectedLeaveType?.allowsHalfDay);
  const leaveBalancePreview = useMemo(
    () =>
      buildLeaveBalancePreview({
        workdays: balanceThrough ? balanceAtDate?.workdays : undefined,
        hourlyBalance: balanceAtDate?.hourlyBalance,
        hourlyManagementConfigured: balanceAtDate?.hourlyManagementConfigured,
        currentBalance: balanceAtDate?.availableDays ?? balanceAtDate?.currentBalance ?? 0,
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
        standardDayMinutes,
      }),
    [
      balanceAtDate,
      balanceThrough,
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
      standardDayMinutes,
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
    const responseMonth = props.data.employeePortal.attendanceTargetMonth;
    const rows = props.data.employeePortal.attendanceDaily ?? [];
    if ((responseMonth === selectedMonthRef.current || (!responseMonth && rows.every((row) => row.targetDate.startsWith(selectedMonthRef.current))))) {
      setEditDailyRows(rows);
    }
  }, [props.data.employeePortal.attendanceDaily, props.data.employeePortal.attendanceTargetMonth]);

  useEffect(() => {
    if (!allowsHalfDay && dayUnit === "HALF") {
      setDayUnit("FULL");
      setHalfDayType("AM");
      setEndDate(startDate);
    }
  }, [allowsHalfDay, dayUnit, startDate]);

  useEffect(() => {
    if (!leaveActive || requestCategory !== "TIME_LEAVE" || !targetDate) {
      setStandardDayMinutes(null);
      return;
    }

    let isCurrent = true;
    setStandardDayMinutes(null);
    void loadEmployeeStandardDayMinutes(targetDate)
      .then(({ standardDayMinutes: minutes }) => {
        if (isCurrent) {
          setStandardDayMinutes(minutes);
        }
      })
      .catch(() => {
        if (isCurrent) {
          setStandardDayMinutes(null);
        }
      });

    return () => {
      isCurrent = false;
    };
  }, [leaveActive, requestCategory, targetDate]);

  useEffect(() => {
    let current = true;
    setBalanceAtDate(null);
    setBalanceLoadError("");
    if (!leaveActive || !/^\d{4}-\d{2}-\d{2}$/.test(balanceDate)) { setIsBalanceLoading(false); return; }
    setIsBalanceLoading(true);
    void (balanceThrough ? loadEmployeeBalanceAtDate(balanceDate, balanceThrough) : loadEmployeeBalanceAtDate(balanceDate)).then((balance) => {
      if (!current) return;
      if (balance.asOf !== balanceDate) throw new Error("残高の照会日が一致しません。再取得してください。");
      if (balanceThrough && !balance.workdays) throw new Error("対象勤務日を取得できません。再取得してください。");
      setBalanceAtDate(balance);
    }).catch((error) => { if (current) setBalanceLoadError(error instanceof Error ? error.message : "残高を取得できませんでした。"); })
      .finally(() => { if (current) setIsBalanceLoading(false); });
    return () => { current = false; };
  }, [leaveActive, balanceDate, balanceThrough, balanceReloadKey, props.data.employeePortal.home.pendingLeaveCount]);

  function handleDayUnitChange(nextDayUnit: LeaveDayUnit) {
    setDayUnit(nextDayUnit);
    if (nextDayUnit === "HALF") {
      setEndDate(startDate);
    }
  }

  function handleStartDateChange(nextStartDate: string) {
    setStartDate(nextStartDate);
    if (dayUnit === "HALF" || endDate === startDate || (nextStartDate && nextStartDate > endDate)) {
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
    setFormFieldErrors({});
    setFormMessage("");
  }

  function resetDailyEditRequestForm() {
    setEditOriginal(null);
    setEditClockInTime("09:00");
    setEditClockOutTime("18:00");
    setEditBreakStartTime("12:00");
    setEditBreakEndTime("13:00");
    setEditClockInNextDay(false);
    setEditClockOutNextDay(false);
    setEditBreakStartNextDay(false);
    setEditBreakEndNextDay(false);
    setEditAdditionalBreaks([]);
    setEditRemark("");
    setEditEmployeeComment("");
    setEditRequestError("");
    setEditFieldErrors({});
    setEditRequestMessage("");
  }

  function applyDailyRowToEditForm(row: AttendanceDaily) {
    const payload = dailyRowRequestPayload(row);
    setEditOriginal(payload);
    setEditClockInTime(payload.clockInTime ?? "");
    setEditClockOutTime(payload.clockOutTime ?? "");
    setEditBreakStartTime(payload.breaks?.[0]?.startTime ?? "");
    setEditBreakEndTime(payload.breaks?.[0]?.endTime ?? "");
    setEditClockInNextDay(Boolean(payload.clockInNextDay));
    setEditClockOutNextDay(Boolean(payload.clockOutNextDay));
    setEditBreakStartNextDay(Boolean(payload.breaks?.[0]?.startNextDay));
    setEditBreakEndNextDay(Boolean(payload.breaks?.[0]?.endNextDay));
    setEditAdditionalBreaks(payload.breaks?.slice(1) ?? []);
    setEditRemark(row.remark ?? "");
    setEditEmployeeComment("");
    setEditRequestError("");
    setEditFieldErrors({});
    setEditRequestMessage("");
  }

  async function handleEditTargetMonthChange(nextTargetMonth: string) {
    const sequence = ++attendanceRequestSequence.current;
    if (nextTargetMonth !== selectedMonthRef.current) resetDailyEditRequestForm();
    selectedMonthRef.current = nextTargetMonth;
    setEditTargetMonth(nextTargetMonth);
    setEditDailyRows([]);
    setEditRequestError("");
    setEditFieldErrors({});
    setAttendanceDailyLoadError("");
    setIsAttendanceDailyLoading(true);
    try {
      const rows = await (props.actions.onAttendanceMonthChange?.(nextTargetMonth) ?? loadEmployeeAttendanceDaily(nextTargetMonth));
      if (sequence === attendanceRequestSequence.current) {
        if (!rows.every((row) => row.targetDate.startsWith(nextTargetMonth))) throw new Error("対象月と応答の年月が一致しません。再取得してください。");
        setEditDailyRows(rows);
      }
    } catch (error) {
      if (sequence === attendanceRequestSequence.current) {
        setEditDailyRows([]);
        setAttendanceDailyLoadError(error instanceof Error ? error.message : "月次勤怠の取得に失敗しました。");
      }
    } finally {
      if (sequence === attendanceRequestSequence.current) setIsAttendanceDailyLoading(false);
    }
  }

  async function retryAttendanceDailyLoad() {
    await handleEditTargetMonthChange(selectedMonthRef.current);
  }

  function submitDailyEditRequest(original: DailyEditRequestPayload, payload: DailyEditRequestPayload) {
    if (unknownResult || mutationInFlight.current) return;
    setEditRequestError("");
    setEditFieldErrors({});
    setEditRequestMessage("");

    if (!payload.targetDate || !payload.clockInTime || !payload.clockOutTime) {
      setEditFieldErrors({ ...(!payload.targetDate ? { targetDate: ["対象日を入力してください。"] } : {}), ...(!payload.clockInTime ? { clockInTime: ["出勤を入力してください。"] } : {}), ...(!payload.clockOutTime ? { clockOutTime: ["退勤を入力してください。"] } : {}) });
      setEditRequestError("対象日、出勤、退勤を入力してください。");
      return;
    }

    const breaks = payload.breaks ?? [];
    const incompleteBreak = breaks.findIndex((item) => !item.startTime || !item.endTime);
    if (incompleteBreak >= 0) {
      const missingField = breaks[incompleteBreak].startTime ? "endTime" : "startTime";
      setEditFieldErrors({ [`breaks.${incompleteBreak}.${missingField}`]: ["休憩の開始・終了を両方入力してください。"] });
      setEditRequestError("休憩の開始・終了を両方入力するか、不要な休憩を削除してください。");
      return;
    }
    setPendingEmployeePortalAction({
      title: "日次修正を申請しますか？",
      message: [
        `対象日: ${original.targetDate}`,
        `変更前\n${describeDailyRecord(original)}`,
        `申請後\n${describeDailyRecord(payload)}`,
        `申請コメント: ${payload.employeeComment?.trim() || "なし"}`,
      ].join("\n\n"),
      confirmLabel: "日次修正を申請",
      execute: () => executeDailyEditRequest(payload),
    });
  }

  async function executeDailyEditRequest(payload: DailyEditRequestPayload) {
    if (mutationInFlight.current) return;
    mutationInFlight.current = true;
    setIsEditRequestSubmitting(true);
    try {
      const refreshed = await props.actions.onAttendanceDailyEditRequestCreate({
        targetDate: payload.targetDate,
        workTypeId: payload.workTypeId,
        clockInTime: payload.clockInTime,
        clockInNextDay: payload.clockInNextDay,
        clockOutTime: payload.clockOutTime,
        clockOutNextDay: payload.clockOutNextDay,
        breaks: payload.breaks ?? [],
        remark: payload.remark ?? "",
        employeeComment: payload.employeeComment ?? "",
      });
      setEditRequestMessage(
        refreshed !== false
          ? "日次修正申請を登録しました。"
          : "日次修正申請は登録済みです。一覧更新に失敗したため、下の「再試行」で勤怠一覧だけ更新してください。",
      );
      if (payload.employeeComment) setEditEmployeeComment("");
    } catch (error) {
      if (isMutationResultUnknown(error)) setUnknownResult(true);
      setEditFieldErrors(error instanceof ApiRequestError ? error.fieldErrors : {});
      setEditRequestError(error instanceof Error ? error.message : "日次修正申請の登録に失敗しました。");
    } finally {
      mutationInFlight.current = false;
      setIsEditRequestSubmitting(false);
    }
  }

  async function handleLeaveRequestSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (unknownResult || mutationInFlight.current) return;
    setFormError("");
    setFormFieldErrors({});
    setFormMessage("");

    if (requestCategory === "TIME_LEAVE") {
      if (!targetDate || !startTime || !endTime || endTime <= startTime) {
        setFormFieldErrors({ endTime: ["対象日と、開始より後の終了時刻を入力してください。"] });
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

      setPendingEmployeePortalAction({
        title: "時間休暇を申請しますか？",
        message: `対象日: ${targetDate}／時間帯: ${startTime} - ${endTime}／申請量: ${formatHourMinutes(quantityMinutes)}${timeLeaveType === "PAID_HOURLY" ? `／申請後の時間有給残（目安）: ${formatHourMinutes(leaveBalancePreview.projectedMinutes ?? 0)}` : ""}`,
        confirmLabel: "時間休暇を申請",
        execute: async () => {
          if (mutationInFlight.current) return;
          mutationInFlight.current = true;
          setIsSubmitting(true);
          try {
            const refreshed = await props.actions.onLeaveRequestCreate({
          requestCategory: "TIME_LEAVE",
          timeLeaveType,
          targetDate,
          startTime,
          endTime,
          quantityMinutes,
          reason,
            });
            setFormMessage(
              refreshed !== false
                ? "時間休暇申請を登録しました。"
                : "時間休暇申請は登録済みです。一覧更新に失敗したため、最新情報を再読込してください。",
            );
            setReason("");
          } catch (error) {
            if (isMutationResultUnknown(error)) setUnknownResult(true);
          setFormFieldErrors(error instanceof ApiRequestError ? error.fieldErrors : {});
            setFormError(error instanceof Error ? error.message : "時間休暇申請の登録に失敗しました。");
          } finally {
            mutationInFlight.current = false;
            setIsSubmitting(false);
          }
        },
      });
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
      setFormFieldErrors({ endDate: ["終了日は開始日以降にしてください。"] });
      setFormError("終了日は開始日以降にしてください。");
      return;
    }

    const workdays = leaveBalancePreview.workdays;
    if (selectedLeaveType?.requiresBalance && !workdays) {
      setFormError("対象勤務日を確認してから申請してください。");
      return;
    }
    if (workdays && workdays.dates.length === 0) {
      setFormError("選択した期間に休暇対象の勤務日がありません。");
      return;
    }
    const datesMessage = workdays ? "／消化対象日: " + workdays.dates.join("、")
      + (workdays.unconfirmedDates.length ? "／勤務予定未登録（対象日に含む）: " + workdays.unconfirmedDates.join("、") : "") : "";
    setPendingEmployeePortalAction({
      title: "休暇を申請しますか？",
      message: `休暇区分: ${selectedLeaveType?.name ?? leaveTypeCode}／対象日: ${startDate}${normalizedEndDate !== startDate ? ` - ${normalizedEndDate}` : ""}${datesMessage}／申請単位: ${dayUnit === "HALF" ? `半日（${halfDayType === "AM" ? "午前" : "午後"}）` : "全日"}／申請量: ${leaveBalancePreview.requestedDays.toFixed(2)}日／${leaveBalancePreview.requiresPeriodCheck ? "申請後残高は期間中の付与・失効と後日の予約を含めて送信時に再確認します。" : `申請後残高（目安）: ${leaveBalancePreview.projectedBalance.toFixed(2)}日`}`,
      confirmLabel: "休暇を申請",
      execute: async () => {
        if (mutationInFlight.current) return;
        mutationInFlight.current = true;
        setIsSubmitting(true);
        try {
          const refreshed = await props.actions.onLeaveRequestCreate({
        requestCategory: "LEAVE",
        leaveTypeCode,
        startDate,
        endDate: normalizedEndDate,
        ...(workdays ? { expectedDates: workdays.dates } : {}),
        dayUnit,
        halfDayType: dayUnit === "HALF" ? halfDayType : null,
        reason,
          });

          setFormMessage(
            refreshed !== false
              ? "休暇申請を登録しました。"
              : "休暇申請は登録済みです。一覧更新に失敗したため、最新情報を再読込してください。",
          );
          setReason("");
          setDayUnit("FULL");
          setHalfDayType("AM");
          setEndDate(startDate);
        } catch (error) {
          if (isMutationResultUnknown(error)) setUnknownResult(true);
          setFormFieldErrors(error instanceof ApiRequestError ? error.fieldErrors : {});
          setFormError(error instanceof Error ? error.message : "休暇申請の登録に失敗しました。");
        } finally {
          mutationInFlight.current = false;
            setIsSubmitting(false);
        }
      },
    });
  }

  function handleDailyEditRequestSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editOriginal) return;
    submitDailyEditRequest(editOriginal, {
      targetDate: editOriginal.targetDate,
      workTypeId: editOriginal.workTypeId,
      clockInTime: editClockInTime,
      clockInNextDay: editClockInNextDay,
      clockOutTime: editClockOutTime,
      clockOutNextDay: editClockOutNextDay,
      breaks: [
        ...(editBreakStartTime || editBreakEndTime ? [{ startTime: editBreakStartTime, endTime: editBreakEndTime, startNextDay: editBreakStartNextDay, endNextDay: editBreakEndNextDay }] : []),
        ...editAdditionalBreaks,
      ],
      remark: editRemark,
      employeeComment: editEmployeeComment,
    });
  }

  function handleDailyRowSubmit(row: AttendanceDaily) {
    const record = dailyRowRequestPayload(row);
    submitDailyEditRequest(record, record);
  }

  async function reconcileUnknownResult() {
    if (isReconciling) return;
    setIsReconciling(true);
    try {
      if (props.actions.onRequestStatusReload) {
        await props.actions.onRequestStatusReload();
      } else {
        await Promise.all([loadEmployeeLeaveRequests(), loadEmployeeAttendanceDailyEditRequests()]);
        props.actions.onRefresh();
      }
      setUnknownResult(false);
      setFormError(""); setEditRequestError("");
      const message = "申請一覧を再取得しました。「申請状況」で対象日の申請を確認してください。登録済みの申請は再送不要です。";
      setFormMessage(message); setEditRequestMessage(message);
    } catch (error) {
      const message = error instanceof Error ? error.message : "申請状況を確認できませんでした。";
      setFormError(message); setEditRequestError(message);
    } finally { setIsReconciling(false); }
  }

  return {
    leaveTypes,
    leaveRequestFormProps: {
      leaveTypes,
      selectedLeaveType,
      balancePreview: leaveBalancePreview,
      allowsHalfDay,
      values: {
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
      },
      formError,
      fieldErrors: formFieldErrors,
      balanceAsOf: balanceDate,
      hourlyEffectiveOn: balanceAtDate?.hourlyBalance?.effectiveOn,
      isBalanceLoading,
      balanceLoadError,
      balanceAvailable: balanceAtDate !== null && (requestCategory !== "TIME_LEAVE" || timeLeaveType !== "PAID_HOURLY" || ((balanceAtDate.hourlyBalance != null && (!balanceAtDate.hourlyBalance.effectiveOn || targetDate > balanceAtDate.hourlyBalance.effectiveOn)) || balanceAtDate.hourlyManagementConfigured === false && standardDayMinutes !== null)),
      onRetryBalance: () => setBalanceReloadKey((value) => value + 1),
      formMessage,
      isPending: props.data.isPending,
      isSubmitting,
      unknownResult, isReconciling, onReconcileResult: () => void reconcileUnknownResult(),
      onSubmit: (event: FormEvent<HTMLFormElement>) => void handleLeaveRequestSubmit(event),
      onReset: resetLeaveRequestForm,
      onLeaveTypeCodeChange: setLeaveTypeCode,
      onStartDateChange: handleStartDateChange,
      onEndDateChange: setEndDate,
      onDayUnitChange: handleDayUnitChange,
      onHalfDayTypeChange: setHalfDayType,
      onRequestCategoryChange: setRequestCategory,
      onTimeLeaveTypeChange: setTimeLeaveType,
      onTargetDateChange: setTargetDate,
      onStartTimeChange: setStartTime,
      onEndTimeChange: setEndTime,
      onReasonChange: setReason,
    },
    dailyEditRequestFormProps: {
      values: {
        editTargetDate: editOriginal?.targetDate ?? "",
        editTargetMonth,
        editClockInTime,
        editClockOutTime,
        editBreakStartTime,
        editBreakEndTime,
        editClockInNextDay,
        editClockOutNextDay,
        editBreakStartNextDay,
        editBreakEndNextDay,
        editAdditionalBreaks,
        editRemark,
        editEmployeeComment,
      },
      attendanceDaily: editDailyRows,
      attendanceDailyLoadError,
      requestCount: props.data.employeePortal.attendanceDailyEditRequests?.length ?? 0,
      editRequestError,
      fieldErrors: editFieldErrors,
      editRequestMessage,
      isPending: props.data.isPending,
      isEditRequestSubmitting: isEditRequestSubmitting || unknownResult,
      unknownResult, isReconciling, onReconcileResult: () => void reconcileUnknownResult(),
      isAttendanceDailyLoading,
      isDailyEditFormOpen: editOriginal !== null,
      onSubmit: (event: FormEvent<HTMLFormElement>) => void handleDailyEditRequestSubmit(event),
      onReset: resetDailyEditRequestForm,
      selectHistoryMonth: (month: string) => {
        if (selectedMonthRef.current === month) return;
        ++attendanceRequestSequence.current;
        selectedMonthRef.current = month;
        setEditTargetMonth(month);
        setEditDailyRows([]);
        setAttendanceDailyLoadError("");
        setIsAttendanceDailyLoading(false);
        resetDailyEditRequestForm();
      },
      onEditTargetMonthChange: (value: string) => void handleEditTargetMonthChange(value),
      onRetryAttendanceDailyLoad: () => void retryAttendanceDailyLoad(),
      onDailyRowEdit: applyDailyRowToEditForm,
      onDailyRowSubmit: (row: AttendanceDaily) => void handleDailyRowSubmit(row),
      onEditClockInTimeChange: setEditClockInTime,
      onEditClockOutTimeChange: setEditClockOutTime,
      onEditBreakStartTimeChange: setEditBreakStartTime,
      onEditBreakEndTimeChange: setEditBreakEndTime,
      onEditClockInNextDayChange: setEditClockInNextDay,
      onEditClockOutNextDayChange: setEditClockOutNextDay,
      onEditBreakStartNextDayChange: setEditBreakStartNextDay,
      onEditBreakEndNextDayChange: setEditBreakEndNextDay,
      onEditAdditionalBreaksChange: setEditAdditionalBreaks,
      onEditRemarkChange: setEditRemark,
      onEditEmployeeCommentChange: setEditEmployeeComment,
    },
    confirmationDialogProps: {
      open: pendingEmployeePortalAction !== null,
      title: pendingEmployeePortalAction?.title ?? "確認",
      message: pendingEmployeePortalAction?.message ?? "",
      confirmLabel: pendingEmployeePortalAction?.confirmLabel ?? "実行",
      onCancel: () => setPendingEmployeePortalAction(null),
      onConfirm: () => {
        const action = pendingEmployeePortalAction;
        setPendingEmployeePortalAction(null);
        if (action) {
          void action.execute();
        }
      },
    },
  };
}

function describeDailyRecord(record: DailyEditRequestPayload) {
  const time = (value?: string | null, nextDay?: boolean | null) => value ? `${nextDay ? "翌日 " : ""}${value}` : "未登録";
  const breaks = (record.breaks ?? []).map((item) => `${time(item.startTime, item.startNextDay)} - ${time(item.endTime, item.endNextDay)}`);
  return [
    `出勤: ${time(record.clockInTime, record.clockInNextDay)} ／ 退勤: ${time(record.clockOutTime, record.clockOutNextDay)}`,
    `休憩: ${breaks.join("、") || "なし"}`,
    `備考: ${record.remark?.trim() || "なし"}`,
  ].join("\n");
}

function dailyRowRequestPayload(row: AttendanceDaily): DailyEditRequestPayload {
  const clockIn = row.clockInAt ?? row.rawClockInAt;
  const clockOut = row.clockOutAt ?? row.rawClockOutAt;
  const nextDay = (value?: string | null) => Boolean(value && /^\d{4}-\d{2}-\d{2}[T ]/.test(value) && value.slice(0, 10) > row.targetDate);
  return {
    targetDate: row.targetDate,
    workTypeId: row.workTypeId ?? null,
    clockInTime: toTimeInputValue(clockIn), clockInNextDay: nextDay(clockIn),
    clockOutTime: toTimeInputValue(clockOut), clockOutNextDay: nextDay(clockOut),
    breaks: (row.breaks ?? []).map((item) => ({
      startTime: item.startTime ?? toTimeInputValue(item.startAt), endTime: item.endTime ?? toTimeInputValue(item.endAt),
      startNextDay: item.startNextDay ?? nextDay(item.startAt), endNextDay: item.endNextDay ?? nextDay(item.endAt),
    })),
    remark: row.remark ?? "",
  };
}

function toTimeInputValue(value?: string | null) {
  if (!value) {
    return null;
  }

  const dateMatch = /[T ](\d{2}):(\d{2})/.exec(value);
  if (dateMatch) {
    return `${dateMatch[1]}:${dateMatch[2]}`;
  }

  const timeMatch = /^(\d{2}):(\d{2})/.exec(value);
  if (timeMatch) {
    return `${timeMatch[1]}:${timeMatch[2]}`;
  }

  return null;
}

type LeaveBalancePreviewInput = {
  workdays?: EmployeeBalanceAtDate["workdays"];
  hourlyBalance?: import("@/types").HourlyPaidLeaveBalance | null;
  hourlyManagementConfigured?: boolean;
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
  standardDayMinutes: number | null;
};

function formatHourMinutes(value: number) { return `${Math.floor(value / 60)}時間${value % 60 ? `${value % 60}分` : ""}`; }

function buildLeaveBalancePreview(input: LeaveBalancePreviewInput): LeaveBalancePreview {
  if (input.requestCategory === "TIME_LEAVE") {
    const requested = Math.max(0, timeInputToMinutes(input.endTime) - timeInputToMinutes(input.startTime));
    const legacyDays = input.timeLeaveType === "PAID_HOURLY" && !input.hourlyBalance && input.hourlyManagementConfigured === false;
    const requestedDays = legacyDays && input.standardDayMinutes ? requested / input.standardDayMinutes : 0;
    const available = input.timeLeaveType === "PAID_HOURLY"
      ? input.hourlyBalance?.availableMinutes ?? (legacyDays && input.standardDayMinutes ? Math.floor(input.currentBalance * input.standardDayMinutes + 1e-7) : null) : null;
    return { currentBalance: input.currentBalance, requestedDays, requestedMinutes: requested,
      availableMinutes: available, projectedMinutes: available === null ? null : available - requested,
      projectedBalance: input.currentBalance - requestedDays, consumesBalance: legacyDays,
      isOverBalance: available !== null && requested > available, standardDayMinutes: input.standardDayMinutes,
      note: input.timeLeaveType === "PAID_HOURLY"
        ? input.hourlyBalance ? `時間有給を ${formatHourMinutes(requested)}使用します。申請中を差し引いた申請可能量です（期限 ${input.hourlyBalance.expiresOn}）。日数の有給は減りません。`
          : legacyDays ? `所定1日 ${input.standardDayMinutes ?? "未確認"}分で日数換算する職員です。今回 ${roundDays(requestedDays)}日、申請後 ${roundDays(input.currentBalance - requestedDays)}日（目安）。`
          : "対象日時点の時間有給残高を確認してください。"
        : "日数の有給は減りません。この時間休暇の最終的な残高は送信時に確認します。" };
  }
  const balanceType = input.selectedLeaveType;
  const requestedDays = input.workdays ? input.workdays.dates.length * (input.dayUnit === "HALF" ? 0.5 : 1) : calculateLeaveDays(input.startDate, input.dayUnit === "HALF" ? input.startDate : input.endDate, input.dayUnit);
  const consumesBalance = Boolean(balanceType?.requiresBalance);
  const projectedBalance = roundDays(input.currentBalance - (consumesBalance ? requestedDays : 0));
  const requiresPeriodCheck = consumesBalance && input.dayUnit === "FULL" && requestedDays > 1;
  const isOverBalance = consumesBalance && requestedDays > 0 && projectedBalance < 0 && !requiresPeriodCheck;

  return {
    workdays: input.workdays,
    currentBalance: roundDays(input.currentBalance),
    requestedDays,
    projectedBalance,
    consumesBalance,
    isOverBalance,
    requiresPeriodCheck,
    standardDayMinutes: input.standardDayMinutes,
    note: requiresPeriodCheck ? "開始日時点の残高を表示しています。期間中の付与・失効と後日の予約を含めて送信時に再確認します。" : buildBalanceNote({
      consumesBalance,
      isOverBalance,
      requestedDays,
      pendingLeaveCount: input.pendingLeaveCount,
      requestCategory: input.requestCategory,
      timeLeaveType: input.timeLeaveType,
      standardDayMinutes: input.standardDayMinutes,
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
  standardDayMinutes: number | null;
}) {
  if (input.requestCategory === "TIME_LEAVE" && input.standardDayMinutes === null) {
    return "対象日の所定時間を確認しています。";
  }

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
    return `時間有給は所定1日 ${input.standardDayMinutes} 分として換算した目安です。最終的な残数判定は送信時に再確認されます。`;
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

function isMutationResultUnknown(error: unknown) { return error instanceof ApiRequestError && (error.code === "UNKNOWN_RESULT" || error.code === "INVALID_RESPONSE" || error.status >= 500); }
