import { useMemo, useRef, useState } from "react";
import { DataTable } from "@/components/data-table";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { FieldError, ValidationSummary, type FieldErrors } from "@/components/form-field-errors";
import { ApiRequestError } from "@/lib/api/core";
import { loadAdminNotice, stopAdminNotice, updateAdminNotice, type ManagedNotice } from "@/lib/api/notices";
import type { Employee, Notice } from "@/types";

type NoticesSectionProps = {
  data: {
    notices: Notice[];
    employees: Employee[];
    activePanel: string;
  };
  form: {
    noticeType: string;
    noticeTitle: string;
    noticeBody: string;
    noticeStartAt: string;
    noticeEndAt: string;
    noticeResult: string;
  };
  actions: {
    onNoticeTypeChange: (value: string) => void;
    onNoticeTitleChange: (value: string) => void;
    onNoticeBodyChange: (value: string) => void;
    onNoticeStartAtChange: (value: string) => void;
    onNoticeEndAtChange: (value: string) => void;
    onCreateNotice: () => Promise<void>;
  };
  formatters: {
    formatNoticeType: (value?: string | null) => string;
    formatDateTime: (value?: string | null) => string;
  };
};

export function NoticesSection(props: NoticesSectionProps) {
  const activePanel = props.data.activePanel || "notices-list";
  const [noticeCategory, setNoticeCategory] = useState<"ALL" | "GENERAL" | "AUTOMATIC">("ALL");
  const [noticeEmployeeId, setNoticeEmployeeId] = useState("");
  const [noticeTargetMonth, setNoticeTargetMonth] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const submittingRef = useRef(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [feedback, setFeedback] = useState("");
  const [selected, setSelected] = useState<ManagedNotice | null>(null);
  const [overrides, setOverrides] = useState<Record<number, ManagedNotice>>({});
  const [loadingDetail, setLoadingDetail] = useState(false);
  const detailRequestRef = useRef(0);
  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState("");
  const [editBody, setEditBody] = useState("");
  const [editType, setEditType] = useState("GENERAL");
  const [editStart, setEditStart] = useState("");
  const [editEnd, setEditEnd] = useState("");
  const [confirmStop, setConfirmStop] = useState(false);
  const notices = useMemo(() => props.data.notices.map((notice) => (overrides[notice.id]?.revision ?? 0) >= ((notice as ManagedNotice).revision ?? 0) ? overrides[notice.id] ?? notice : notice), [props.data.notices, overrides]);
  const employeeNames = useMemo(() => new Map(props.data.employees.map((employee) => [employee.id, employee.name])), [props.data.employees]);
  const noticeMonths = useMemo(
    () => Array.from(new Set(notices.map(getNoticeTargetMonth).filter((month): month is string => Boolean(month)))).sort().reverse(),
    [notices],
  );
  const visibleNotices = notices.filter((notice) => {
    const automatic = isAutomaticNotice(notice);
    return (noticeCategory === "ALL" || (noticeCategory === "AUTOMATIC" ? automatic : !automatic))
      && (!noticeEmployeeId || String(notice.targetEmployeeId ?? "") === noticeEmployeeId)
      && (!noticeTargetMonth || getNoticeTargetMonth(notice) === noticeTargetMonth);
  });

  function reportError(error: unknown) {
    setErrors(error instanceof ApiRequestError ? error.fieldErrors : {});
    setFeedback(error instanceof Error ? error.message : "処理に失敗しました。");
  }
  async function createNotice(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submittingRef.current) return;
    submittingRef.current = true;
    setSubmitting(true); setErrors({}); setFeedback("");
    try { await props.actions.onCreateNotice(); } catch (error) { reportError(error); }
    finally { submittingRef.current = false; setSubmitting(false); }
  }
  async function showDetail(id: number) {
    const request = ++detailRequestRef.current;
    setLoadingDetail(true); setSelected(null); setEditing(false); setErrors({}); setFeedback("");
    try { const notice = await loadAdminNotice(id); if (request === detailRequestRef.current) setSelected(notice); }
    catch (error) { if (request === detailRequestRef.current) reportError(error); }
    finally { if (request === detailRequestRef.current) setLoadingDetail(false); }
  }
  function startEditing() {
    if (!selected) return;
    setEditTitle(selected.title); setEditBody(selected.body); setEditType(selected.noticeType);
    setEditStart(toLocalDateTime(selected.publishStartAt)); setEditEnd(toLocalDateTime(selected.publishEndAt));
    setErrors({}); setFeedback(""); setEditing(true);
  }
  function acceptNotice(notice: ManagedNotice) {
    setSelected(notice); setOverrides((current) => ({ ...current, [notice.id]: notice })); setEditing(false);
  }
  async function saveNotice(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected || submittingRef.current) return;
    submittingRef.current = true; setSubmitting(true); setErrors({}); setFeedback("");
    try {
      acceptNotice(await updateAdminNotice(selected.id, { revision: selected.revision ?? 1, noticeType: editType,
        title: editTitle, body: editBody, publishStartAt: editStart, publishEndAt: editEnd || undefined }));
      setFeedback("お知らせを更新しました。送信済みのお知らせは再通知しません。");
    } catch (error) { reportError(error); }
    finally { submittingRef.current = false; setSubmitting(false); }
  }
  async function stopNotice() {
    if (!selected || submittingRef.current) return;
    submittingRef.current = true; setSubmitting(true); setErrors({}); setFeedback("");
    try { acceptNotice(await stopAdminNotice(selected.id, selected.revision ?? 1)); setConfirmStop(false); setFeedback("公開を停止しました。職員画面への表示と未送信の通知を停止しました。"); }
    catch (error) { setConfirmStop(false); reportError(error); }
    finally { submittingRef.current = false; setSubmitting(false); }
  }

  return (
    <section className="stack-section section-enter delay-3">
      {activePanel === "notices-list" ? (
        <>
        <section className="panel action-panel anchor-panel">
          <div className="panel-header">
            <div>
              <p className="panel-kicker">一覧の絞り込み</p>
              <h3>お知らせ検索</h3>
            </div>
            <span className="panel-meta">{visibleNotices.length} / {props.data.notices.length} 件</span>
          </div>
          <div className="filter-grid">
            <label>
              お知らせの種類
              <select value={noticeCategory} onChange={(event) => setNoticeCategory(event.target.value as typeof noticeCategory)}>
                <option value="ALL">すべて</option>
                <option value="GENERAL">一般のお知らせ</option>
                <option value="AUTOMATIC">給与・賞与の自動通知</option>
              </select>
            </label>
            <label>
              対象職員
              <select value={noticeEmployeeId} onChange={(event) => setNoticeEmployeeId(event.target.value)}>
                <option value="">すべて</option>
                {props.data.employees.map((employee) => (
                  <option key={employee.id} value={employee.id}>{employee.employeeCode} {employee.name}</option>
                ))}
              </select>
            </label>
            <label>
              対象月
              <select value={noticeTargetMonth} onChange={(event) => setNoticeTargetMonth(event.target.value)}>
                <option value="">すべて</option>
                {noticeMonths.map((month) => <option key={month} value={month}>{month}</option>)}
              </select>
            </label>
          </div>
          <p className="compact-empty">給与・賞与の自動通知は対象職員ごとに表示します。</p>
        </section>
        <DataTable
          id="notices-list"
          title="お知らせ一覧"
          rows={visibleNotices}
          emptyMessage={props.data.notices.length === 0 ? "お知らせはありません" : "条件に一致するお知らせはありません"}
          columns={[
            { key: "noticeType", header: "種別", render: (row) => props.formatters.formatNoticeType(row.noticeType) },
            { key: "title", header: "件名", render: (row) => row.title },
            { key: "recipient", header: "対象職員", render: (row) => row.targetEmployeeId == null ? "全体" : employeeNames.get(row.targetEmployeeId) ?? `職員ID ${row.targetEmployeeId}` },
            { key: "targetMonth", header: "対象月", render: (row) => getNoticeTargetMonth(row) ?? "-" },
            { key: "publishStartAt", header: "公開開始", render: (row) => props.formatters.formatDateTime(row.publishStartAt) },
            { key: "publishEndAt", header: "公開終了", render: (row) => props.formatters.formatDateTime(row.publishEndAt) },
            { key: "publicationStatus", header: "公開状態", render: (row) => publicationLabel(row as ManagedNotice) },
            { key: "notificationStatus", header: "通知状態", render: (row) => notificationLabel(row as ManagedNotice) },
            { key: "createdByName", header: "登録者", render: (row) => row.createdByName ?? "-" },
            { key: "detail", header: "詳細", render: (row) => <button type="button" className="secondary" disabled={submitting || loadingDetail} onClick={() => void showDetail(row.id)}>本文・編集</button> },
          ]}
        />
        {loadingDetail ? <p role="status">お知らせを読み込んでいます…</p> : null}
        {selected ? <section className="panel action-panel" aria-label="お知らせ詳細">
          <div className="panel-header"><h3>お知らせ詳細</h3><button type="button" className="secondary" disabled={submitting} onClick={() => { detailRequestRef.current += 1; setSelected(null); setEditing(false); setErrors({}); setFeedback(""); }}>閉じる</button></div>
          <p>{publicationLabel(selected)} ／ {notificationLabel(selected)} ／ 第{selected.revision ?? 1}版</p>
          <p>対象: {selected.targetEmployeeId == null ? "全職員" : employeeNames.get(selected.targetEmployeeId) ?? `職員ID ${selected.targetEmployeeId}`}</p>
          {editing ? <form onSubmit={(event) => void saveNotice(event)}>
            <ValidationSummary errors={errors} />
            <fieldset className="stack-form" style={{ border: 0, padding: 0, minWidth: 0 }} disabled={submitting}>
              <NoticeFields values={{ noticeType: editType, title: editTitle, body: editBody, start: editStart, end: editEnd }}
                setters={{ noticeType: setEditType, title: setEditTitle, body: setEditBody, start: setEditStart, end: setEditEnd }} errors={errors} />
              <p className="compact-empty">公開前の編集は、公開時に最新の本文で通知します。送信済みのお知らせは再通知しません。</p>
              <div className="actions-row"><button type="submit">{submitting ? "保存中…" : "変更を保存"}</button><button type="button" className="secondary" onClick={() => { setEditing(false); setErrors({}); }}>編集をやめる</button></div>
            </fieldset>
          </form> : <>
            <h4>{selected.title}</h4><p style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere" }}>{selected.body}</p>
            <p>公開期間: {props.formatters.formatDateTime(selected.publishStartAt)} ～ {props.formatters.formatDateTime(selected.publishEndAt)}</p>
            {selected.updatedAt ? <p>最終更新: {props.formatters.formatDateTime(selected.updatedAt)}</p> : null}
            {!isAutomaticNotice(selected) && !selected.stoppedAt ? <div className="actions-row"><button type="button" className="secondary" disabled={submitting} onClick={startEditing}>編集する</button><button type="button" className="danger-action" disabled={submitting} onClick={() => setConfirmStop(true)}>公開を停止</button></div> : null}
            {isAutomaticNotice(selected) ? <p className="compact-empty">給与・賞与の自動通知は給与明細の管理画面から操作してください。</p> : null}
          </>}
        </section> : null}
        {feedback ? <p className="feedback" role="status">{feedback}</p> : null}
        </>
      ) : null}
      {activePanel === "notices-register" ? (
        <section id="notices-register" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>お知らせ登録</h3>
          </div>
        </div>
        <form onSubmit={(event) => void createNotice(event)}>
          <ValidationSummary errors={errors} />
          <fieldset className="stack-form" style={{ border: 0, padding: 0, minWidth: 0 }} disabled={submitting}>
            <NoticeFields values={{ noticeType: props.form.noticeType, title: props.form.noticeTitle, body: props.form.noticeBody, start: props.form.noticeStartAt, end: props.form.noticeEndAt }}
              setters={{ noticeType: props.actions.onNoticeTypeChange, title: props.actions.onNoticeTitleChange, body: props.actions.onNoticeBodyChange, start: props.actions.onNoticeStartAtChange, end: props.actions.onNoticeEndAtChange }} errors={errors} />
            <p className="compact-empty">全職員へ公開します。Google Chat通知は公開開始時刻以降に送信します。公開終了後や公開停止後は送信しません。</p>
            <button type="submit">{submitting ? "登録中…" : "お知らせを登録"}</button>
          </fieldset>
        </form>
        {feedback || props.form.noticeResult ? <p className="feedback" role="status">{feedback || props.form.noticeResult}</p> : null}
        </section>
      ) : null}
      <ConfirmDialog open={confirmStop} title="お知らせの公開を停止" message={`「${selected?.title ?? ""}」を職員画面から非表示にし、未送信の通知を停止します。すでにGoogle Chatへ送信したメッセージは残ります。`} confirmLabel="公開を停止" busy={submitting} onCancel={() => setConfirmStop(false)} onConfirm={() => void stopNotice()} />
    </section>
  );
}

type NoticeValues = { noticeType: string; title: string; body: string; start: string; end: string };
function NoticeFields({ values, setters, errors }: { values: NoticeValues; setters: { [K in keyof NoticeValues]: (value: string) => void }; errors: FieldErrors }) {
  const invalid = (field: string) => ({ "aria-invalid": Boolean(errors[field]?.length), "aria-describedby": errors[field]?.length ? `error-${field}` : undefined });
  return <>
    <label>種別<select name="noticeType" value={values.noticeType} onChange={(event) => setters.noticeType(event.target.value)} {...invalid("noticeType")}><option value="GENERAL">一般</option><option value="PAYROLL_INFO">明細案内</option><option value="SYSTEM">システム</option></select><FieldError errors={errors} field="noticeType" /></label>
    <label>件名<input name="title" required maxLength={100} value={values.title} onChange={(event) => setters.title(event.target.value)} {...invalid("title")} /><FieldError errors={errors} field="title" /></label>
    <label>本文<textarea name="body" required rows={6} value={values.body} onChange={(event) => setters.body(event.target.value)} {...invalid("body")} /><FieldError errors={errors} field="body" /></label>
    <label>公開開始<input name="publishStartAt" required type="datetime-local" value={values.start} onChange={(event) => setters.start(event.target.value)} {...invalid("publishStartAt")} /><FieldError errors={errors} field="publishStartAt" /></label>
    <label>公開終了<input name="publishEndAt" type="datetime-local" min={values.start || undefined} value={values.end} onChange={(event) => setters.end(event.target.value)} {...invalid("publishEndAt")} /><FieldError errors={errors} field="publishEndAt" /></label>
  </>;
}
function toLocalDateTime(value?: string | null) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}
function publicationLabel(notice: ManagedNotice) {
  if (notice.stoppedAt || notice.publicationStatus === "STOPPED") return "公開停止";
  const status = notice.publicationStatus ?? (new Date(notice.publishStartAt).getTime() > Date.now() ? "SCHEDULED" : notice.publishEndAt && new Date(notice.publishEndAt).getTime() <= Date.now() ? "ENDED" : "PUBLISHED");
  return { SCHEDULED: "公開予定", PUBLISHED: "公開中", ENDED: "公開終了", STOPPED: "公開停止" }[status];
}
function notificationLabel(notice: ManagedNotice) {
  if (isAutomaticNotice(notice)) return "給与明細で確認";
  return { UNKNOWN: "履歴未記録", SCHEDULED: "通知予約", QUEUED: "送信待ち", SENT: "送信済み", INCOMPLETE: "送信未完了", CANCELLED: "送信停止" }[notice.notificationStatus ?? "UNKNOWN"];
}

function isAutomaticNotice(notice: Notice) {
  return ["PAYROLL_PUBLISHED", "BONUS_PUBLISHED"].includes(notice.noticeType.toUpperCase())
    || /PAYROLL|BONUS/i.test(notice.relatedType ?? "");
}

function getNoticeTargetMonth(notice: Notice) {
  return /\b\d{4}-\d{2}\b/.exec(notice.body)?.[0] ?? null;
}
