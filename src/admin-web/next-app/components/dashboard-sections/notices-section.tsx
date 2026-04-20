import { DataTable } from "@/components/data-table";
import type { Notice } from "@/types";

type NoticesSectionProps = {
  data: {
    notices: Notice[];
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

  return (
    <section className="split section-enter delay-3">
      {activePanel === "notices-list" ? (
        <DataTable
          id="notices-list"
          title="お知らせ一覧"
          rows={props.data.notices}
          emptyMessage="お知らせはありません"
          columns={[
            { key: "noticeType", header: "種別", render: (row) => props.formatters.formatNoticeType(row.noticeType) },
            { key: "title", header: "件名", render: (row) => row.title },
            { key: "publishStartAt", header: "公開開始", render: (row) => props.formatters.formatDateTime(row.publishStartAt) },
            { key: "publishEndAt", header: "公開終了", render: (row) => props.formatters.formatDateTime(row.publishEndAt) },
            { key: "createdByName", header: "登録者", render: (row) => row.createdByName ?? "-" },
          ]}
        />
      ) : null}
      {activePanel === "notices-register" ? (
        <section id="notices-register" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>お知らせ登録</h3>
          </div>
        </div>
        <label>
          種別
          <select value={props.form.noticeType} onChange={(event) => props.actions.onNoticeTypeChange(event.target.value)}>
            <option value="GENERAL">一般</option>
            <option value="PAYROLL_INFO">明細案内</option>
            <option value="SYSTEM">システム</option>
          </select>
        </label>
        <label>
          件名
          <input value={props.form.noticeTitle} onChange={(event) => props.actions.onNoticeTitleChange(event.target.value)} />
        </label>
        <label>
          本文
          <textarea rows={6} value={props.form.noticeBody} onChange={(event) => props.actions.onNoticeBodyChange(event.target.value)} />
        </label>
        <label>
          公開開始
          <input
            type="datetime-local"
            value={props.form.noticeStartAt}
            onChange={(event) => props.actions.onNoticeStartAtChange(event.target.value)}
          />
        </label>
        <label>
          公開終了
          <input
            type="datetime-local"
            value={props.form.noticeEndAt}
            onChange={(event) => props.actions.onNoticeEndAtChange(event.target.value)}
          />
        </label>
        <button type="button" onClick={() => void props.actions.onCreateNotice()}>
          お知らせを登録
        </button>
        {props.form.noticeResult ? <p className="feedback">{props.form.noticeResult}</p> : null}
        </section>
      ) : null}
    </section>
  );
}
