import type { LeaveSectionProps } from "./leave-section-types";

type LeaveGrantAdjustPanelProps = {
  dashboard: LeaveSectionProps["data"]["dashboard"];
  form: LeaveSectionProps["form"];
  actions: LeaveSectionProps["actions"];
  leaveAdminResult: string;
  decisionResult: string;
};

export function LeaveGrantAdjustPanel({ dashboard, form, actions, leaveAdminResult, decisionResult }: LeaveGrantAdjustPanelProps) {
  return (
    <section id="leave-grant-adjust" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>有給付与・調整</h3>
        </div>
      </div>
      <label>
        対象職員
        <select value={form.grantEmployeeId} onChange={(event) => actions.onGrantEmployeeIdChange(event.target.value)}>
          {dashboard.employees.map((employee) => (
            <option key={employee.id} value={employee.id}>
              {employee.employeeCode} / {employee.name}
            </option>
          ))}
        </select>
      </label>
      <label>付与日数<input value={form.grantDays} onChange={(event) => actions.onGrantDaysChange(event.target.value)} /></label>
      <label>付与日<input type="date" value={form.grantDate} onChange={(event) => actions.onGrantDateChange(event.target.value)} /></label>
      <label>失効日<input type="date" value={form.grantExpiresOn} onChange={(event) => actions.onGrantExpiresOnChange(event.target.value)} /></label>
      <label>付与メモ<input value={form.grantNote} onChange={(event) => actions.onGrantNoteChange(event.target.value)} /></label>
      <button type="button" onClick={() => void actions.onGrantPaidLeave()}>付与を登録</button>

      <hr className="soft-divider" />

      <label>
        調整種別
        <select value={form.adjustType} onChange={(event) => actions.onAdjustTypeChange(event.target.value as "ADJUST_PLUS" | "ADJUST_MINUS")}>
          <option value="ADJUST_PLUS">残数を増やす</option>
          <option value="ADJUST_MINUS">残数を減らす</option>
        </select>
      </label>
      <label>調整日数<input value={form.adjustDays} onChange={(event) => actions.onAdjustDaysChange(event.target.value)} /></label>
      <label>反映日<input type="date" value={form.adjustDate} onChange={(event) => actions.onAdjustDateChange(event.target.value)} /></label>
      <label>調整メモ<input value={form.adjustNote} onChange={(event) => actions.onAdjustNoteChange(event.target.value)} /></label>
      <button type="button" className="secondary" onClick={() => void actions.onAdjustPaidLeave()}>調整を登録</button>

      <label>
        判定コメント
        <textarea rows={4} value={form.decisionComment} onChange={(event) => actions.onDecisionCommentChange(event.target.value)} />
      </label>
      {leaveAdminResult ? <p className="feedback">{leaveAdminResult}</p> : null}
      {decisionResult ? <p className="feedback">{decisionResult}</p> : null}
    </section>
  );
}
