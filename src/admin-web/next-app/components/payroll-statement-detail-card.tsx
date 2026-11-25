import { formatStatementPayDate } from "@/lib/api/formatters";
import { useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { DataTable } from "@/components/data-table";
import type { PayrollStatementDetail } from "@/types";

type PayrollStatementDetailCardProps = {
  detail: PayrollStatementDetail;
  mode: "admin" | "employee";
  onAdminPayrollDownload: (statementId: number, fileName?: string) => Promise<void>;
  onPayrollDownload: (statementId: number, fileName?: string) => Promise<void>;
  onDeletePayrollStatement: (statementId: number) => Promise<void>;
  formatDateOnly: (value?: string | null) => string;
};

export function PayrollStatementDetailCard(props: PayrollStatementDetailCardProps) {
  const [isDeleteConfirmOpen, setIsDeleteConfirmOpen] = useState(false);
  function formatCurrency(value?: number | null) {
    if (value == null) {
      return "-";
    }

    return `${value.toLocaleString("ja-JP")} 円`;
  }

  const payDateLabel = formatStatementPayDate(props.detail.payDate);
  const periodLabel =
    props.detail.periodStartOn && props.detail.periodEndOn
      ? `${props.formatDateOnly(props.detail.periodStartOn)} 〜 ${props.formatDateOnly(props.detail.periodEndOn)}`
      : "-";

  return (
    <section className="panel action-panel payroll-detail-card">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">個票詳細</p>
          <h3 tabIndex={props.mode === "employee" ? -1 : undefined}>
            {props.detail.employeeName} / {props.detail.statementTypeLabel} {props.detail.targetYearMonth}
          </h3>
        </div>
        <div className="button-row">
          <button
            type="button"
            className="table-action"
            onClick={() =>
              props.mode === "admin"
                ? void props.onAdminPayrollDownload(props.detail.id, props.detail.originalFileName)
                : void props.onPayrollDownload(props.detail.id, props.detail.originalFileName)
            }
          >
            PDF保存
          </button>
          {props.mode === "admin" && props.detail.deleteAvailable ? (
            <button
              type="button"
              className="table-action danger"
              onClick={() => setIsDeleteConfirmOpen(true)}
            >
              削除する
            </button>
          ) : null}
        </div>
      </div>

      {(props.detail.revision ?? 1) > 1 ? (
        <p className="feedback" role="status">訂正版 第{props.detail.revision}版／差替日時: {props.detail.replacedAt ? new Date(props.detail.replacedAt).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" }) : "-"}</p>
      ) : null}
      {props.mode === "admin" ? <p>現在の版の閲覧回数: {props.detail.viewCount ?? 0}回{props.detail.lastViewedAt ? `／最終閲覧: ${new Date(props.detail.lastViewedAt).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" })}` : "（未閲覧）"}</p> : null}
      {props.mode === "admin" && props.detail.notificationStatus ? (
        <p>Google Chat通知: {{SCHEDULED:"公開日時に送信予定",QUEUED:"送信待ち",SENT:"送信済み（受信側の確認は別途必要）",INCOMPLETE:"未完了。給与は保存済みです。再登録せず監査ログを確認してください。",UNKNOWN:"過去の送信結果は未確認"}[props.detail.notificationStatus]}</p>
      ) : null}
      <div className="detail-grid">
        <div>
          <span className="detail-label">職員番号</span>
          <strong>{props.detail.employeeCode}</strong>
        </div>
        <div>
          <span className="detail-label">氏名</span>
          <strong>{props.detail.employeeName}</strong>
        </div>
        <div>
          <span className="detail-label">明細区分</span>
          <strong>{props.detail.statementTypeLabel}</strong>
        </div>
        <div>
          <span className="detail-label">対象月</span>
          <strong>{props.detail.targetYearMonth}</strong>
        </div>
        <div>
          <span className="detail-label">対象期間</span>
          <strong>{periodLabel}</strong>
        </div>
        <div>
          <span className="detail-label">支給日</span>
          <strong>{payDateLabel}</strong>
        </div>
        <div>
          <span className="detail-label">公開日</span>
          <strong>{props.detail.publishedAt ? props.formatDateOnly(props.detail.publishedAt) : "未公開"}</strong>
        </div>
      </div>

      {props.detail.legacyMode ? (
        <p className="detail-legacy-alert">
          旧形式の明細です。0円表示はCSVから金額内訳を復元できない項目を含みます。正式な金額確認はPDF保存した明細を利用してください。
        </p>
      ) : null}

      <div className="summary-strip">
        <div>
          <span className="detail-label">総支給額</span>
          <strong>{formatCurrency(props.detail.grossAmount)}</strong>
        </div>
        <div>
          <span className="detail-label">控除額計</span>
          <strong>{formatCurrency(props.detail.deductionAmount)}</strong>
        </div>
        <div>
          <span className="detail-label">差引支給</span>
          <strong>{formatCurrency(props.detail.netAmount)}</strong>
        </div>
      </div>

      <div className="split">
        <DataTable
          title="支給"
          rows={props.detail.sections.pay}
          emptyMessage="支給項目はありません"
          columns={[
            { key: "itemLabel", header: "項目", render: (row) => row.itemLabel },
            { key: "amount", header: "金額", render: (row) => row.formattedAmount },
          ]}
        />
        <DataTable
          title="控除"
          rows={props.detail.sections.deduction}
          emptyMessage="控除項目はありません"
          columns={[
            { key: "itemLabel", header: "項目", render: (row) => row.itemLabel },
            { key: "amount", header: "金額", render: (row) => row.formattedAmount },
          ]}
        />
      </div>

      <div className="split">
        <DataTable
          title="その他"
          rows={props.detail.sections.summary}
          emptyMessage="集計項目はありません"
          columns={[
            { key: "itemLabel", header: "項目", render: (row) => row.itemLabel },
            { key: "amount", header: "金額", render: (row) => row.formattedAmount },
          ]}
        />
        <section className="panel detail-note-panel">
          <div className="panel-header">
            <div>
              <p className="panel-kicker">備考</p>
              <h3>明細メモ</h3>
            </div>
          </div>
          <p className="detail-note-text">{props.detail.remarks || "備考はありません。"}</p>
          {props.detail.legacyMode ? (
            <p className="detail-legacy-note">
              旧形式のため、画面内の明細表は補助表示です。
            </p>
          ) : null}
        </section>
      </div>
      <ConfirmDialog
        open={isDeleteConfirmOpen}
        title="給与明細を削除しますか？"
        message={`職員: ${props.detail.employeeCode} / ${props.detail.employeeName}／対象: ${props.detail.statementTypeLabel} ${props.detail.targetYearMonth}／支給日: ${payDateLabel}／差引支給: ${formatCurrency(props.detail.netAmount)}。この操作は元に戻せません。`}
        confirmLabel="給与明細を削除"
        onCancel={() => setIsDeleteConfirmOpen(false)}
        onConfirm={() => {
          setIsDeleteConfirmOpen(false);
          void props.onDeletePayrollStatement(props.detail.id);
        }}
      />
    </section>
  );
}
