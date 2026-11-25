import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import { loadAttendanceShiftSchedules, saveAttendanceShiftSchedule } from "@/lib/api";
import { ApiRequestError } from "@/lib/api/core";
import { FieldError, ValidationSummary, type FieldErrors } from "@/components/form-field-errors";
import type { AttendanceShiftSchedule, DashboardData, EmployeeAttendanceSetting } from "@/types";

type ShiftPayload = Parameters<typeof saveAttendanceShiftSchedule>[0];
type Props = { dashboard: DashboardData; targetMonth: string };

export function AttendanceShiftPanel({ dashboard, targetMonth }: Props) {
  const [shifts, setShifts] = useState<AttendanceShiftSchedule[]>([]);
  const [loading, setLoading] = useState(true);
  const [fresh, setFresh] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const requestSequence = useRef(0);
  const saving = useRef(false);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; requestSequence.current++; }; }, []);
  const reload = useCallback(async () => {
    if (saving.current) return;
    const request = ++requestSequence.current;
    setLoading(true); setFresh(false); setError(""); setFieldErrors({});
    try {
      const rows = await loadAttendanceShiftSchedules({ targetMonth });
      if (!mounted.current || request !== requestSequence.current) return;
      setShifts(rows.filter((row) => row.targetDate.startsWith(`${targetMonth}-`))); setFresh(true);
    } catch (loadError) {
      if (mounted.current && request === requestSequence.current) setError(loadError instanceof Error ? loadError.message : "シフトを取得できませんでした。");
    } finally { if (mounted.current && request === requestSequence.current) setLoading(false); }
  }, [targetMonth]);
  useEffect(() => { void reload(); }, [reload, dashboard.attendanceShiftSchedules]);

  async function save(payload: ShiftPayload) {
    if (saving.current || loading || !fresh) return;
    if (!payload.targetDate.startsWith(`${targetMonth}-`)) { setError("対象月内の日付を選択してください。"); return; }
    saving.current = true; setBusy(true); setMessage(""); setError(""); setFieldErrors({});
    try {
      const rows = await saveAttendanceShiftSchedule(payload);
      if (!mounted.current) return;
      setShifts((current) => [...current.filter((row) => row.employeeId !== payload.employeeId), ...rows.filter((row) => row.targetDate.startsWith(`${targetMonth}-`))]
        .sort((a, b) => `${a.employeeCode}-${a.targetDate}`.localeCompare(`${b.employeeCode}-${b.targetDate}`)));
      setMessage("月次シフトを保存しました。既存の打刻・勤怠採用値は変更していません。");
    } catch (saveError) {
      if (!mounted.current) return;
      setError(saveError instanceof Error ? saveError.message : "シフトを保存できませんでした。");
      if (saveError instanceof ApiRequestError) {
        setFieldErrors(saveError.fieldErrors);
        if (["UNKNOWN_RESULT", "INVALID_RESPONSE", "SESSION_CHANGED"].includes(saveError.code ?? "") || saveError.status >= 500) setFresh(false);
      }
    } finally { saving.current = false; if (mounted.current) setBusy(false); }
  }

  return <section id="attendance-shifts" className="panel action-panel anchor-panel">
    <div className="panel-header"><div><p className="panel-kicker">月次シフト</p><h3>職員別の日別予定を登録</h3><p>対象月 {targetMonth}・全職員</p></div><span className="panel-meta">{fresh ? `${shifts.length} 件` : "未確認"}</span></div>
    <p className="muted">日付と職員を選ぶと登録済みの予定を読み込みます。シフトのCSV・Excel取込には現在対応していません。</p>
    <div className="button-row"><button type="button" className="secondary-button" disabled={loading || busy} onClick={() => { setMessage(""); void reload(); }}>シフトを再読込</button></div>
    {loading ? <p role="status">対象月のシフトを読み込んでいます。</p> : null}
    {error ? <p className="banner" role="alert">{error}{!fresh ? " 再保存する前にシフトを再読込して登録状況を確認してください。" : ""}</p> : null}
    {message ? <p className="feedback" role="status">{message}</p> : null}
    <ShiftScheduleForm dashboard={dashboard} shifts={shifts} targetMonth={targetMonth} disabled={loading || busy || !fresh} busy={busy} errors={fieldErrors} onSave={save} />
    <div className="table-wrap" role="region" aria-label="登録済みシフト一覧" tabIndex={0}><table className="data-table compact-table"><thead><tr><th>日付</th><th>職員</th><th>勤務区分</th><th>予定出勤</th><th>予定退勤</th><th>メモ</th></tr></thead><tbody>
      {!fresh ? <tr><td colSpan={6} className="table-empty-cell">対象月のシフトは未確認です</td></tr> : shifts.length === 0 ? <tr><td colSpan={6} className="table-empty-cell">登録済みシフトはありません</td></tr> : shifts.map((shift) => <tr key={shift.id}><td>{shift.targetDate}</td><td>{shift.employeeCode} {shift.employeeName}</td><td>{shift.workTypeName ?? "未設定"}</td><td>{formatShiftTime(shift.scheduledClockInTime)}</td><td>{formatShiftTime(shift.scheduledClockOutTime)}</td><td>{shift.note ?? "-"}</td></tr>)}
    </tbody></table></div>
  </section>;
}

function ShiftScheduleForm({ dashboard, shifts, targetMonth, disabled, busy, errors, onSave }: Props & {
  shifts: AttendanceShiftSchedule[]; disabled: boolean; busy: boolean; errors: FieldErrors; onSave: (payload: ShiftPayload) => Promise<void>;
}) {
  const [selectedId, setSelectedId] = useState(String(dashboard.employees[0]?.id ?? ""));
  const employeeId = dashboard.employees.some((employee) => String(employee.id) === selectedId) ? selectedId : String(dashboard.employees[0]?.id ?? "");
  const [date, setDate] = useState(`${targetMonth}-01`);
  const selected = shifts.find((row) => String(row.employeeId) === employeeId && row.targetDate === date);
  const setting = dashboard.employeeAttendanceSettings?.find((row) => String(row.employeeId) === employeeId);
  const lastDay = String(new Date(Number(targetMonth.slice(0, 4)), Number(targetMonth.slice(5, 7)), 0).getDate()).padStart(2, "0");
  const dateValid = date >= `${targetMonth}-01` && date <= `${targetMonth}-${lastDay}`;
  const selectors = <div className="form-grid">
    <label>職員<select name="employeeId" value={employeeId} disabled={disabled} onChange={(event) => setSelectedId(event.target.value)}>{dashboard.employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.employeeCode} {employee.name}</option>)}</select><FieldError errors={errors} field="employeeId" idPrefix="shift" /></label>
    <label>日付<input name="targetDate" type="date" required min={`${targetMonth}-01`} max={`${targetMonth}-${lastDay}`} value={date} disabled={disabled} onChange={(event) => setDate(event.target.value)} aria-invalid={!dateValid || !!errors.targetDate} aria-describedby={errors.targetDate ? "shift-error-targetDate" : !dateValid ? "shift-invalid-date" : undefined} /><FieldError errors={errors} field="targetDate" idPrefix="shift" />{!dateValid ? <span id="shift-invalid-date" className="field-error">対象月内の日付を選択してください。</span> : null}</label>
  </div>;
  return <ShiftFields key={`${employeeId}-${date}-${JSON.stringify(selected ?? setting ?? null)}`} selectors={selectors} employeeId={employeeId} date={date} selected={selected} setting={setting} workTypes={dashboard.systemMasters.workTypes} disabled={disabled || !dateValid} busy={busy} errors={errors} onSave={onSave} />;
}

function ShiftFields({ selectors, employeeId, date, selected, setting, workTypes, disabled, busy, errors, onSave }: {
  selectors: ReactNode; employeeId: string; date: string; selected?: AttendanceShiftSchedule; setting?: EmployeeAttendanceSetting;
  workTypes: DashboardData["systemMasters"]["workTypes"]; disabled: boolean; busy: boolean; errors: FieldErrors; onSave: (payload: ShiftPayload) => Promise<void>;
}) {
  const [workTypeId, setWorkTypeId] = useState(String(selected?.workTypeId ?? ""));
  const [clockIn, setClockIn] = useState(selected ? selected.scheduledClockInTime ?? "" : setting?.standardClockInTime ?? "");
  const [clockOut, setClockOut] = useState(selected ? selected.scheduledClockOutTime ?? "" : setting?.standardClockOutTime ?? "");
  const [note, setNote] = useState(selected?.note ?? "");
  return <form className="stack-form compact-form" onSubmit={(event) => { event.preventDefault(); if (!disabled && employeeId) void onSave({ employeeId: Number(employeeId), targetDate: date, workTypeId: workTypeId ? Number(workTypeId) : null, scheduledClockInTime: clockIn || null, scheduledClockOutTime: clockOut || null, note: note || null }); }}>
    <ValidationSummary errors={errors} />{selectors}
    <p>{selected ? "登録済みシフトの編集" : "新規登録"}：{date}</p>
    <div className="form-grid">
      <label>勤務区分<select name="workTypeId" value={workTypeId} disabled={disabled} onChange={(event) => { const value = event.target.value; setWorkTypeId(value); const type = workTypes.find((row) => String(row.id) === value); if (type) { setClockIn(type.startTime ?? ""); setClockOut(type.endTime ?? ""); } }}><option value="">未設定</option>{workTypes.filter((row) => row.isActive || String(row.id) === workTypeId).map((row) => <option key={row.id} value={row.id}>{row.name}{row.isActive ? "" : "（無効）"}</option>)}</select><FieldError errors={errors} field="workTypeId" idPrefix="shift" /></label>
      <label>予定出勤<input name="scheduledClockInTime" type="time" value={clockIn} disabled={disabled} onChange={(event) => setClockIn(event.target.value)} aria-invalid={!!errors.scheduledClockInTime} aria-describedby={errors.scheduledClockInTime ? "shift-error-scheduledClockInTime" : undefined} /><FieldError errors={errors} field="scheduledClockInTime" idPrefix="shift" /></label>
      <label>予定退勤<input name="scheduledClockOutTime" type="time" value={clockOut} disabled={disabled} onChange={(event) => setClockOut(event.target.value)} aria-invalid={!!errors.scheduledClockOutTime} aria-describedby={errors.scheduledClockOutTime ? "shift-error-scheduledClockOutTime" : undefined} /><FieldError errors={errors} field="scheduledClockOutTime" idPrefix="shift" /></label>
      <label>メモ<input name="note" maxLength={1000} value={note} disabled={disabled} onChange={(event) => setNote(event.target.value)} /><FieldError errors={errors} field="note" idPrefix="shift" /></label>
    </div>
    <p className="muted">勤務区分を変更すると、その区分の基本時刻を予定に入れます。時刻が空欄の場合は個人別の基本時刻を使います。00:00〜05:00は対象日の翌日です。空欄で保存してもシフト行の削除にはなりません。</p>
    <div className="button-row"><button type="submit" disabled={disabled || !employeeId}>{busy ? "保存中…" : "シフトを保存"}</button></div>
  </form>;
}

function formatShiftTime(time?: string | null) { return time ? `${time <= "05:00" ? "翌日 " : ""}${time}` : "個人設定を使用"; }
