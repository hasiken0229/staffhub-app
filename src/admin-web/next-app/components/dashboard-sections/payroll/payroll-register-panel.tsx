import { type FormEvent, useState } from "react";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

type PayrollRegisterPanelProps = {
  data: PayrollSectionProps["data"];
  form: PayrollSectionProps["form"];
  actions: PayrollSectionProps["actions"];
};

export function PayrollRegisterPanel({ data, form, actions }: PayrollRegisterPanelProps) {
  const [payrollPreview, setPayrollPreview] = useState<{ fileName: string; rowCount: number } | null>(null);

  async function previewPayrollCsv(file?: File) {
    if (!file) {
      setPayrollPreview(null);
      return;
    }

    const text = await file.text();
    const lines = text.split(/\r?\n/).filter((line) => line.trim() !== "");
    setPayrollPreview({
      fileName: file.name,
      rowCount: Math.max(0, lines.length - 1),
    });
  }

  async function submitPayrollCsv(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!payrollPreview) {
      return;
    }

    const shouldImport = window.confirm(
      `${data.payrollTypeLabel}CSVを登録します。\nファイル: ${payrollPreview.fileName}\n対象月: ${form.payrollTargetYearMonth}\n対象期間: ${form.payrollPeriodStartOn} - ${form.payrollPeriodEndOn}\n支給日: ${form.payrollPayDate}\n想定件数: ${payrollPreview.rowCount} 件`,
    );
    if (!shouldImport) {
      return;
    }

    const formData = new FormData(event.currentTarget);
    formData.set("statementType", form.payrollStatementType);
    if (form.payrollDefinitionId) {
      formData.set("definitionId", form.payrollDefinitionId);
    }
    await actions.onPayrollBatchCreate(formData);
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
        onSubmit={(event) => void submitPayrollCsv(event)}
      >
        <input type="hidden" name="statementType" value={form.payrollStatementType} readOnly />
        <div className="form-grid">
          <label>
            データ定義
            <select
              name="definitionId"
              value={form.payrollDefinitionId}
              onChange={(event) => actions.onPayrollDefinitionIdChange(event.target.value)}
            >
              <option value="">自動選択</option>
              {data.filteredPayrollDefinitions.map((definition) => (
                <option key={definition.id} value={definition.id}>
                  {definition.definitionName}
                </option>
              ))}
            </select>
          </label>
          <label>
            対象月
            <input
              name="targetYearMonth"
              type="month"
              value={form.payrollTargetYearMonth}
              onChange={(event) => actions.onPayrollTargetYearMonthChange(event.target.value)}
            />
          </label>
        </div>
        <div className="form-grid form-grid-3">
          <label>
            対象期間 開始
            <input
              name="periodStartOn"
              type="date"
              value={form.payrollPeriodStartOn}
              onChange={(event) => actions.onPayrollPeriodStartOnChange(event.target.value)}
            />
          </label>
          <label>
            対象期間 終了
            <input
              name="periodEndOn"
              type="date"
              value={form.payrollPeriodEndOn}
              onChange={(event) => actions.onPayrollPeriodEndOnChange(event.target.value)}
            />
          </label>
          <label>
            支給日
            <input name="payDate" type="date" value={form.payrollPayDate} onChange={(event) => actions.onPayrollPayDateChange(event.target.value)} />
          </label>
        </div>
        <div className="form-grid">
          <label>
            公開日
            <input
              name="publishDate"
              type="date"
              value={form.payrollPublishDate}
              onChange={(event) => actions.onPayrollPublishDateChange(event.target.value)}
            />
          </label>
          <label>
            備考
            <input name="remarks" value={form.payrollRemarks} onChange={(event) => actions.onPayrollRemarksChange(event.target.value)} placeholder="任意" />
          </label>
        </div>
        <label>
          CSVファイル
          <input name="file" type="file" accept=".csv,text/csv" onChange={(event) => void previewPayrollCsv(event.currentTarget.files?.[0])} />
        </label>
        <p className="compact-empty">選択したデータ定義と列名・列順・列数が一致しないCSVは登録できません。</p>
        {payrollPreview ? (
          <div className="import-preview">
            <strong>取込プレビュー</strong>
            <span>ファイル: {payrollPreview.fileName}</span>
            <span>対象月: {form.payrollTargetYearMonth}</span>
            <span>対象期間: {form.payrollPeriodStartOn} - {form.payrollPeriodEndOn}</span>
            <span>想定件数: {payrollPreview.rowCount} 件</span>
          </div>
        ) : null}
        <button type="submit" disabled={!payrollPreview}>
          プレビュー内容で登録する
        </button>
      </form>
    </section>
  );
}
