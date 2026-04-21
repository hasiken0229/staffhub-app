import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

type PayrollOperationPanelProps = {
  data: PayrollSectionProps["data"];
  form: PayrollSectionProps["form"];
};

export function PayrollOperationPanel({ data, form }: PayrollOperationPanelProps) {
  return (
    <section id="payroll-operation" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">運用</p>
          <h3>PDFはCSVから自動生成</h3>
        </div>
      </div>
      <div className="stack-form">
        <p className="compact-empty">{data.payrollTypeLabel}CSVを登録すると、各職員の明細PDFが自動生成されます。</p>
        <p className="compact-empty">生成後は「取込バッチ一覧」または{`「公開済み${data.payrollTypeLabel}一覧」`}の「PDF保存」から取得してください。</p>
      </div>
      {form.payrollResult ? <p className="feedback">{form.payrollResult}</p> : null}
    </section>
  );
}
