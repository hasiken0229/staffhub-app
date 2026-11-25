import { useEffect, useState } from "react";
import { fetchJson } from "@/lib/api/core";

type Balance = {
  employeeId: number; employeeCode: string; employeeName: string; category: "CHILD" | "NURSING";
  configured: boolean; eligibleCount: number | null; entitlementDays: number | null; dayMinutes: number | null;
  effectiveOn: string | null; openingMinutes: number | null; note: string | null;
  recordedDays: number; recordedMinutes: number; remainingMinutes: number | null; futureReservedMinutes: number | null; availableMinutes: number | null;
};
const hours = (minutes: number) => `${Math.floor(Math.abs(minutes) / 60)}:${String(Math.abs(minutes) % 60).padStart(2, "0")}`;
function balanceText(minutes: number | null, day: number | null) {
  if (minutes === null || !day) return "未設定";
  return `${minutes < 0 ? "超過 " : ""}${Math.floor(Math.abs(minutes) / day)}日 ${hours(Math.abs(minutes) % day)}`;
}

export function CareLeaveBalancesPanel() {
  const today = new Date().toLocaleDateString("sv-SE", { timeZone: "Asia/Tokyo" });
  const [asOf, setAsOf] = useState(today);
  const year = Number(asOf.slice(0, 4)) - (Number(asOf.slice(5, 7)) < 4 ? 1 : 0);
  const [rows, setRows] = useState<Balance[]>([]);
  const [message, setMessage] = useState("");
  const [selected, setSelected] = useState<Balance | null>(null);
  const [busy, setBusy] = useState(false);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    let active = true;
    setRows([]); setSelected(null); setMessage("");
    if (!asOf) return;
    fetchJson<Balance[]>(`/api/admin/leave/care-balances?fiscalYear=${year}&asOf=${asOf}`)
      .then((data) => { if (active) setRows(data); })
      .catch((e: Error) => { if (active) setMessage(e.message); });
    return () => { active = false; };
  }, [year, asOf, revision]);
  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selected) return;
    const form = new FormData(event.currentTarget);
    const dayMinutes = Number(form.get("dayMinutes"));
    const payload = { employeeId: selected.employeeId, category: selected.category, fiscalYear: year,
      eligibleCount: Number(form.get("eligibleCount")), dayMinutes, effectiveOn: form.get("effectiveOn"),
      openingMinutes: Number(form.get("days")) * dayMinutes + Number(form.get("minutes")), note: form.get("note") };
    setBusy(true); setMessage("");
    try {
      await fetchJson("/api/admin/leave/care-balances", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
      setSelected(null); setRevision((n) => n + 1);
    } catch (e) { setMessage(e instanceof Error ? e.message : "保存に失敗しました"); }
    finally { setBusy(false); }
  }
  return <section className="panel">
    <h3>子の看護等休暇・介護休暇の残数</h3>
    <p>年度：4月1日～翌3月31日。対象1人は5日、2人以上は10日。子は小学3年生まで。翌年度へは繰り越しません。</p>
    <p>基準日の終了時点の残数を原本で確認して登録します。基準日後の勤怠に記録された取得分を控除します。取得は実働・残業とは別集計です。職員の対象人数・所定時間・移行残数が未確認の間は「未設定」です。</p>
    <label>残数の照会日 <input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} disabled={busy} /></label>
    <p>{year}年度 / 将来分は勤怠反映済みの取得予定です。申請中は含みません。残数超過を自動で申請拒否する機能はありません。</p>
    {message && <p role="alert">{message}</p>}
    {selected && <form key={`${selected.employeeId}:${selected.category}`} onSubmit={(e) => void save(e)} className="panel">
      <h4>{selected.employeeCode} {selected.employeeName} / {selected.category === "CHILD" ? "子の看護等休暇" : "介護休暇"} の基準残数</h4>
      <label>対象人数 <input name="eligibleCount" type="number" min="0" max="20" required defaultValue={selected.eligibleCount ?? ""} /></label>
      <label>所定勤務時間（1日分・分） <input name="dayMinutes" type="number" min="1" max="1440" required defaultValue={selected.dayMinutes ?? ""} /></label>
      <label>基準日（この日までの取得分を含む残数） <input name="effectiveOn" type="date" min={`${year}-03-31`} max={`${year + 1}-03-31`} required defaultValue={selected.effectiveOn ?? asOf} /></label>
      <label>残日数 <input name="days" type="number" min="0" max="10" required defaultValue={selected.openingMinutes !== null && selected.dayMinutes ? Math.floor(selected.openingMinutes / selected.dayMinutes) : ""} /></label>
      <label>残日数に加える端数（分） <input name="minutes" type="number" min="0" max="1439" required defaultValue={selected.openingMinutes !== null && selected.dayMinutes ? selected.openingMinutes % selected.dayMinutes : 0} /></label>
      <label>確認根拠・変更理由 <input name="note" required maxLength={500} defaultValue={selected.note ?? ""} /></label>
      <button type="submit" disabled={busy}>確認済み残数を保存</button><button type="button" disabled={busy} onClick={() => setSelected(null)}>閉じる</button>
    </form>}
    <div className="table-wrap"><table><thead><tr><th>職員</th><th>休暇</th><th>年間上限</th><th>年度内の記録</th><th>残数</th><th>将来予定</th><th>予定控除後</th><th>基準日</th><th>設定</th></tr></thead><tbody>
      {rows.map((r) => <tr key={`${r.employeeId}:${r.category}`}><td>{r.employeeCode} {r.employeeName}</td><td>{r.category === "CHILD" ? "子の看護等" : "介護"}</td>
        <td>{r.entitlementDays === null ? "未設定" : `${r.entitlementDays}日`}</td><td>{r.recordedDays}日 {hours(r.recordedMinutes)}</td>
        <td>{balanceText(r.remainingMinutes, r.dayMinutes)}</td><td>{balanceText(r.futureReservedMinutes, r.dayMinutes)}</td><td>{balanceText(r.availableMinutes, r.dayMinutes)}</td>
        <td>{r.effectiveOn ?? "未設定"}</td><td><button type="button" disabled={busy} onClick={() => setSelected(r)}>設定</button></td></tr>)}
    </tbody></table></div>
  </section>;
}
