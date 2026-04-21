import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

type PayrollDefinitionPanelProps = {
  data: PayrollSectionProps["data"];
  form: PayrollSectionProps["form"];
  actions: PayrollSectionProps["actions"];
};

export function PayrollDefinitionPanel({ data, form, actions }: PayrollDefinitionPanelProps) {
  return (
    <section id="payroll-definitions" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">定義</p>
          <h3>データ定義を整える</h3>
        </div>
      </div>
      <div className="definition-list">
        {data.filteredPayrollDefinitions.length === 0 ? (
          <p className="compact-empty">データ定義はまだありません。</p>
        ) : (
          data.filteredPayrollDefinitions.map((definition) => (
            <button
              key={definition.id}
              type="button"
              className={form.payrollDefinitionId === String(definition.id) ? "definition-chip is-active" : "definition-chip"}
              onClick={() => actions.onPayrollDefinitionSelect(definition)}
            >
              <span className="definition-chip-title">{definition.definitionName}</span>
              <span className="definition-chip-meta">
                {definition.fieldCount} 項目 / {definition.isActive ? "使用中" : "停止中"}
              </span>
            </button>
          ))
        )}
      </div>
      <div className="stack-form">
        <label>
          定義名
          <input value={form.payrollDefinitionName} onChange={(event) => actions.onPayrollDefinitionNameChange(event.target.value)} />
        </label>
        <label className="checkbox-row">
          <input
            type="checkbox"
            checked={form.payrollDefinitionActive}
            onChange={(event) => actions.onPayrollDefinitionActiveChange(event.target.checked)}
          />
          使用中にする
        </label>
        <div className="button-row">
          <button
            type="button"
            className="secondary"
            onClick={() => void actions.onTemplateDownload(form.payrollStatementType === "BONUS" ? "bonus" : "payroll")}
          >
            サンプルCSV
          </button>
          <button type="button" onClick={() => void actions.onPayrollDefinitionSave()}>
            定義を保存
          </button>
        </div>
      </div>
      {form.payrollDefinitionResult ? <p className="feedback">{form.payrollDefinitionResult}</p> : null}
    </section>
  );
}
