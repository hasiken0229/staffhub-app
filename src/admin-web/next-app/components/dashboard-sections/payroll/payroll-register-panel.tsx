import { type FormEvent, useRef, useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { FieldError, ValidationSummary, type FieldErrors } from "@/components/form-field-errors";
import { ApiRequestError } from "@/lib/api/core";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";
import type { PayrollImportPreview } from "@/types";
import { hasJapaneseHolidayCalendar } from "@/lib/payroll-dates";

type PayrollRegisterPanelProps = {
  data: PayrollSectionProps["data"];
  form: PayrollSectionProps["form"];
  actions: PayrollSectionProps["actions"];
};

const PREVIEW_TEXT_COLUMNS = new Set(["社員番号", "姓", "名"]);

function buildHeaderLabels(headers: string[]) {
  const occurrences = new Map<string, number>();

  return headers.map((header, index) => {
    const occurrence = (occurrences.get(header) ?? 0) + 1;
    occurrences.set(header, occurrence);
    const isDuplicate = headers.filter((item) => item === header).length > 1;
    return {
      key: `${header}-${index}`,
      label: isDuplicate ? `${header} ${occurrence}` : header,
    };
  });
}

function formatPreviewAmount(value: string) {
  const amount = Number(value.replace(/,/g, ""));
  return Number.isFinite(amount) ? amount.toLocaleString("ja-JP") : value || "-";
}

function PreviewDetails({ preview }: { preview: PayrollImportPreview }) {
  const metadata = preview.preview;
  const headers = metadata.headers;
  const headerLabels = buildHeaderLabels(headers);

  return (
    <div className="payroll-import-preview">
      <div className="payroll-preview-summary">
        <div>
          <span className="detail-label">加工方式</span>
          <strong>{metadata.sourceFormat ? "給与・賞与ソフト形式を自動加工" : "標準CSV"}</strong>
        </div>
        <div>
          <span className="detail-label">元データ行</span>
          <strong>{metadata.sourceRowCount ?? "-"} 行（空行除く）</strong>
        </div>
        <div>
          <span className="detail-label">個人行</span>
          <strong>{metadata.candidateRowCount ?? metadata.normalizedRowCount} 行</strong>
        </div>
        <div>
          <span className="detail-label">取込対象</span>
          <strong>{metadata.normalizedRowCount} 件</strong>
        </div>
        <div>
          <span className="detail-label">除外</span>
          <strong>{metadata.skippedRowCount} 件</strong>
        </div>
        <div>
          <span className="detail-label">照合エラー</span>
          <strong>{preview.errorCount} 件</strong>
        </div>
      </div>

      <div className="summary-strip" aria-label="給与登録の確認">
        <div><span className="detail-label">新規</span><strong>{preview.newCount ?? preview.items.filter((item) => item.action !== "REPLACE").length}名</strong></div>
        <div><span className="detail-label">差替</span><strong>{preview.replacementCount ?? preview.items.filter((item) => item.action === "REPLACE").length}名</strong></div>
        <div><span className="detail-label">差引支給合計</span><strong>{(preview.totals?.netAmount ?? preview.items.reduce((sum, item) => sum + item.netAmount, 0)).toLocaleString("ja-JP")}円</strong></div>
        <div><span className="detail-label">総支給合計</span><strong>{(preview.totals?.grossAmount ?? preview.items.reduce((sum, item) => sum + item.grossAmount, 0)).toLocaleString("ja-JP")}円</strong></div>
        <div><span className="detail-label">控除合計</span><strong>{(preview.totals?.deductionAmount ?? preview.items.reduce((sum, item) => sum + item.deductionAmount, 0)).toLocaleString("ja-JP")}円</strong></div>
      </div>
      <div className="table-wrap">
        <table aria-label="職員ごとの登録内容"><thead><tr><th>職員</th><th>登録区分</th><th>総支給額</th><th>控除額計</th><th>差引支給</th></tr></thead>
          <tbody>{preview.items.map((item) => <tr key={`${item.line}-${item.employeeCode}`}><td>{item.employeeCode} {item.employeeName}</td><td>{item.action === "REPLACE" ? `差替（第${item.revision ?? 2}版）` : "新規"}</td><td>{item.grossAmount.toLocaleString("ja-JP")}円</td><td>{item.deductionAmount.toLocaleString("ja-JP")}円</td><td>{item.netAmount.toLocaleString("ja-JP")}円</td></tr>)}</tbody>
        </table>
      </div>
      {preview.errorCount > 0 ? <p className="banner" role="alert">エラーがあるため登録できません。CSVを修正してプレビューを取り直してください。正常な行もまだ登録していません。</p> : null}
      <div>
        <p className="preview-section-title">加工後の列順（{headers.length}列）</p>
        <div className="payroll-preview-headers">
          {headerLabels.map((header, index) => <span key={header.key}>{index + 1}. {header.label}</span>)}
        </div>
      </div>

      <div>
        <p className="preview-section-title">加工後データ（先頭{metadata.rows.length}件／全列）</p>
        {metadata.rows.length === 0 ? (
          <p className="compact-empty">加工後に登録対象となる行がありません。</p>
        ) : (
          <div className="table-wrap payroll-preview-table-wrap">
            <table>
              <thead>
                <tr>
                  {headerLabels.map((header) => <th key={header.key}>{header.label}</th>)}
                </tr>
              </thead>
              <tbody>
                {metadata.rows.map((row, index) => (
                  <tr key={`${row[0] ?? ""}-${index}`}>
                    {headers.map((header, columnIndex) => {
                      const value = row[columnIndex] ?? "";
                      return (
                        <td key={`${header}-${columnIndex}`} className={PREVIEW_TEXT_COLUMNS.has(header) ? undefined : "amount-cell"}>
                          {PREVIEW_TEXT_COLUMNS.has(header) ? value : formatPreviewAmount(value)}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {metadata.skippedRows.length > 0 ? (
        <div className="import-preview import-preview-warning">
          <strong>除外された行</strong>
          {metadata.skippedRows.slice(0, 10).map((row) => (
            <span key={`${row.line}-${row.employeeCode ?? ""}`}>
              {row.line}行目／社員番号 {row.employeeCode ?? "-"}／{row.reason}
            </span>
          ))}
          {metadata.skippedRows.length > 10 ? <span>ほか {metadata.skippedRows.length - 10} 件</span> : null}
        </div>
      ) : null}

      {preview.errors.length > 0 ? (
        <div className="import-preview import-preview-error">
          <strong>職員マスタ照合エラー</strong>
          {preview.errors.slice(0, 10).map((error) => (
            <span key={`${error.line}-${error.employeeCode ?? ""}`}>{error.line}行目／{error.employeeCode ?? "-"}／{error.message}</span>
          ))}
          {preview.errors.length > 10 ? <span>ほか {preview.errors.length - 10} 件</span> : null}
        </div>
      ) : null}
    </div>
  );
}

export function PayrollRegisterPanel({ data, form, actions }: PayrollRegisterPanelProps) {
  const formRef = useRef<HTMLFormElement>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [payrollPreview, setPayrollPreview] = useState<PayrollImportPreview | null>(null);
  const [previewError, setPreviewError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const previewRequestRef = useRef(0);
  const [isPreviewing, setIsPreviewing] = useState(false);
  const [isImporting, setIsImporting] = useState(false);
  const [pendingPayrollImport, setPendingPayrollImport] = useState<FormData | null>(null);
  const publishTimingMessage = buildPublishTimingMessage(form.payrollPublishDate);

  function invalidatePreview() {
    previewRequestRef.current += 1;
    setPayrollPreview(null);
    setPreviewError("");
    setFieldErrors({});
    setPendingPayrollImport(null);
  }

  async function submitPayrollCsv(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedFile) {
      setPreviewError("先にCSVファイルを選択してください。");
      return;
    }

    const formData = new FormData(event.currentTarget);
    formData.set("statementType", form.payrollStatementType);
    if (form.payrollDefinitionId) {
      formData.set("definitionId", form.payrollDefinitionId);
    }
    setPreviewError("");
    setIsPreviewing(true);
    const requestId = ++previewRequestRef.current;
    try {
      const result = await actions.onPayrollBatchPreview(formData);
      if (requestId === previewRequestRef.current) setPayrollPreview(result);
    } catch (error) {
      if (requestId !== previewRequestRef.current) return;
      setPayrollPreview(null);
      setFieldErrors(error instanceof ApiRequestError ? error.fieldErrors : {});
      setPreviewError(error instanceof Error ? error.message : "加工プレビューの取得に失敗しました。");
    } finally {
      setIsPreviewing(false);
    }
  }

  function requestPayrollImport() {
    if (!payrollPreview || !formRef.current || isImporting || payrollPreview.errorCount > 0 || payrollPreview.canImport === false || payrollPreview.importedCount === 0) {
      return;
    }

    const formData = new FormData(formRef.current);
    formData.set("statementType", form.payrollStatementType);
    if (form.payrollDefinitionId) {
      formData.set("definitionId", form.payrollDefinitionId);
    }
    if (payrollPreview.previewFingerprint) formData.set("previewFingerprint", payrollPreview.previewFingerprint);
    setPendingPayrollImport(formData);
  }

  async function confirmPayrollImport() {
    if (!pendingPayrollImport || isImporting) {
      return;
    }

    const payload = pendingPayrollImport;
    setIsImporting(true);
    setPendingPayrollImport(null);
    try {
      await actions.onPayrollBatchCreate(payload);
      invalidatePreview();
    } catch (error) {
      setFieldErrors(error instanceof ApiRequestError ? error.fieldErrors : {});
      setPreviewError(error instanceof Error ? error.message : "給与CSVの登録に失敗しました。");
      if (error instanceof ApiRequestError && error.code === "PREVIEW_CHANGED") setPayrollPreview(null);
    } finally {
      setIsImporting(false);
    }
  }

  return (
    <section id="payroll-register" className="panel action-panel anchor-panel payroll-register-panel">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">登録</p>
          <h3>{data.payrollTypeLabel}CSVを登録</h3>
        </div>
      </div>
      <form
        className="stack-form"
        ref={formRef}
        onSubmit={(event) => void submitPayrollCsv(event)}
      >
        <ValidationSummary errors={fieldErrors} />
        <input type="hidden" name="statementType" value={form.payrollStatementType} readOnly />
        <div className="form-grid">
          <label>
            データ定義
            <select
              name="definitionId"
              aria-invalid={Boolean(fieldErrors.definitionId?.length)} aria-describedby={fieldErrors.definitionId?.length ? "error-definitionId" : undefined}
              value={form.payrollDefinitionId}
              onChange={(event) => {
                invalidatePreview();
                actions.onPayrollDefinitionIdChange(event.target.value);
              }}
            >
              <option value="">自動選択</option>
              {data.filteredPayrollDefinitions.map((definition) => (
                <option key={definition.id} value={definition.id}>
                  {definition.definitionName}
                </option>
              ))}
            </select>
            <FieldError errors={fieldErrors} field="definitionId" />
          </label>
          <label>
            対象月
            <input
              name="targetYearMonth"
              type="month"
              required aria-invalid={Boolean(fieldErrors.targetYearMonth?.length)} aria-describedby={fieldErrors.targetYearMonth?.length ? "error-targetYearMonth" : undefined}
              value={form.payrollTargetYearMonth}
              onChange={(event) => {
                invalidatePreview();
                actions.onPayrollTargetYearMonthChange(event.target.value);
              }}
            />
            <FieldError errors={fieldErrors} field="targetYearMonth" />
          </label>
        </div>
        <div className="form-grid form-grid-3">
          <label>
            対象期間 開始
            <input
              name="periodStartOn"
              type="date"
              required aria-invalid={Boolean(fieldErrors.periodStartOn?.length)} aria-describedby={fieldErrors.periodStartOn?.length ? "error-periodStartOn" : undefined}
              value={form.payrollPeriodStartOn}
              onChange={(event) => {
                invalidatePreview();
                actions.onPayrollPeriodStartOnChange(event.target.value);
              }}
            />
            <FieldError errors={fieldErrors} field="periodStartOn" />
          </label>
          <label>
            対象期間 終了
            <input
              name="periodEndOn"
              type="date"
              required min={form.payrollPeriodStartOn || undefined} aria-invalid={Boolean(fieldErrors.periodEndOn?.length)} aria-describedby={fieldErrors.periodEndOn?.length ? "error-periodEndOn" : undefined}
              value={form.payrollPeriodEndOn}
              onChange={(event) => {
                invalidatePreview();
                actions.onPayrollPeriodEndOnChange(event.target.value);
              }}
            />
            <FieldError errors={fieldErrors} field="periodEndOn" />
          </label>
          <label>
            支給日
            <input
              name="payDate"
              type="date"
              required aria-invalid={Boolean(fieldErrors.payDate?.length)} aria-describedby={fieldErrors.payDate?.length ? "error-payDate" : undefined}
              value={form.payrollPayDate}
              onChange={(event) => {
                invalidatePreview();
                actions.onPayrollPayDateChange(event.target.value);
              }}
            />
            <FieldError errors={fieldErrors} field="payDate" />
            {form.payrollStatementType === "PAYROLL" ? (
              <span className="form-help">
                {hasJapaneseHolidayCalendar(form.payrollTargetYearMonth)
                  ? "当月28日（土日祝は直前の平日）、公開日は支給日の前日を初期設定します。"
                  : "対象年の祝日データが未収録のため、支給日・公開日を入力してください。"}
              </span>
            ) : null}
          </label>
        </div>
        <div className="form-grid">
          <label>
            公開日
            <input
              name="publishDate"
              type="date"
              required aria-invalid={Boolean(fieldErrors.publishDate?.length)} aria-describedby={fieldErrors.publishDate?.length ? "error-publishDate" : undefined}
              value={form.payrollPublishDate}
              onChange={(event) => {
                invalidatePreview();
                actions.onPayrollPublishDateChange(event.target.value);
              }}
            />
            <FieldError errors={fieldErrors} field="publishDate" />
            <span className="form-help">{publishTimingMessage}</span>
          </label>
          <label>
            備考
            <input
              name="remarks"
              value={form.payrollRemarks}
              onChange={(event) => {
                invalidatePreview();
                actions.onPayrollRemarksChange(event.target.value);
              }}
              placeholder="任意"
            />
          </label>
        </div>
        <label>
          CSVファイル
          <input
            name="file"
            type="file"
            aria-invalid={Boolean(fieldErrors.file?.length)} aria-describedby={fieldErrors.file?.length ? "error-file" : undefined}
            accept=".csv,text/csv"
            onChange={(event) => {
              setSelectedFile(event.currentTarget.files?.[0] ?? null);
              invalidatePreview();
            }}
          />
        </label>
        <FieldError errors={fieldErrors} field="file" />
        <p className="compact-empty">給与ソフトの「支給・控除一覧表」は、登録前にアプリ用の列へ自動加工して確認できます。</p>
        {selectedFile ? (
          <div className="import-preview">
            <strong>ファイル選択済み</strong>
            <span>ファイル: {selectedFile.name}</span>
            <span>対象月: {form.payrollTargetYearMonth}</span>
            <span>対象期間: {form.payrollPeriodStartOn} - {form.payrollPeriodEndOn}</span>
          </div>
        ) : null}
        {previewError ? <p className="feedback import-preview-error">{previewError}</p> : null}
        {isPreviewing ? <div className="import-preview"><strong>加工プレビューを作成中です…</strong></div> : null}
        {payrollPreview ? <PreviewDetails preview={payrollPreview} /> : null}
        <div className="button-row">
          <button type="submit" disabled={!selectedFile || isPreviewing || isImporting}>
            {payrollPreview ? "加工プレビューを更新" : "加工プレビューを取得"}
          </button>
          {payrollPreview ? (
            <button type="button" className="secondary" onClick={requestPayrollImport} disabled={isImporting || isPreviewing || payrollPreview.errorCount > 0 || payrollPreview.canImport === false || payrollPreview.importedCount === 0}>
              {isImporting ? "登録処理中..." : "この内容で登録する"}
            </button>
          ) : null}
        </div>
      </form>
      <ConfirmDialog
        open={pendingPayrollImport !== null}
        title={`${data.payrollTypeLabel}CSVを登録しますか？`}
        message={`ファイル: ${selectedFile?.name ?? "-"}／対象月: ${form.payrollTargetYearMonth}／対象期間: ${form.payrollPeriodStartOn} - ${form.payrollPeriodEndOn}／支給日: ${form.payrollPayDate}／公開日: ${form.payrollPublishDate || "未設定"}（${publishTimingMessage}）／新規: ${payrollPreview?.newCount ?? payrollPreview?.items.filter((item) => item.action !== "REPLACE").length ?? 0}名／差替: ${payrollPreview?.replacementCount ?? payrollPreview?.items.filter((item) => item.action === "REPLACE").length ?? 0}名／差引支給合計: ${(payrollPreview?.totals?.netAmount ?? payrollPreview?.items.reduce((sum, item) => sum + item.netAmount, 0) ?? 0).toLocaleString("ja-JP")}円／照合エラー: ${payrollPreview?.errorCount ?? 0}件`}
        confirmLabel="CSVを登録"
        onCancel={() => setPendingPayrollImport(null)}
        onConfirm={() => void confirmPayrollImport()}
      />
    </section>
  );
}

function buildPublishTimingMessage(publishDate: string) {
  if (!publishDate) {
    return "公開日を設定すると、職員が閲覧できる開始日を確認できます。";
  }

  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
  return publishDate <= today
    ? `登録後すぐに公開対象になります（公開日: ${publishDate}）。`
    : `登録すると${publishDate}から閲覧可能です。`;
}
