import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

type PayrollTypeSwitchPanelProps = {
  form: PayrollSectionProps["form"];
  actions: PayrollSectionProps["actions"];
};

export function PayrollTypeSwitchPanel({ form, actions }: PayrollTypeSwitchPanelProps) {
  return (
    <section id="payroll-type" className="panel action-panel payroll-switch-panel anchor-panel">
      <div className="segmented-control" role="tablist" aria-label="明細種別">
        <button
          type="button"
          className={form.payrollStatementType === "PAYROLL" ? "segmented-item is-active" : "segmented-item"}
          onClick={() => actions.onPayrollStatementTypeChange("PAYROLL")}
        >
          給与明細
        </button>
        <button
          type="button"
          className={form.payrollStatementType === "BONUS" ? "segmented-item is-active" : "segmented-item"}
          onClick={() => actions.onPayrollStatementTypeChange("BONUS")}
        >
          賞与明細
        </button>
      </div>
    </section>
  );
}
