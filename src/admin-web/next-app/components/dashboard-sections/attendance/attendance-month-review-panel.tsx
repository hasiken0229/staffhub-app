import { useCallback, useEffect, useRef, useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { DataTable } from "@/components/data-table";
import { approveAttendanceMonthCandidates, loadAttendanceApprovalPreview, loadAttendanceMonthCloseStatus } from "@/lib/api/attendance";
import type { AttendanceApprovalPreview, AttendanceBatchResult, AttendanceMonthCloseStatusRow } from "@/types/attendance";
import type { AttendanceSectionProps } from "./attendance-section-types";

export const attendanceDayLabels: Record<string, string> = {
  INCOMPLETE_APPROVED: "承認済み・実働要確認", FUTURE_ALL: "本日・未到来（登録済みを含む）", FUTURE: "未到来", HOLIDAY: "公休", LEAVE: "休暇", WORKING: "勤務中", NOT_DUE: "当日・未確定", COMPLETE: "記録あり", MISSING: "要確認",
  WORKDAY_UNREGISTERED: "勤務日候補・未登録", FUTURE_UNREGISTERED: "未到来・未登録", HOLIDAY_UNREGISTERED: "休日候補・未登録", TODAY_UNREGISTERED: "当日・未登録",
};

export type AttendanceReviewRequest = { targetMonth: string; code: string; revision: number };
const blockerCategories: Record<string, string> = { INCOMPLETE_APPROVED_DAILY: "INCOMPLETE_APPROVED", UNSUBMITTED_DAILY: "UNREGISTERED", PERIOD_NOT_FINISHED: "FUTURE_ALL", PENDING_APPROVAL: "PENDING", RETURNED_APPROVAL: "RETURNED" };
type Props = { targetMonth: string; formatters: AttendanceSectionProps["formatters"]; onRefresh: () => Promise<void>; onOpenDaily: (id?: number | null) => Promise<void>; reviewRequest?: AttendanceReviewRequest | null };

export function AttendanceMonthReviewPanel({ targetMonth, formatters, onRefresh, onOpenDaily, reviewRequest }: Props) {
  const [preview, setPreview] = useState<AttendanceApprovalPreview | null>(null);
  const [statusRows, setStatusRows] = useState<AttendanceMonthCloseStatusRow[]>([]);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [showPreview, setShowPreview] = useState(false);
  const [showDetails, setShowDetails] = useState(false);
  const [employeeId, setEmployeeId] = useState("");
  const [category, setCategory] = useState("WORKDAY_UNREGISTERED");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [result, setResult] = useState<AttendanceBatchResult | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [comment, setComment] = useState("");
  const sequence = useRef(0);
  const submitting = useRef(false);
  const detailsRef = useRef<HTMLDivElement>(null);
  const reload = useCallback(async () => {
    const request = ++sequence.current;
    setLoading(true); setError(""); setPreview(null); setSelected(new Set());
    try {
      const [next, rows] = await Promise.all([loadAttendanceApprovalPreview(targetMonth), loadAttendanceMonthCloseStatus({targetMonth, page:1, perPage:10000})]);
      if (request !== sequence.current) return;
      setPreview(next); setStatusRows(rows);
      setSelected(new Set(next.items.filter(item => item.eligible && item.daily.id !== null).map(item => item.daily.id!)));
    } catch (cause) {
      if (request === sequence.current) setError(cause instanceof Error ? cause.message : "月締めの確認情報を取得できませんでした。");
    } finally { if (request === sequence.current) setLoading(false); }
  }, [targetMonth]);
  useEffect(() => { setResult(null); setConfirm(false); setShowPreview(false); setShowDetails(false); setEmployeeId(""); void reload(); return () => { sequence.current++; }; }, [reload]);
  useEffect(() => {
    if (reviewRequest?.targetMonth !== targetMonth) return;
    setCategory(blockerCategories[reviewRequest.code] ?? "");
    setEmployeeId("");
    setShowDetails(true);
  }, [reviewRequest, targetMonth]);
  useEffect(() => {
    if (!showDetails || reviewRequest?.targetMonth !== targetMonth) return;
    detailsRef.current?.scrollIntoView?.({ block: "start" });
    detailsRef.current?.focus({ preventScroll: true });
  }, [showDetails, reviewRequest, targetMonth]);

  async function approve() {
    if (submitting.current || !preview || preview.targetMonth !== targetMonth) return;
    const candidates = preview.items.filter(item => item.eligible && item.daily.id !== null && selected.has(item.daily.id)).map(item => ({id:item.daily.id!, fingerprint:item.fingerprint}));
    if (!candidates.length) return;
    submitting.current = true; setBusy(true); setError(""); setConfirm(false);
    try {
      setResult(await approveAttendanceMonthCandidates(targetMonth, candidates, comment));
      await reload();
      await onRefresh();
    } catch (cause) {
      setError(`${cause instanceof Error ? cause.message : "一括承認の結果を確認できません。"} 再処理せず一覧と履歴を再取得して確認してください。`);
      setSelected(new Set());
    } finally { submitting.current = false; setBusy(false); }
  }

  const counts = statusRows.reduce((total, row) => ({workday:total.workday+(row.missingBreakdown?.workday??0), holiday:total.holiday+(row.missingBreakdown?.holiday??0), future:total.future+(row.missingBreakdown?.future??0), today:total.today+(row.missingBreakdown?.today??0), pending:total.pending+row.pendingCount}), {workday:0,holiday:0,future:0,today:0,pending:0});
  const details = statusRows.flatMap(row => (row.dayDetails??[]).map(day => ({...day,employeeId:row.employee.id,employeeCode:row.employee.employeeCode,employeeName:row.employee.name}))).filter(day => (!employeeId || String(day.employeeId)===employeeId) && (!category || (category === "INCOMPLETE_APPROVED" && day.incompleteApproved) || day.state===category || day.approvalStatus===category || (category === "UNREGISTERED" && day.state.endsWith("_UNREGISTERED")) || (category === "FUTURE_ALL" && (day.notElapsed || ["FUTURE", "FUTURE_UNREGISTERED", "TODAY_UNREGISTERED", "NOT_DUE", "WORKING"].includes(day.state)))));
  return <section className="panel action-panel anchor-panel">
    <div className="panel-header"><div><h3>月締め前の確認と一括承認</h3><p>対象月 {targetMonth}・全職員。検索条件による職員の絞り込みは適用しません。</p></div></div>
    {loading ? <p role="status">承認候補と日付別の状況を確認しています。</p> : null}
    {error ? <div role="alert" className="banner"><p>{error}</p><button type="button" className="secondary" disabled={busy} onClick={() => void reload()}>確認情報を再取得</button></div> : null}
    {preview ? <><p>未登録の内訳: 勤務日候補 {counts.workday}人日 / 休日候補 {counts.holiday}人日 / 未到来 {counts.future}人日 / 当日 {counts.today}人日。承認待ち {counts.pending}人日。</p><p className="compact-empty">人日は職員ごとの日数の合計です（2人に各19日ある場合は38人日）。休日候補は未登録の土日・祝休日です。個人の勤務予定は日付別に確認できます。未登録日は月締め前に確認してください。</p>
      <div className="button-row"><button type="button" disabled={busy} onClick={() => setShowPreview(!showPreview)}>問題のない勤怠を一括承認（{preview.eligibleCount}件）</button><button type="button" className="secondary" onClick={() => setShowDetails(!showDetails)}>職員・日付別に確認</button><button type="button" className="secondary" disabled={busy} onClick={() => void reload()}>再取得</button></div>
    </> : null}
    {showDetails ? <div ref={detailsRef} tabIndex={-1} aria-label="月締めの職員・日付別確認" className="stack-form anchor-panel">{reviewRequest?.targetMonth === targetMonth && !(reviewRequest.code in blockerCategories) ? <p>申請・勤怠エラーはこの一覧だけでは絞り込めないため、対象月の全職員・日付を表示しています。各日の詳細と申請・エラーの内容を確認してください。</p> : null}<div className="form-grid"><label>職員<select value={employeeId} onChange={event=>setEmployeeId(event.target.value)}><option value="">全職員</option>{statusRows.map(row=><option key={row.employee.id} value={row.employee.id}>{row.employee.employeeCode} {row.employee.name}</option>)}</select></label><label>確認する状況<select value={category} onChange={event=>setCategory(event.target.value)}><option value="">すべて</option><option value="UNREGISTERED">未登録（すべて）</option>{["INCOMPLETE_APPROVED","WORKDAY_UNREGISTERED","HOLIDAY_UNREGISTERED","FUTURE_ALL","FUTURE_UNREGISTERED","TODAY_UNREGISTERED","PENDING","RETURNED"].map(value=><option key={value} value={value}>{attendanceDayLabels[value]??(value==="PENDING"?"承認待ち":"差戻し")}</option>)}</select></label></div><DataTable title="日付別の確認" rows={details} emptyMessage="該当する日付はありません" columns={[
      {key:"employee",header:"職員",render:row=>`${row.employeeCode} ${row.employeeName}`},{key:"date",header:"日付",render:row=>formatters.formatDateOnly(row.targetDate)},{key:"calendar",header:"暦・個人予定",render:row=><>{row.holidayName || "—"}<br/>{row.shift ? [row.shift.name, [row.shift.start?.slice(0,5),row.shift.end?.slice(0,5)].filter(Boolean).join("～")].filter(Boolean).join(" ") || "日別予定あり" : "日別予定未登録"}</>},{key:"state",header:"状況",render:row=>row.incompleteApproved ? "承認済み・実働要確認" : attendanceDayLabels[row.state]??row.state},{key:"approval",header:"承認",render:row=>row.approvalStatus?formatters.formatApprovalStatus(row.approvalStatus):"未登録"},{key:"open",header:"操作",render:row=>row.dailyId?<button type="button" className="table-action" onClick={()=>void onOpenDaily(row.dailyId)}>詳細</button>:"日次未登録"},
    ]}/></div> : null}
    {showPreview && preview ? <div className="stack-form"><p>一括承認の対象と判定された勤怠だけを初期選択します。打刻漏れなど、個別確認が必要な理由は各行に表示します。</p><DataTable title={`一括承認の候補 ${preview.items.length}件（対象外 ${preview.excludedCount}件）`} rows={preview.items} emptyMessage="承認待ちの勤怠はありません" columns={[
      {key:"select",header:"選択",render:row=><label className="table-checkbox"><input type="checkbox" aria-label={`${row.daily.targetDate} ${row.daily.employeeName}を承認対象にする`} checked={row.daily.id!==null&&selected.has(row.daily.id)} disabled={busy||!row.eligible} onChange={event=>setSelected(current=>{const next=new Set(current);if(row.daily.id!==null){if(event.target.checked)next.add(row.daily.id);else next.delete(row.daily.id);}return next;})}/></label>},
      {key:"who",header:"職員・日付",render:row=><>{row.daily.employeeCode} {row.daily.employeeName}<br/>{formatters.formatDateOnly(row.daily.targetDate)}</>},
      {key:"raw",header:"原打刻",render:row=><>{formatters.formatTimeOnly(row.daily.rawClockInAt)} ～ {formatters.formatTimeOnly(row.daily.rawClockOutAt)}</>},
      {key:"adopted",header:"採用時刻",render:row=><>{formatters.formatTimeOnly(row.daily.clockInAt)} ～ {formatters.formatTimeOnly(row.daily.clockOutAt)}</>},
      {key:"work",header:"休憩・実働",render:row=><>休憩 {formatters.formatWorkMinutes(row.daily.breakMinutes)}<br/>実働 {formatters.formatWorkMinutes(row.daily.workMinutes)}</>},
      {key:"reason",header:"判定",render:row=>row.eligible?"承認可能":row.reasons.join(" / ")},
      {key:"open",header:"操作",render:row=><button type="button" className="table-action" disabled={busy} onClick={()=>void onOpenDaily(row.daily.id)}>詳細</button>},
    ]}/><label>承認コメント<textarea rows={2} value={comment} disabled={busy} onChange={event=>setComment(event.target.value)}/></label><div className="button-row"><button type="button" disabled={busy||loading||selected.size===0} onClick={()=>setConfirm(true)}>{busy?"承認中…":`選択した ${selected.size}件を承認`}</button><button type="button" className="secondary" disabled={busy} onClick={()=>setSelected(new Set())}>選択を解除</button></div></div> : null}
    {result ? <div role="status"><p>承認 {result.updatedCount}件 / 変更なし {result.unchangedCount??0}件 / 見送り {result.skippedCount??0}件 / 失敗・結果不明 {result.errors.filter(item=>item.status!=="SKIPPED").length}件。月締めはまだ行っていません。</p>{result.errors.map(item=><p key={item.id}>日次ID {item.id}: {item.status==="UNKNOWN"?"結果不明":item.status==="SKIPPED"?"見送り":"失敗"} / {item.message}</p>)}</div> : null}
    <ConfirmDialog open={confirm} title="選択した勤怠を一括承認しますか？" message={`対象月 ${targetMonth}・全職員のうち選択した ${selected.size}件。確認後の変更はサーバーで再確認し、変更された行は承認しません。月締めは別操作です。`} confirmLabel={`${selected.size}件を承認`} onCancel={()=>setConfirm(false)} onConfirm={()=>void approve()}/>
  </section>;
}
