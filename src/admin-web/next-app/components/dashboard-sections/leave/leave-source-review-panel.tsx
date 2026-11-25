"use client";

import { useState, type FormEvent } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { FieldError, ValidationSummary, type FieldErrors } from "@/components/form-field-errors";
import { ApiRequestError } from "@/lib/api/core";
import { confirmAttendanceLeaveBaseline, confirmPaidLeaveGrantReview, loadAdminLeaveBalance, loadAttendanceLeaveBaseline, previewPaidLeaveGrantReview } from "@/lib/api/leave";
import { currentDateValue } from "@/lib/date-defaults";
import type { AdminLeaveBalance, AttendanceLeaveBaseline, AttendanceLeaveValues, GrantReviewPayload, GrantReviewPreview, PaidLeaveGrantLot } from "@/types/leave";

const fields: Array<{ key: keyof AttendanceLeaveValues; label: string; max: number; step: number }> = [
  { key: "paid_leave_unit", label: "有給（日）", max: 1, step: .5 },
  { key: "hour_paid_leave_minutes", label: "時間有給（分）", max: 1440, step: 1 },
  { key: "child_care_leave_minutes", label: "子の看護等（分）", max: 1440, step: 1 },
  { key: "nursing_care_leave_minutes", label: "介護（分）", max: 1440, step: 1 },
  { key: "absence_flag", label: "欠勤（0/1）", max: 1, step: 1 },
  { key: "special_leave_flag", label: "特別休暇（0/1）", max: 1, step: 1 },
];

export function LeaveSourceReviewPanel({ employees, onSaved }: {
  employees: Array<{ id: number; employeeCode: string; name: string }>;
  onSaved: () => Promise<void>;
}) {
  const [employeeId, setEmployeeId] = useState(String(employees[0]?.id ?? ""));
  const [balance, setBalance] = useState<AdminLeaveBalance | null>(null);
  const [selected, setSelected] = useState<PaidLeaveGrantLot | null>(null);
  const [lots, setLots] = useState<GrantReviewPayload["lots"]>([]);
  const [evidence, setEvidence] = useState("");
  const [preview, setPreview] = useState<GrantReviewPreview | null>(null);
  const [date, setDate] = useState(currentDateValue);
  const [daily, setDaily] = useState<AttendanceLeaveBaseline | null>(null);
  const [baseline, setBaseline] = useState<AttendanceLeaveValues | null>(null);
  const [baselineEvidence, setBaselineEvidence] = useState("");
  const [confirming, setConfirming] = useState<"grant" | "baseline" | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [dailyErrors, setDailyErrors] = useState<FieldErrors>({});

  function fail(error: unknown, source: "grant" | "baseline" = "grant") {
    (source === "baseline" ? setDailyErrors : setErrors)(error instanceof ApiRequestError ? error.fieldErrors : {});
    setMessage(error instanceof Error ? error.message : "原本確認情報を処理できませんでした。");
  }
  async function loadBalance() {
    setBusy(true); setMessage(""); setErrors({}); setSelected(null); setPreview(null);
    try { setBalance(await loadAdminLeaveBalance(Number(employeeId))); } catch (error) { fail(error); }
    finally { setBusy(false); }
  }
  function selectLot(lot: PaidLeaveGrantLot) {
    setSelected(lot); setLots([{ days: lot.grantedDays, expiresOn: lot.expiresOn ?? "", originalGrantedOn: lot.originalGrantedOn ?? undefined }]);
    setEvidence(lot.evidenceReference ?? ""); setPreview(null); setErrors({}); setMessage("");
  }
  async function previewGrant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selected) return;
    setBusy(true); setErrors({}); setPreview(null); setMessage("");
    try { setPreview(await previewPaidLeaveGrantReview(selected.id, { lots, evidenceReference: evidence })); }
    catch (error) { fail(error); } finally { setBusy(false); }
  }
  async function loadDaily(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setDaily(null); setBaseline(null); setDailyErrors({}); setMessage("");
    try {
      const result = await loadAttendanceLeaveBaseline(Number(employeeId), date);
      setDaily(result); setBaseline(result.baseline); setBaselineEvidence("");
    } catch (error) { fail(error, "baseline"); } finally { setBusy(false); }
  }
  async function saveConfirmed() {
    const action = confirming; setConfirming(null); setBusy(true); setMessage(""); setErrors({}); setDailyErrors({});
    try {
      if (action === "grant" && selected && preview) {
        const updated = await confirmPaidLeaveGrantReview(selected.id, { lots, evidenceReference: evidence, fingerprint: preview.fingerprint });
        setBalance(updated); setSelected(null); setPreview(null);
      } else if (action === "baseline" && daily && baseline) {
        const updated = await confirmAttendanceLeaveBaseline({ employeeId: daily.employeeId, targetDate: daily.targetDate,
          baseline, evidenceReference: baselineEvidence, fingerprint: daily.fingerprint });
        setDaily(updated); setBaseline(updated.baseline);
      } else { return; }
      setMessage("原本確認情報を保存しました。");
      await onSaved().catch(() => setMessage("保存は完了しました。一覧の更新に失敗したため最新情報を再読込してください。再保存は不要です。"));
    } catch (error) { fail(error, action ?? "grant"); } finally { setBusy(false); }
  }

  return <section className="panel action-panel">
    <h3>有給期限・日次休暇の原本確認</h3>
    <p>確認できた原本だけを登録します。不明な日付や移行内訳は推定しません。保存前に変更結果を確認できます。</p>
    <label>確認する職員<select value={employeeId} disabled={busy} onChange={(e) => {
      setEmployeeId(e.target.value); setBalance(null); setSelected(null); setPreview(null); setDaily(null); setBaseline(null); setErrors({}); setDailyErrors({}); setMessage("");
    }}>{employees.map((e) => <option key={e.id} value={e.id}>{e.employeeCode} / {e.name}</option>)}</select></label>
    <button type="button" disabled={busy || !employeeId} onClick={() => void loadBalance()}>付与内訳を確認</button>
    {balance ? <>
      <p>{balance.asOf} 時点の残数：{balance.currentBalance}日</p>
      <div className="table-wrap"><table><thead><tr><th>基準日</th><th>付与日数</th><th>残日数</th><th>失効日</th><th>確認状態</th><th>操作</th></tr></thead>
        <tbody>{balance.grants.map((g) => <tr key={g.id}><td>{g.grantedOn}</td><td>{g.grantedDays}日{g.adjustedDays ? <small>（減算調整 {g.adjustedDays}日）</small> : null}</td><td>{g.remainingDays}日</td><td>{g.expiresOn ?? "未確認"}</td>
          <td>{g.expiryNeedsReview ? "原本確認が必要" : g.expiryReviewedAt ? "原本確認済み" : "登録済み"}</td>
          <td><button type="button" disabled={busy} onClick={() => selectLot(g)}>期限・内訳を確認</button></td></tr>)}</tbody></table></div>
    </> : null}
    {selected ? <form className="stack-form" onSubmit={(e) => void previewGrant(e)}>
      <h4>基準日 {selected.grantedOn}・{selected.grantedDays}日の期限確認</h4>
      <p>内訳の合計は元の付与日数に合わせてください。移行基準日は保持し、原本の付与日は別に記録します。</p>
      <ValidationSummary errors={errors} />
      {lots.map((lot, index) => <div className="form-grid" key={index}>
        <label>内訳{index + 1} 日数<input name={`lots.${index}.days`} type="number" min="0.01" max="99.99" step="0.01" required disabled={busy} value={lot.days}
          aria-invalid={Boolean(errors[`lots.${index}.days`])} aria-describedby={errors[`lots.${index}.days`] ? `leave-grant-review-error-lots.${index}.days` : undefined} onChange={(e) => { setPreview(null); setLots(lots.map((v, i) => i === index ? { ...v, days: Number(e.target.value) } : v)); }} /><FieldError idPrefix="leave-grant-review" errors={errors} field={`lots.${index}.days`} /></label>
        <label>失効日<input name={`lots.${index}.expiresOn`} type="date" min={selected.grantedOn} required disabled={busy} value={lot.expiresOn}
          aria-invalid={Boolean(errors[`lots.${index}.expiresOn`])} aria-describedby={errors[`lots.${index}.expiresOn`] ? `leave-grant-review-error-lots.${index}.expiresOn` : undefined} onChange={(e) => { setPreview(null); setLots(lots.map((v, i) => i === index ? { ...v, expiresOn: e.target.value } : v)); }} /><FieldError idPrefix="leave-grant-review" errors={errors} field={`lots.${index}.expiresOn`} /></label>
        <label>原本の付与日（分かる場合）<input name={`lots.${index}.originalGrantedOn`} type="date" max={selected.grantedOn} disabled={busy} value={lot.originalGrantedOn ?? ""}
          aria-invalid={Boolean(errors[`lots.${index}.originalGrantedOn`])} aria-describedby={errors[`lots.${index}.originalGrantedOn`] ? `leave-grant-review-error-lots.${index}.originalGrantedOn` : undefined} onChange={(e) => { setPreview(null); setLots(lots.map((v, i) => i === index ? { ...v, originalGrantedOn: e.target.value || undefined } : v)); }} /><FieldError idPrefix="leave-grant-review" errors={errors} field={`lots.${index}.originalGrantedOn`} /></label>
        {lots.length > 1 ? <button type="button" disabled={busy} onClick={() => { setLots(lots.filter((_, i) => i !== index)); setPreview(null); }}>この内訳を除く</button> : null}
      </div>)}
      <button type="button" disabled={busy || lots.length >= 20} onClick={() => { setLots([...lots, { days: 0, expiresOn: "" }]); setPreview(null); }}>内訳を追加</button>
      <p>内訳合計：{Number(lots.reduce((sum, lot) => sum + lot.days, 0).toFixed(2))}日 / 元の付与：{selected.grantedDays}日</p>
      <label>確認した原本・確認内容<textarea name="evidenceReference" required maxLength={1000} disabled={busy} value={evidence} aria-invalid={Boolean(errors.evidenceReference)} aria-describedby={errors.evidenceReference ? "leave-grant-review-error-evidenceReference" : undefined}
        onChange={(e) => { setEvidence(e.target.value); setPreview(null); }} /><FieldError idPrefix="leave-grant-review" errors={errors} field="evidenceReference" /></label>
      <button type="submit" disabled={busy}>保存前の残数を確認</button>
      {preview ? <div className="import-preview"><strong>現在の残数 {preview.beforeBalance}日 → 保存後 {preview.afterBalance}日</strong>
        <p>過去の失効日を確認した場合は、期限に基づいて現在の残数も変わります。元の付与記録は履歴として保持します。</p>
        <button type="button" disabled={busy} onClick={() => setConfirming("grant")}>原本確認済みの内訳を保存</button></div> : null}
    </form> : null}
    <hr className="soft-divider" />
    <form className="stack-form" onSubmit={(e) => void loadDaily(e)}>
      <h4>日次休暇の原本確認</h4><p>既存の承認済み申請と移行・手修正分の区別が必要な場合に使用します。</p>
      <ValidationSummary errors={dailyErrors} />
      <label>対象日<input name="targetDate" type="date" required disabled={busy} value={date} aria-invalid={Boolean(dailyErrors.targetDate)} aria-describedby={dailyErrors.targetDate ? "leave-baseline-error-targetDate" : undefined} onChange={(e) => { setDate(e.target.value); setDaily(null); setBaseline(null); setDailyErrors({}); }} /><FieldError idPrefix="leave-baseline" errors={dailyErrors} field="targetDate" /></label>
      <button type="submit" disabled={busy || !employeeId}>日次の現在値を読込</button>
    </form>
    {daily ? <form className="stack-form" onSubmit={(event) => { event.preventDefault(); setConfirming("baseline"); }}>
      <p>{daily.targetDate} / 承認済み申請ID：{daily.requestIds.join(", ") || "なし"}。原本で確認した「申請分を除く」移行・手修正の量を入力してください。</p>
      <ValidationSummary errors={dailyErrors} />
      {daily.requiresDuplicateResolution ? <p role="alert">既存の承認済み有給が重複しています。内訳を確認・保存してから重複申請を取消してください。解消までは日次の現在値を維持します。</p> : null}
      {!baseline ? <button type="button" disabled={busy} onClick={() => setBaseline({ absence_flag: 0, special_leave_flag: 0, paid_leave_unit: null,
        hour_paid_leave_minutes: 0, child_care_leave_minutes: 0, nursing_care_leave_minutes: 0 })}>原本から内訳を入力する</button> : null}
      <div className="table-wrap"><table><thead><tr><th>項目</th><th>日次の現在値</th><th>承認済み申請分</th><th>移行・手修正分（原本確認）</th><th>保存後の日次</th></tr></thead>
        <tbody>{fields.map((f) => <tr key={f.key}><th>{f.label}</th><td>{daily.current[f.key] ?? 0}</td><td>{daily.applied[f.key] ?? 0}</td><td>{baseline ? <>
          <input aria-label={`${f.label}の移行・手修正分`} name={`baseline.${f.key}`} type="number" required min="0" max={f.max} step={f.step} disabled={busy}
            value={baseline[f.key] ?? 0} aria-invalid={Boolean(dailyErrors[`baseline.${f.key}`])} aria-describedby={dailyErrors[`baseline.${f.key}`] ? `leave-baseline-error-baseline.${f.key}` : undefined} onChange={(e) => setBaseline({ ...baseline, [f.key]: Number(e.target.value) })} />
          <FieldError idPrefix="leave-baseline" errors={dailyErrors} field={`baseline.${f.key}`} /></> : "未確認"}</td>
          <td>{daily.requiresDuplicateResolution ? daily.current[f.key] ?? 0 : baseline ? (f.key === "absence_flag" || f.key === "special_leave_flag"
            ? Math.min(1, (baseline[f.key] ?? 0) + (daily.applied[f.key] ?? 0))
            : (baseline[f.key] ?? 0) + (daily.applied[f.key] ?? 0)) : "未確認"}</td></tr>)}</tbody></table></div>
      <label>確認した原本・確認内容<textarea name="evidenceReference" required maxLength={1000} disabled={busy} value={baselineEvidence} aria-invalid={Boolean(dailyErrors.evidenceReference)} aria-describedby={dailyErrors.evidenceReference ? "leave-baseline-error-evidenceReference" : undefined}
        onChange={(e) => setBaselineEvidence(e.target.value)} /><FieldError idPrefix="leave-baseline" errors={dailyErrors} field="evidenceReference" /></label>
      <button type="submit" disabled={busy || !baseline}>原本確認済みの内訳を保存</button>
    </form> : null}
    {message ? <p role="status" className="feedback">{message}</p> : null}
    <ConfirmDialog open={confirming !== null} title="原本確認済みの内訳を保存しますか？"
      message={confirming === "grant" ? `現在 ${preview?.beforeBalance}日 → 保存後 ${preview?.afterBalance}日。確認原本：${evidence}`
        : `${daily?.targetDate} の内訳を保存します。${daily?.requiresDuplicateResolution ? "重複解消までは日次の現在値を維持します。" : "日次休暇を表の保存後の値へ更新します。"}確認原本：${baselineEvidence}`}
      confirmLabel="確認済みの内訳を保存" onCancel={() => setConfirming(null)} onConfirm={() => void saveConfirmed()} />
  </section>;
}
