import { useRef, useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { FieldError, ValidationSummary } from "@/components/form-field-errors";
import { HourlyBalanceForm } from "./hourly-balance-form";
import type { LeaveSectionProps } from "./leave-section-types";

type LeaveGrantAdjustPanelProps = {
  dashboard: LeaveSectionProps["data"]["dashboard"];
  form: LeaveSectionProps["form"];
  actions: LeaveSectionProps["actions"];
  leaveAdminResult: string;
  decisionResult: string;
};

export function LeaveGrantAdjustPanel({ dashboard, form, actions, leaveAdminResult, decisionResult }: LeaveGrantAdjustPanelProps) {
  const [grantErrors, setGrantErrors] = useState<Record<string, string[]>>({});
  const [adjustErrors, setAdjustErrors] = useState<Record<string, string[]>>({});
  const [confirmation, setConfirmation] = useState<"grant" | "adjust" | null>(null);
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);
  const employee = dashboard.employees.find((row) => row.id === Number(form.grantEmployeeId));
  async function confirmSave() {
    if (savingRef.current || confirmation === null) return;
    savingRef.current = true; setSaving(true);
    try {
      if (confirmation === "grant") await actions.onGrantPaidLeave(setGrantErrors);
      else await actions.onAdjustPaidLeave(setAdjustErrors);
    } finally {
      savingRef.current = false; setSaving(false); setConfirmation(null);
    }
  }
  return (
    <section id="leave-grant-adjust" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>有給付与・調整</h3>
        </div>
      </div>
      <fieldset disabled={saving || confirmation !== null} className="stack-form">
      <label>
        対象職員
        <select name="employeeId" required value={form.grantEmployeeId} aria-invalid={Boolean(grantErrors.employeeId || adjustErrors.employeeId)} aria-describedby={grantErrors.employeeId ? "leave-grant-error-employeeId" : adjustErrors.employeeId ? "leave-adjust-error-employeeId" : undefined} onChange={(event) => { setGrantErrors({}); setAdjustErrors({}); actions.onGrantEmployeeIdChange(event.target.value); }}>
          {dashboard.employees.map((employee) => (
            <option key={employee.id} value={employee.id}>
              {employee.employeeCode} / {employee.name}
            </option>
          ))}
        </select>
      </label>
      <FieldError idPrefix="leave-grant" errors={grantErrors} field="employeeId" /><FieldError idPrefix="leave-adjust" errors={adjustErrors} field="employeeId" />
      <form className="stack-form" onChangeCapture={() => setGrantErrors({})} onSubmit={(event) => { event.preventDefault(); setConfirmation("grant"); }}>
        <ValidationSummary errors={grantErrors} />
        <label>付与日数<input name="days" type="number" required min="0.01" max="99.99" step="0.01" aria-invalid={Boolean(grantErrors.days)} aria-describedby={grantErrors.days ? "leave-grant-error-days" : undefined} value={form.grantDays} onChange={(event) => actions.onGrantDaysChange(event.target.value)} /></label><FieldError idPrefix="leave-grant" errors={grantErrors} field="days" />
        <label>付与日<input name="grantedOn" type="date" required aria-invalid={Boolean(grantErrors.grantedOn)} aria-describedby={grantErrors.grantedOn ? "leave-grant-error-grantedOn" : undefined} value={form.grantDate} onChange={(event) => actions.onGrantDateChange(event.target.value)} /></label><FieldError idPrefix="leave-grant" errors={grantErrors} field="grantedOn" />
        <label>失効日<input name="expiresOn" type="date" min={form.grantDate || undefined} aria-invalid={Boolean(grantErrors.expiresOn)} aria-describedby={grantErrors.expiresOn ? "leave-grant-error-expiresOn" : undefined} value={form.grantExpiresOn} onChange={(event) => actions.onGrantExpiresOnChange(event.target.value)} /></label><FieldError idPrefix="leave-grant" errors={grantErrors} field="expiresOn" />
        <label>付与メモ<input name="note" maxLength={255} aria-invalid={Boolean(grantErrors.note)} aria-describedby={grantErrors.note ? "leave-grant-error-note" : undefined} value={form.grantNote} onChange={(event) => actions.onGrantNoteChange(event.target.value)} /></label><FieldError idPrefix="leave-grant" errors={grantErrors} field="note" />
        <button type="submit" disabled={!employee}>付与を登録</button>
      </form>

      <hr className="soft-divider" />

      <form className="stack-form" onChangeCapture={() => setAdjustErrors({})} onSubmit={(event) => { event.preventDefault(); setConfirmation("adjust"); }}>
      <ValidationSummary errors={adjustErrors} />
      <p>残数を減らす調整は、失効日が早い付与分から差し引きます。期限が未確認の付与日は推定しません。</p>
      <label>
        調整種別
        <select name="adjustmentType" aria-invalid={Boolean(adjustErrors.adjustmentType)} aria-describedby={adjustErrors.adjustmentType ? "leave-adjust-error-adjustmentType" : undefined} value={form.adjustType} onChange={(event) => actions.onAdjustTypeChange(event.target.value as "ADJUST_PLUS" | "ADJUST_MINUS")}>
          <option value="ADJUST_PLUS">残数を増やす</option>
          <option value="ADJUST_MINUS">残数を減らす</option>
        </select>
      </label>
      <FieldError idPrefix="leave-adjust" errors={adjustErrors} field="adjustmentType" />
      <label>調整日数<input name="days" type="number" required min="0.01" max="99.99" step="0.01" aria-invalid={Boolean(adjustErrors.days)} aria-describedby={adjustErrors.days ? "leave-adjust-error-days" : undefined} value={form.adjustDays} onChange={(event) => actions.onAdjustDaysChange(event.target.value)} /></label><FieldError idPrefix="leave-adjust" errors={adjustErrors} field="days" />
      <label>反映日<input name="effectiveOn" type="date" required aria-invalid={Boolean(adjustErrors.effectiveOn)} aria-describedby={adjustErrors.effectiveOn ? "leave-adjust-error-effectiveOn" : undefined} value={form.adjustDate} onChange={(event) => actions.onAdjustDateChange(event.target.value)} /></label><FieldError idPrefix="leave-adjust" errors={adjustErrors} field="effectiveOn" />
      <label>調整メモ<input name="note" maxLength={255} aria-invalid={Boolean(adjustErrors.note)} aria-describedby={adjustErrors.note ? "leave-adjust-error-note" : undefined} value={form.adjustNote} onChange={(event) => actions.onAdjustNoteChange(event.target.value)} /></label><FieldError idPrefix="leave-adjust" errors={adjustErrors} field="note" />
      <button type="submit" className="secondary" disabled={!employee}>調整を登録</button>
      </form>
      </fieldset>

      <HourlyBalanceForm key={form.grantEmployeeId} employeeId={Number(form.grantEmployeeId)} current={dashboard.paidLeaveReport.find((row) => row.employeeId === Number(form.grantEmployeeId))?.hourlyBalance} onSaved={actions.onHourlyBalanceSaved} />
      <label>
        判定コメント
        <textarea rows={4} value={form.decisionComment} onChange={(event) => actions.onDecisionCommentChange(event.target.value)} />
      </label>
      {leaveAdminResult ? <p className="feedback">{leaveAdminResult}</p> : null}
      {decisionResult ? <p className="feedback">{decisionResult}</p> : null}
      <ConfirmDialog open={confirmation !== null} busy={saving} title={confirmation === "grant" ? "有給付与の確認" : "有給調整の確認"}
        message={`${employee?.employeeCode ?? ""} / ${employee?.name ?? ""}：${confirmation === "grant"
          ? `${form.grantDate} に ${form.grantDays}日を付与します。失効日：${form.grantExpiresOn || "未設定"}。`
          : `${form.adjustDate} に残数を ${form.adjustDays}日${form.adjustType === "ADJUST_PLUS" ? "増やします" : "減らします"}。`}登録後の内容は操作ログで確認できます。`}
        confirmLabel="確認して登録" onCancel={() => setConfirmation(null)} onConfirm={() => void confirmSave()} />
    </section>
  );
}
