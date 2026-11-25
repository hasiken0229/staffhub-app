import { useEffect, useRef, useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { DataTable } from "@/components/data-table";
import { createAttendanceDaily, loadAttendanceAttentionPage } from "@/lib/api/attendance";
import { formatTimeOnly, formatWorkMinutes } from "@/lib/api/formatters";
import type { AttendanceApproval } from "@/types";

type Props = {
  revision: string;
  onOpenDaily: (id: number, expected: Pick<AttendanceApproval, "employeeId" | "targetDate">) => Promise<void>;
  onRefresh: () => Promise<void>;
  onTotalChange?: (total: number) => void;
};
type Result = Awaited<ReturnType<typeof loadAttendanceAttentionPage>>;

export function AttendanceAttentionPanel({ revision, onOpenDaily, onRefresh, onTotalChange }: Props) {
  const [page, setPage] = useState(1);
  const [attempt, setAttempt] = useState(0);
  const [response, setResponse] = useState<{ key: string; result?: Result; error?: string } | null>(null);
  const [createTarget, setCreateTarget] = useState<AttendanceApproval | null>(null);
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState("");
  const createLock = useRef(false);
  const key = `${revision}/${page}/${attempt}`;
  const current = response?.key === key ? response : null;

  useEffect(() => {
    const controller = new AbortController();
    loadAttendanceAttentionPage(page, controller.signal).then(result => {
      if (controller.signal.aborted) return;
      onTotalChange?.(result.meta.total);
      const last = Math.max(1, Math.ceil(result.meta.total / Math.max(1, result.meta.perPage)));
      if (page > last) { setPage(last); return; }
      setResponse({ key, result });
    }).catch((error: unknown) => {
      if (!controller.signal.aborted) setResponse({ key, error: error instanceof Error ? error.message : "要確認の勤怠を取得できませんでした。" });
    });
    return () => controller.abort();
  }, [key, page, onTotalChange]);

  async function createAndOpen() {
    if (!createTarget || createLock.current) return;
    createLock.current = true;
    setCreating(true);
    setCreateError("");
    setCreateTarget(null);
    try {
      const daily = await createAttendanceDaily({employeeId: createTarget.employeeId, targetDate: createTarget.targetDate});
      if (daily.employeeId !== createTarget.employeeId || daily.targetDate !== createTarget.targetDate) {
        throw new Error("作成結果の職員または勤務日が一致しません。一覧を更新してください。");
      }
      setAttempt(value => value + 1);
      await onOpenDaily(daily.id, createTarget);
      await onRefresh();
    } catch (error) {
      setCreateError(error instanceof Error ? error.message : "日次の取得・更新に失敗しました。一覧を更新して確認してください。");
    } finally {
      createLock.current = false;
      setCreating(false);
    }
  }

  const result = current?.result;
  const pages = result ? Math.max(1, Math.ceil(result.meta.total / Math.max(1, result.meta.perPage))) : 1;
  return <section id="attendance-attention" className="stack-section anchor-panel" aria-label="全期間の要確認勤怠">
    <div className="button-row">
      <button type="button" className="secondary" disabled={creating || !current} onClick={() => { setCreateError(""); setAttempt(value => value + 1); }}>要確認一覧を更新</button>
    </div>
    {!current && <p role="status">全期間の要確認勤怠を読み込んでいます。</p>}
    {current?.error && <p role="alert" className="feedback">{current.error}</p>}
    {result && <DataTable title="打刻漏れなど要確認の勤怠（全期間）" rows={result.items}
      emptyMessage="要確認の日次勤怠はありません。" metaLabel={`全 ${result.meta.total} 件`}
      columns={[
        {key: "employee", header: "職員", render: row => `${row.employeeCode}　${row.employeeName}`},
        {key: "date", header: "勤務日", render: row => row.targetDate},
        {key: "rawIn", header: "出勤の原打刻", render: row => formatTimeOnly(row.rawClockInAt)},
        {key: "rawOut", header: "退勤の原打刻", render: row => formatTimeOnly(row.rawClockOutAt)},
        {key: "reason", header: "確認理由", render: row => {
          const reason = row.approvalReviewReason || row.alertSummary || "日次詳細をご確認ください";
          if (!row.scheduleInferredSide || row.workMinutes == null) return reason;
          const missingStart = row.scheduleInferredSide === "CLOCK_IN";
          const plannedTime = formatTimeOnly(missingStart ? row.clockInAt : row.clockOutAt);
          return <>{reason}<br/><small>
            打刻を{missingStart ? "退勤" : "出勤"}と判定。{missingStart ? "出勤" : "退勤"}は予定の{plannedTime}で補完。実働 {formatWorkMinutes(row.workMinutes)}
          </small></>;
        }},
        {key: "action", header: "操作", render: row => row.id !== null
          ? <button type="button" className="table-action" disabled={creating} onClick={() => void onOpenDaily(row.id!, row)}>詳細・修正</button>
          : <button type="button" className="table-action" disabled={creating} onClick={() => setCreateTarget(row)}>未登録の日次を作成</button>},
      ]}
      footer={pages > 1 && <nav className="button-row" aria-label="要確認一覧のページ">
        <button type="button" className="secondary" disabled={page <= 1 || creating} onClick={() => setPage(page - 1)}>前のページ</button>
        <span>{page} / {pages} ページ</span>
        <button type="button" className="secondary" disabled={page >= pages || creating} onClick={() => setPage(page + 1)}>次のページ</button>
      </nav>} />}
    {createError && <p role="alert" className="feedback">{createError}</p>}
    <ConfirmDialog open={createTarget !== null} title="未登録の日次を作成しますか？" message={createTarget ? `${createTarget.targetDate}　${createTarget.employeeCode} ${createTarget.employeeName}。日次を作成して修正画面を開きます。原打刻は追加されません。` : ""}
      confirmLabel="日次を作成して開く" onCancel={() => setCreateTarget(null)} onConfirm={() => void createAndOpen()} />
  </section>;
}
