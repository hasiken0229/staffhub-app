import { useEffect, useRef, useState } from "react";
import { previewBreakOvertime } from "@/lib/api/attendance";
import { formatDateTime, formatWorkMinutes } from "@/lib/api/formatters";
import type { BreakOvertimeConfirmation, BreakOvertimeInputs, BreakOvertimePreview } from "@/types";

type Props = {
  dailyId: number;
  busy?: boolean;
  onApply: (id: number, inputs: BreakOvertimeConfirmation) => Promise<void>;
};

export function BreakOvertimeReview({ dailyId, busy, onApply }: Props) {
  const [initial, setInitial] = useState<BreakOvertimePreview | null>(null);
  const [planned, setPlanned] = useState<string | null>(null);
  const [selectedBasis, setBasis] = useState<"" | "RULE" | "ACTUAL" | null>(null);
  const [actualStart, setActualStart] = useState("");
  const [nextDay, setNextDay] = useState(false);
  const [enteredMinutes, setMinutes] = useState<string | null>(null);
  const [useScheduleBreak, setUseScheduleBreak] = useState(true);
  const [inputsEdited, setInputsEdited] = useState(false);
  const [calculation, setCalculation] = useState<{ result: BreakOvertimePreview; inputs: BreakOvertimeInputs } | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [attempt, setAttempt] = useState(0);
  const request = useRef<AbortController | null>(null);
  const applying = useRef(false);

  useEffect(() => {
    const controller = new AbortController();
    request.current = controller;
    setLoading(true);
    setError("");
    previewBreakOvertime(dailyId, {}, controller.signal).then(value => {
      if (!controller.signal.aborted) setInitial(value);
    }).catch((e: unknown) => {
      if (!controller.signal.aborted) setError(e instanceof Error ? e.message : "勤務予定を読み込めませんでした。");
    }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => request.current?.abort();
  }, [dailyId, attempt]);

  function invalidate() {
    request.current?.abort();
    setInputsEdited(true);
    setCalculation(null);
    setLoading(false);
    setConfirmed(false);
    setError("");
  }

  const effectivePlan = planned ?? initial?.plannedStart?.slice(0, 5) ?? "";
  const basis = selectedBasis ?? initial?.defaultStartBasis ?? "";
  const minutes = enteredMinutes ?? initial?.defaultBreakMinutes?.toString() ?? "";
  const automaticBasis = initial?.defaultBreakBasis;
  const scheduledBreak = !!automaticBasis && useScheduleBreak;
  const thresholdRule = scheduledBreak && automaticBasis === "REGULAR_PUNCH";
  const rawPairSufficient = thresholdRule && initial?.branch === "RAW";
  const valid = !!basis && (basis === "RULE" ? !!effectivePlan || rawPairSufficient : !!actualStart)
    && (scheduledBreak || (/^\d{1,4}$/.test(minutes) && Number(minutes) < 1440));

  async function calculate() {
    if (!valid) return;
    invalidate();
    const inputs: BreakOvertimeInputs = {
      ...(planned ? { plannedStart: planned } : {}),
      startBasis: basis || undefined,
      ...(scheduledBreak ? { breakBasis: automaticBasis } : { actualBreakMinutes: Number(minutes) }),
      ...(basis === "ACTUAL" ? { actualStart, actualStartNextDay: nextDay } : {}),
    };
    const controller = new AbortController();
    request.current = controller;
    setLoading(true);
    try {
      const result = await previewBreakOvertime(dailyId, inputs, controller.signal);
      if (!controller.signal.aborted) setCalculation({ result, inputs });
    } catch (e) {
      if (!controller.signal.aborted) setError(e instanceof Error ? e.message : "試算できませんでした。");
    } finally {
      if (!controller.signal.aborted) setLoading(false);
    }
  }

  async function apply() {
    const inputs = calculation?.inputs;
    const token = calculation?.result.token;
    if (!confirmed || !calculation?.result.canApply || !token || !inputs?.startBasis || applying.current) return;
    applying.current = true;
    setConfirmed(false);
    try {
      await onApply(dailyId, { ...inputs, startBasis: inputs.startBasis, confirmed: true, token });
    } catch (e) {
      setError(e instanceof Error ? e.message : "計上できませんでした。日次の内容を再取得してください。");
    } finally {
      applying.current = false;
      setCalculation(null);
    }
  }

  const preview = calculation?.result ?? (inputsEdited ? null : initial);
  return <section aria-label="始業・休憩と時間外勤務の確認" className="stack-form">
    <h4>始業・休憩と時間外勤務の確認</h4>
    {!initial && loading && <p role="status">勤務予定を読み込んでいます。</p>}
    {error && <p className="feedback" role="alert">{error} {!initial && <button type="button" className="secondary" onClick={() => setAttempt(a => a + 1)}>再取得</button>}</p>}
    {initial && !initial.eligible && <p>{initial.message || "この日次は再計算の対象外です。"}</p>}
    {initial?.eligible && <fieldset className="stack-form" disabled={busy}>
      <legend>当日の勤務実態</legend>
      <div className="form-grid">
        <label>当日の予定始業<input type="time" value={effectivePlan} onChange={e => { invalidate(); setPlanned(e.target.value); }} />
          <small>{planned ? "入力した当日の予定" : initial.plannedStartSource || "予定が不明な日は、実際の始業を指定できます。"}</small></label>
        <label>採用する始業<select value={basis} onChange={e => { invalidate(); setBasis(e.target.value as typeof basis); }}>
          <option value="">選択してください</option>
          <option value="RULE">予定・原打刻の計算ルールを使う</option>
          <option value="ACTUAL">実際の始業を指定する</option>
        </select></label>
        {basis === "ACTUAL" && <label>実際の始業<input type="time" value={actualStart} onChange={e => { invalidate(); setActualStart(e.target.value); }} /></label>}
        {basis === "ACTUAL" && <label className="checkbox-row"><input type="checkbox" checked={nextDay} onChange={e => { invalidate(); setNextDay(e.target.checked); }} />始業は翌日</label>}
        {automaticBasis && <label>休憩の計上方法<select value={scheduledBreak ? automaticBasis : "ACTUAL"} onChange={e => { invalidate(); setUseScheduleBreak(e.target.value === automaticBasis); }}>
          <option value={automaticBasis}>{automaticBasis === "REGULAR_PUNCH" ? "原打刻の長さと予定始業から休憩を計算" : "昼休憩と予定終業後の休憩枠で計算"}</option>
          <option value="ACTUAL">実際に取得した休憩を入力</option>
        </select></label>}
        {thresholdRule ? <p>{basis === "ACTUAL" ? "入力した始業を採用します。" : "原打刻が9時間1分未満の日は予定始業（遅れて出勤した日は原打刻）、9時間1分以上の日は原打刻の始業を採用します。"}9時間1分未満の日の休憩は、昼休憩と、予定始業の8時間45分後から退勤までの最大15分です。9時間1分以上の日は休憩を合計60分とします。現在の記録は{initial.recordedBreakMinutes}分です。</p>
          : scheduledBreak ? <p>予定終業 {initial.scheduledEnd}（{initial.scheduledEndSource}）。昼休憩の12:00～12:45と予定終業後15分間のうち、勤務時間に含まれる分を計上します。現在の記録は{initial.recordedBreakMinutes}分です。</p>
          : <label>実際に取得した休憩（分）<input type="number" min="0" max="1439" step="1" inputMode="numeric" value={minutes}
            onChange={e => { invalidate(); setMinutes(e.target.value); }} /><small>現在の記録は{initial.recordedBreakMinutes}分です。</small></label>}
      </div>
      {basis === "RULE" && initial.defaultStartBasis === "RULE" && !automaticBasis && <p className="compact-empty">原打刻の出勤から退勤までが9時間1分以上なら、原打刻から休憩を引き、実働8時間を超えた分を時間外に計上します。</p>}
      <div className="button-row">
        {!scheduledBreak && <button type="button" className="secondary" onClick={() => { invalidate(); setMinutes("60"); }}>45分＋15分を取得（60分を入力）</button>}
        <button type="button" onClick={() => void calculate()} disabled={!valid || loading}>{loading ? "試算中…" : "確認内容で試算"}</button>
      </div>
      {preview?.workMinutes != null && <div aria-live="polite">
        <strong>{calculation ? "確認内容での試算" : initial.defaultStartBasis === "RULE" ? "予定・原打刻ルールでの試算" : "記録からの試算"}</strong>
        <p>{calculation?.inputs.startBasis === "ACTUAL" ? "確認した始業" : preview.branch === "RAW" ? "原打刻の始業" : "勤務予定を考慮した始業"} {formatDateTime(preview.adoptedStart)}　原打刻の退勤 {formatDateTime(preview.rawEnd)}</p>
        <p>休憩 {preview.breakMinutes}分　実働 {formatWorkMinutes(preview.workMinutes)}　時間外 {formatWorkMinutes(preview.overtimeMinutes)}</p>
        {thresholdRule && preview.additionalBreakStart && preview.additionalBreakMinutes != null && <p>追加休憩の開始 {preview.additionalBreakStart}（予定始業の8時間45分後）　計上 {preview.additionalBreakMinutes}分</p>}
        {scheduledBreak && !thresholdRule && preview.additionalBreakMinutes != null && <p>うち予定終業後の追加休憩 {preview.additionalBreakMinutes}分</p>}
      </div>}
      {preview?.message && <p className="compact-empty">{preview.message}</p>}
      {calculation?.result.canApply && <><label className="checkbox-row"><input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />
        {thresholdRule ? "採用する始業・退勤と、今回のルールで計算した休憩分を確認しました" : scheduledBreak ? "採用する始業・退勤と、予定終業から計算した休憩分を確認しました" : "試算の始業・退勤と休憩が実際の勤務と一致することを確認しました"}</label>
        <div className="button-row"><button type="button" disabled={!confirmed} onClick={() => void apply()}>この内容で勤怠に計上</button></div></>}
    </fieldset>}
  </section>;
}
