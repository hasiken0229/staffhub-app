import { useState } from "react";
import { ApiRequestError, fetchJson, toDateValue } from "@/lib/api/core";
import { FieldError, ValidationSummary } from "@/components/form-field-errors";
import { ConfirmDialog } from "@/components/confirm-dialog";
import type { HourlyPaidLeaveBalance } from "@/types";

export function HourlyBalanceForm({ employeeId, current, onSaved }: { employeeId: number; current?: HourlyPaidLeaveBalance | null; onSaved: () => Promise<void> }) {
  const [effectiveOn, setEffectiveOn] = useState(toDateValue(new Date()));
  const [expiresOn, setExpiresOn] = useState(current?.expiresOn ?? "");
  const [hours, setHours] = useState("");
  const [totalHours, setTotalHours] = useState("");
  const [note, setNote] = useState("");
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [confirming, setConfirming] = useState(false);
  async function save() {
    if (saving) return;
    const remainingMinutes = Number(hours) * 60;
    const totalMinutes = Number(totalHours) * 60;
    if (!employeeId || hours === "" || totalHours === "" || !Number.isInteger(remainingMinutes) || !Number.isInteger(totalMinutes) || remainingMinutes < 0 || totalMinutes < remainingMinutes || !effectiveOn || !expiresOn || expiresOn <= effectiveOn) {
      setMessage("日付と残時間を確認してください。総残時間は今年度の残時間以上で入力してください。"); setConfirming(false); return;
    }
    setSaving(true); setMessage(""); setErrors({});
    try {
      await fetchJson("/api/admin/leave/hourly-balances", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ employeeId, effectiveOn, expiresOn, remainingMinutes, totalMinutes, note }) });
      setMessage("時間有給残数を保存しました。");
      await onSaved().catch(() => setMessage("保存済みです。一覧の最新情報を再読み込みしてください。"));
    } catch (error) { setMessage(error instanceof Error ? error.message : "保存に失敗しました。"); setErrors(error instanceof ApiRequestError ? error.fieldErrors : {}); }
    finally { setSaving(false); setConfirming(false); }
  }
  return <><form onChangeCapture={() => setErrors({})} onSubmit={event => { event.preventDefault(); setConfirming(true); }}><fieldset disabled={saving || confirming} className="stack-form">
    <legend>時間有給の残数設定</legend>
    <p>基準日終了時点の残数を設定します。翌日以降の申請を差し引きます。時間有給は日数と別に管理します。</p>
    {current ? <p>現在：今年度残 {current.remainingMinutes / 60}時間 ／ 承認待ち {current.pendingMinutes / 60}時間 ／ 総残時間 {current.totalRemainingMinutes === null ? "未設定" : `${current.totalRemainingMinutes / 60}時間`}</p> : null}
    <ValidationSummary errors={errors} />
    <label>残数基準日<input name="effectiveOn" type="date" required aria-invalid={Boolean(errors.effectiveOn)} aria-describedby={errors.effectiveOn ? "leave-hourly-error-effectiveOn" : undefined} value={effectiveOn} onChange={e => setEffectiveOn(e.target.value)} /></label><FieldError idPrefix="leave-hourly" errors={errors} field="effectiveOn" />
    <label>今年度の利用期限<input name="expiresOn" type="date" required min={effectiveOn} aria-invalid={Boolean(errors.expiresOn)} aria-describedby={errors.expiresOn ? "leave-hourly-error-expiresOn" : undefined} value={expiresOn} onChange={e => setExpiresOn(e.target.value)} /></label><FieldError idPrefix="leave-hourly" errors={errors} field="expiresOn" />
    <label>今年度使える残時間<input name="remainingMinutes" type="number" required min="0" max="8760" step="0.5" aria-invalid={Boolean(errors.remainingMinutes)} aria-describedby={errors.remainingMinutes ? "leave-hourly-error-remainingMinutes" : undefined} value={hours} onChange={e => setHours(e.target.value)} /></label><FieldError idPrefix="leave-hourly" errors={errors} field="remainingMinutes" />
    <label>繰越を含む総残時間<input name="totalMinutes" type="number" required min={hours || "0"} max="8760" step="0.5" aria-invalid={Boolean(errors.totalMinutes)} aria-describedby={errors.totalMinutes ? "leave-hourly-error-totalMinutes" : undefined} value={totalHours} onChange={e => setTotalHours(e.target.value)} /></label><FieldError idPrefix="leave-hourly" errors={errors} field="totalMinutes" />
    <label>設定メモ<input name="note" maxLength={255} aria-invalid={Boolean(errors.note)} aria-describedby={errors.note ? "leave-hourly-error-note" : undefined} value={note} onChange={e => setNote(e.target.value)} /></label><FieldError idPrefix="leave-hourly" errors={errors} field="note" />
    <button type="submit" disabled={!employeeId}>{saving ? "保存中…" : "時間有給残数を設定"}</button>
    {message ? <p role="status">{message}</p> : null}
  </fieldset></form><ConfirmDialog open={confirming} busy={saving} title="時間有給残数の確認" message={`${effectiveOn} 終了時点の今年度残 ${hours}時間、総残 ${totalHours}時間、利用期限 ${expiresOn} を設定します。既存の同じ基準日の設定は更新されます。原本と照合した値であることを確認してください。`} confirmLabel="確認して設定" onCancel={() => setConfirming(false)} onConfirm={() => void save()} /></>;
}
