import { useMemo, useState } from "react";
import {
  loadAttendanceShiftSchedules,
  saveAttendanceBreakRule,
  saveAttendanceShiftSchedule,
  saveEmployeeAttendanceSetting,
} from "@/lib/api";
import type { AttendanceBreakRule, AttendanceShiftSchedule, DashboardData, EmployeeAttendanceSetting } from "@/types";

type AttendanceSettingsPanelsProps = {
  activePanel: string;
  dashboard: DashboardData;
  targetMonth: string;
};

export function AttendanceSettingsPanels({ activePanel, dashboard, targetMonth }: AttendanceSettingsPanelsProps) {
  const [settings, setSettings] = useState<EmployeeAttendanceSetting[]>(dashboard.employeeAttendanceSettings ?? []);
  const [shifts, setShifts] = useState<AttendanceShiftSchedule[]>(dashboard.attendanceShiftSchedules ?? []);
  const [breakRule, setBreakRule] = useState<AttendanceBreakRule | null>(dashboard.attendanceBreakRule ?? null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  if (activePanel === "attendance-settings") {
    return (
      <section id="attendance-settings" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <p className="panel-kicker">勤務設定</p>
            <h3>個人別勤務時間・休憩ルール</h3>
          </div>
          <span className="panel-meta">{settings.length} 名</span>
        </div>
        <BreakRuleForm rule={breakRule} onSave={async (nextRule) => {
          try {
            const saved = await saveAttendanceBreakRule(nextRule);
            setBreakRule(saved);
            setMessage("休憩ルールを保存しました。");
            setError("");
          } catch (saveError) {
            setError(saveError instanceof Error ? saveError.message : "休憩ルールの保存に失敗しました。");
          }
        }} />
        <EmployeeSettingForm settings={settings} onSave={async (payload) => {
          try {
            const saved = await saveEmployeeAttendanceSetting(payload);
            setSettings(saved);
            setMessage("個人別勤務設定を保存しました。");
            setError("");
          } catch (saveError) {
            setError(saveError instanceof Error ? saveError.message : "個人別勤務設定の保存に失敗しました。");
          }
        }} />
        {error ? <p className="banner">{error}</p> : null}
        {message ? <p className="feedback">{message}</p> : null}
      </section>
    );
  }

  if (activePanel === "attendance-shifts") {
    return (
      <section id="attendance-shifts" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <p className="panel-kicker">月次シフト</p>
            <h3>職員別の日別予定を登録</h3>
          </div>
          <span className="panel-meta">{shifts.length} 件</span>
        </div>
        <ShiftScheduleForm
          employees={dashboard.employees}
          workTypes={dashboard.systemMasters.workTypes}
          targetMonth={targetMonth}
          onSave={async (payload) => {
            try {
              const saved = await saveAttendanceShiftSchedule(payload);
              const refreshed = await loadAttendanceShiftSchedules({ targetMonth, employeeId: payload.employeeId });
              setShifts(mergeShiftRows(shifts, saved.length > 0 ? saved : refreshed, payload.employeeId));
              setMessage("月次シフトを保存しました。");
              setError("");
            } catch (saveError) {
              setError(saveError instanceof Error ? saveError.message : "月次シフトの保存に失敗しました。");
            }
          }}
        />
        <div className="table-scroll">
          <table className="data-table compact-table">
            <thead>
              <tr>
                <th>日付</th>
                <th>職員</th>
                <th>勤務区分</th>
                <th>予定出勤</th>
                <th>予定退勤</th>
                <th>メモ</th>
              </tr>
            </thead>
            <tbody>
              {shifts.length === 0 ? (
                <tr>
                  <td colSpan={6} className="table-empty-cell">登録済みシフトはありません</td>
                </tr>
              ) : (
                shifts.map((shift) => (
                  <tr key={shift.id}>
                    <td>{shift.targetDate}</td>
                    <td>{shift.employeeCode} {shift.employeeName}</td>
                    <td>{shift.workTypeName ?? "-"}</td>
                    <td>{shift.scheduledClockInTime ?? "-"}</td>
                    <td>{shift.scheduledClockOutTime ?? "-"}</td>
                    <td>{shift.note ?? "-"}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {error ? <p className="banner">{error}</p> : null}
        {message ? <p className="feedback">{message}</p> : null}
      </section>
    );
  }

  return null;
}

function BreakRuleForm({ rule, onSave }: { rule: AttendanceBreakRule | null; onSave: (payload: {
  baseBreakMinutes: number;
  thresholdWorkMinutes: number;
  thresholdBreakMinutes: number;
  note?: string | null;
}) => Promise<void> }) {
  const [baseBreakMinutes, setBaseBreakMinutes] = useState(String(rule?.baseBreakMinutes ?? 45));
  const [thresholdWorkMinutes, setThresholdWorkMinutes] = useState(String(rule?.thresholdWorkMinutes ?? 480));
  const [thresholdBreakMinutes, setThresholdBreakMinutes] = useState(String(rule?.thresholdBreakMinutes ?? 60));
  const [note, setNote] = useState(rule?.note ?? "");

  return (
    <form className="stack-form compact-form" onSubmit={(event) => {
      event.preventDefault();
      void onSave({
        baseBreakMinutes: Number(baseBreakMinutes),
        thresholdWorkMinutes: Number(thresholdWorkMinutes),
        thresholdBreakMinutes: Number(thresholdBreakMinutes),
        note: note || null,
      });
    }}>
      <div className="form-grid">
        <label>
          基本休憩（分）
          <input type="number" min={0} value={baseBreakMinutes} onChange={(event) => setBaseBreakMinutes(event.target.value)} />
        </label>
        <label>
          しきい値（分）
          <input type="number" min={1} value={thresholdWorkMinutes} onChange={(event) => setThresholdWorkMinutes(event.target.value)} />
        </label>
        <label>
          しきい値以上の休憩（分）
          <input type="number" min={0} value={thresholdBreakMinutes} onChange={(event) => setThresholdBreakMinutes(event.target.value)} />
        </label>
        <label>
          メモ
          <input value={note} onChange={(event) => setNote(event.target.value)} />
        </label>
      </div>
      <div className="button-row">
        <button type="submit">休憩ルールを保存</button>
      </div>
    </form>
  );
}

function EmployeeSettingForm({ settings, onSave }: { settings: EmployeeAttendanceSetting[]; onSave: (payload: {
  employeeId: number;
  standardClockInTime?: string | null;
  standardClockOutTime?: string | null;
  includeBeforeStart?: boolean;
  includeAfterEnd?: boolean;
}) => Promise<void> }) {
  const firstEmployeeId = settings[0]?.employeeId ?? 0;
  const [employeeId, setEmployeeId] = useState(String(firstEmployeeId));
  const selected = useMemo(() => settings.find((setting) => String(setting.employeeId) === employeeId), [employeeId, settings]);
  const [clockIn, setClockIn] = useState(selected?.standardClockInTime ?? "09:00");
  const [clockOut, setClockOut] = useState(selected?.standardClockOutTime ?? "18:00");
  const [includeBeforeStart, setIncludeBeforeStart] = useState(selected?.includeBeforeStart ?? false);
  const [includeAfterEnd, setIncludeAfterEnd] = useState(selected?.includeAfterEnd ?? false);

  function changeEmployee(nextEmployeeId: string) {
    const next = settings.find((setting) => String(setting.employeeId) === nextEmployeeId);
    setEmployeeId(nextEmployeeId);
    setClockIn(next?.standardClockInTime ?? "09:00");
    setClockOut(next?.standardClockOutTime ?? "18:00");
    setIncludeBeforeStart(next?.includeBeforeStart ?? false);
    setIncludeAfterEnd(next?.includeAfterEnd ?? false);
  }

  return (
    <form className="stack-form compact-form" onSubmit={(event) => {
      event.preventDefault();
      void onSave({
        employeeId: Number(employeeId),
        standardClockInTime: clockIn || null,
        standardClockOutTime: clockOut || null,
        includeBeforeStart,
        includeAfterEnd,
      });
    }}>
      <div className="form-grid">
        <label>
          職員
          <select value={employeeId} onChange={(event) => changeEmployee(event.target.value)}>
            {settings.map((setting) => (
              <option key={setting.employeeId} value={setting.employeeId}>
                {setting.employeeCode} {setting.employeeName}
              </option>
            ))}
          </select>
        </label>
        <label>
          基本出勤
          <input type="time" value={clockIn} onChange={(event) => setClockIn(event.target.value)} />
        </label>
        <label>
          基本退勤
          <input type="time" value={clockOut} onChange={(event) => setClockOut(event.target.value)} />
        </label>
        <label className="check-row">
          <input type="checkbox" checked={includeBeforeStart} onChange={(event) => setIncludeBeforeStart(event.target.checked)} />
          勤務前の打刻を含める
        </label>
        <label className="check-row">
          <input type="checkbox" checked={includeAfterEnd} onChange={(event) => setIncludeAfterEnd(event.target.checked)} />
          勤務後の打刻を含める
        </label>
      </div>
      <div className="button-row">
        <button type="submit" disabled={!employeeId}>個人別設定を保存</button>
      </div>
    </form>
  );
}

function ShiftScheduleForm({ employees, workTypes, targetMonth, onSave }: {
  employees: DashboardData["employees"];
  workTypes: DashboardData["systemMasters"]["workTypes"];
  targetMonth: string;
  onSave: (payload: {
    employeeId: number;
    targetDate: string;
    workTypeId?: number | null;
    scheduledClockInTime?: string | null;
    scheduledClockOutTime?: string | null;
    note?: string | null;
  }) => Promise<void>;
}) {
  const [employeeId, setEmployeeId] = useState(String(employees[0]?.id ?? ""));
  const [targetDate, setTargetDate] = useState(`${targetMonth}-01`);
  const [workTypeId, setWorkTypeId] = useState(String(workTypes[0]?.id ?? ""));
  const [clockIn, setClockIn] = useState("09:00");
  const [clockOut, setClockOut] = useState("18:00");
  const [note, setNote] = useState("");

  return (
    <form className="stack-form compact-form" onSubmit={(event) => {
      event.preventDefault();
      void onSave({
        employeeId: Number(employeeId),
        targetDate,
        workTypeId: workTypeId ? Number(workTypeId) : null,
        scheduledClockInTime: clockIn || null,
        scheduledClockOutTime: clockOut || null,
        note: note || null,
      });
    }}>
      <div className="form-grid">
        <label>
          職員
          <select value={employeeId} onChange={(event) => setEmployeeId(event.target.value)}>
            {employees.map((employee) => (
              <option key={employee.id} value={employee.id}>
                {employee.employeeCode} {employee.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          日付
          <input type="date" value={targetDate} onChange={(event) => setTargetDate(event.target.value)} />
        </label>
        <label>
          勤務区分
          <select value={workTypeId} onChange={(event) => setWorkTypeId(event.target.value)}>
            <option value="">未設定</option>
            {workTypes.map((workType) => (
              <option key={workType.id} value={workType.id}>{workType.name}</option>
            ))}
          </select>
        </label>
        <label>
          予定出勤
          <input type="time" value={clockIn} onChange={(event) => setClockIn(event.target.value)} />
        </label>
        <label>
          予定退勤
          <input type="time" value={clockOut} onChange={(event) => setClockOut(event.target.value)} />
        </label>
        <label>
          メモ
          <input value={note} onChange={(event) => setNote(event.target.value)} />
        </label>
      </div>
      <div className="button-row">
        <button type="submit" disabled={!employeeId || !targetDate}>シフトを保存</button>
      </div>
    </form>
  );
}

function mergeShiftRows(current: AttendanceShiftSchedule[], nextRows: AttendanceShiftSchedule[], employeeId: number) {
  const otherRows = current.filter((row) => row.employeeId !== employeeId);
  return [...otherRows, ...nextRows].sort((a, b) => `${a.employeeCode}-${a.targetDate}`.localeCompare(`${b.employeeCode}-${b.targetDate}`));
}
