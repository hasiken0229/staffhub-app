import { useEffect, useRef, useState } from "react";
import { saveAttendanceBreakRule, saveEmployeeAttendanceSetting } from "@/lib/api";
import { ApiRequestError } from "@/lib/api/core";
import { FieldError, ValidationSummary, type FieldErrors } from "@/components/form-field-errors";
import { AttendanceShiftPanel } from "@/components/dashboard-sections/attendance/attendance-shift-panel";
import type { AttendanceBreakRule, DashboardData, EmployeeAttendanceSetting } from "@/types";

type Props = { activePanel: string; dashboard: DashboardData; targetMonth: string };
type BreakPayload = Parameters<typeof saveAttendanceBreakRule>[0];
type EmployeePayload = Parameters<typeof saveEmployeeAttendanceSetting>[0];

export function AttendanceSettingsPanels({ activePanel, dashboard, targetMonth }: Props) {
  const [settings, setSettings] = useState(dashboard.employeeAttendanceSettings ?? []);
  const [rule, setRule] = useState(dashboard.attendanceBreakRule ?? null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [errorForm, setErrorForm] = useState<"break" | "employee" | null>(null);
  const [busy, setBusy] = useState(false);
  const saving = useRef(false);
  useEffect(() => { setSettings(dashboard.employeeAttendanceSettings ?? []); }, [dashboard.employeeAttendanceSettings]);
  useEffect(() => { setRule(dashboard.attendanceBreakRule ?? null); }, [dashboard.attendanceBreakRule]);
  async function save(kind: "break" | "employee", action: () => Promise<void>) {
    if (saving.current) return;
    saving.current = true; setBusy(true); setError(""); setMessage(""); setErrors({}); setErrorForm(kind);
    try { await action(); }
    catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "勤務設定を保存できませんでした。");
      if (saveError instanceof ApiRequestError) setErrors(saveError.fieldErrors);
    } finally { saving.current = false; setBusy(false); }
  }
  if (activePanel === "attendance-shifts") return <AttendanceShiftPanel key={targetMonth} dashboard={dashboard} targetMonth={targetMonth} />;
  if (activePanel !== "attendance-settings") return null;
  return <section id="attendance-settings" className="panel action-panel anchor-panel">
    <div className="panel-header"><div><p className="panel-kicker">勤務設定</p><h3>個人別勤務時間・休憩ルール</h3></div><span className="panel-meta">{settings.length} 名</span></div>
    <BreakRuleForm key={JSON.stringify(rule)} rule={rule} busy={busy} errors={errorForm === "break" ? errors : {}}
      onSave={(payload) => save("break", async () => { setRule(await saveAttendanceBreakRule(payload)); setMessage("休憩ルールを保存しました。"); })} />
    <EmployeeSettingForm settings={settings} busy={busy} errors={errorForm === "employee" ? errors : {}}
      onSave={(payload) => save("employee", async () => { setSettings(await saveEmployeeAttendanceSetting(payload)); setMessage("個人別勤務設定を保存しました。"); })} />
    {error ? <p className="banner" role="alert">{error}</p> : null}
    {message ? <p className="feedback" role="status">{message}</p> : null}
  </section>;
}

function BreakRuleForm({ rule, onSave, busy, errors }: { rule: AttendanceBreakRule | null; onSave: (payload: BreakPayload) => Promise<void>; busy: boolean; errors: FieldErrors }) {
  const [base, setBase] = useState(String(rule?.baseBreakMinutes ?? 45));
  const [threshold, setThreshold] = useState(String(rule?.thresholdWorkMinutes ?? 480));
  const [thresholdBreak, setThresholdBreak] = useState(String(rule?.thresholdBreakMinutes ?? 60));
  const [note, setNote] = useState(rule?.note ?? "");
  return <form className="stack-form compact-form" onSubmit={(event) => { event.preventDefault(); if (!busy) void onSave({ baseBreakMinutes: Number(base), thresholdWorkMinutes: Number(threshold), thresholdBreakMinutes: Number(thresholdBreak), note: note || null }); }}>
    <ValidationSummary errors={errors} />
    <div className="form-grid">
      <label>基本休憩（分）<input name="baseBreakMinutes" type="number" min={0} max={240} required disabled={busy} value={base} onChange={(event) => setBase(event.target.value)} aria-invalid={!!errors.baseBreakMinutes} aria-describedby={errors.baseBreakMinutes ? "break-rule-error-baseBreakMinutes" : undefined} /><FieldError errors={errors} field="baseBreakMinutes" idPrefix="break-rule" /></label>
      <label>しきい値（分）<input name="thresholdWorkMinutes" type="number" min={1} max={1440} required disabled={busy} value={threshold} onChange={(event) => setThreshold(event.target.value)} aria-invalid={!!errors.thresholdWorkMinutes} aria-describedby={errors.thresholdWorkMinutes ? "break-rule-error-thresholdWorkMinutes" : undefined} /><FieldError errors={errors} field="thresholdWorkMinutes" idPrefix="break-rule" /></label>
      <label>しきい値以上の休憩（分）<input name="thresholdBreakMinutes" type="number" min={0} max={240} required disabled={busy} value={thresholdBreak} onChange={(event) => setThresholdBreak(event.target.value)} aria-invalid={!!errors.thresholdBreakMinutes} aria-describedby={errors.thresholdBreakMinutes ? "break-rule-error-thresholdBreakMinutes" : undefined} /><FieldError errors={errors} field="thresholdBreakMinutes" idPrefix="break-rule" /></label>
      <label>メモ<input name="note" maxLength={1000} disabled={busy} value={note} onChange={(event) => setNote(event.target.value)} /><FieldError errors={errors} field="note" idPrefix="break-rule" /></label>
    </div><div className="button-row"><button type="submit" disabled={busy}>{busy ? "保存中…" : "休憩ルールを保存"}</button></div>
  </form>;
}

function EmployeeSettingForm({ settings, onSave, busy, errors }: { settings: EmployeeAttendanceSetting[]; onSave: (payload: EmployeePayload) => Promise<void>; busy: boolean; errors: FieldErrors }) {
  const [selectedId, setSelectedId] = useState(String(settings[0]?.employeeId ?? ""));
  const employeeId = settings.some((setting) => String(setting.employeeId) === selectedId) ? selectedId : String(settings[0]?.employeeId ?? "");
  const selected = settings.find((setting) => String(setting.employeeId) === employeeId);
  return <EmployeeSettingFields key={JSON.stringify(selected)} selected={selected} employeeId={employeeId} settings={settings} onEmployeeChange={setSelectedId} onSave={onSave} busy={busy} errors={errors} />;
}

function EmployeeSettingFields({ selected, employeeId, settings, onEmployeeChange, onSave, busy, errors }: {
  selected?: EmployeeAttendanceSetting; employeeId: string; settings: EmployeeAttendanceSetting[]; onEmployeeChange: (id: string) => void;
  onSave: (payload: EmployeePayload) => Promise<void>; busy: boolean; errors: FieldErrors;
}) {
  const [clockIn, setClockIn] = useState(selected?.standardClockInTime ?? "");
  const [clockOut, setClockOut] = useState(selected?.standardClockOutTime ?? "");
  const [includeBefore, setIncludeBefore] = useState(selected?.includeBeforeStart ?? false);
  const [includeAfter, setIncludeAfter] = useState(selected?.includeAfterEnd ?? false);
  return <form className="stack-form compact-form" onSubmit={(event) => { event.preventDefault(); if (!busy && employeeId) void onSave({ employeeId: Number(employeeId), standardClockInTime: clockIn || null, standardClockOutTime: clockOut || null, includeBeforeStart: includeBefore, includeAfterEnd: includeAfter }); }}>
    <ValidationSummary errors={errors} />
    <div className="form-grid">
      <label>職員<select name="employeeId" value={employeeId} disabled={busy || settings.length === 0} onChange={(event) => onEmployeeChange(event.target.value)}>{settings.map((setting) => <option key={setting.employeeId} value={setting.employeeId}>{setting.employeeCode} {setting.employeeName}</option>)}</select><FieldError errors={errors} field="employeeId" idPrefix="employee-setting" /></label>
      <label>基本出勤<input name="standardClockInTime" type="time" disabled={busy} value={clockIn} onChange={(event) => setClockIn(event.target.value)} aria-invalid={!!errors.standardClockInTime} aria-describedby={errors.standardClockInTime ? "employee-setting-error-standardClockInTime" : undefined} /><FieldError errors={errors} field="standardClockInTime" idPrefix="employee-setting" /></label>
      <label>基本退勤<input name="standardClockOutTime" type="time" disabled={busy} value={clockOut} onChange={(event) => setClockOut(event.target.value)} aria-invalid={!!errors.standardClockOutTime} aria-describedby={errors.standardClockOutTime ? "employee-setting-error-standardClockOutTime" : undefined} /><FieldError errors={errors} field="standardClockOutTime" idPrefix="employee-setting" /></label>
      <label className="check-row"><input name="includeBeforeStart" type="checkbox" disabled={busy} checked={includeBefore} onChange={(event) => setIncludeBefore(event.target.checked)} />勤務前の打刻を含める</label>
      <label className="check-row"><input name="includeAfterEnd" type="checkbox" disabled={busy} checked={includeAfter} onChange={(event) => setIncludeAfter(event.target.checked)} />勤務後の打刻を含める</label>
    </div><p className="muted">基本時刻が未設定の場合は空欄で表示します。00:00〜05:00の予定時刻は対象日の翌日として扱います。</p>
    <div className="button-row"><button type="submit" disabled={busy || !employeeId}>{busy ? "保存中…" : "個人別設定を保存"}</button></div>
  </form>;
}
