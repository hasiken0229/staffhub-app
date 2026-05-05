import { PayrollStatementDetailCard } from "@/components/payroll-statement-detail-card";
import { PayrollBatchDetailPanel } from "@/components/dashboard-sections/payroll/payroll-batch-detail-panel";
import { PayrollBatchListPanel } from "@/components/dashboard-sections/payroll/payroll-batch-list-panel";
import { PayrollDefinitionPanel } from "@/components/dashboard-sections/payroll/payroll-definition-panel";
import { PayrollHistoryPanel } from "@/components/dashboard-sections/payroll/payroll-history-panel";
import { PayrollRegisterPanel } from "@/components/dashboard-sections/payroll/payroll-register-panel";
import { PayrollStatementListPanel } from "@/components/dashboard-sections/payroll/payroll-statement-list-panel";
import { PayrollTypeSwitchPanel } from "@/components/dashboard-sections/payroll/payroll-type-switch-panel";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

export function PayrollSection(props: PayrollSectionProps) {
  const activePanel = props.data.activePanel || "payroll-type";
  const isWorkspacePanel = activePanel === "payroll-definitions" || activePanel === "payroll-register";

  return (
    <section className="stack-section section-enter delay-3">
      <PayrollTypeSwitchPanel form={props.form} actions={props.actions} />

      {activePanel === "payroll-type" ? <PayrollStartPanel data={props.data} /> : null}

      {isWorkspacePanel ? (
        <section className="payroll-workspace">
          {activePanel === "payroll-definitions" ? <PayrollDefinitionPanel data={props.data} form={props.form} actions={props.actions} /> : null}
          {activePanel === "payroll-register" ? <PayrollRegisterPanel data={props.data} form={props.form} actions={props.actions} /> : null}
        </section>
      ) : null}

      {activePanel !== "payroll-type" ? (
        <section className={isWorkspacePanel ? "payroll-layout is-hidden" : "payroll-layout"}>
          <div className="payroll-main stack-section">
            {activePanel === "payroll-history" ? (
              <PayrollHistoryPanel data={props.data} actions={props.actions} formatters={props.formatters} />
            ) : null}

            {activePanel === "payroll-batches" ? (
              <PayrollBatchListPanel data={props.data} form={props.form} actions={props.actions} formatters={props.formatters} />
            ) : null}

            {activePanel === "payroll-batch-detail" ? (
              <PayrollBatchDetailPanel data={props.data} form={props.form} actions={props.actions} formatters={props.formatters} />
            ) : null}

            {activePanel === "payroll-batch-detail" && props.data.selectedAdminPayrollDetail ? (
              <PayrollStatementDetailCard
                detail={props.data.selectedAdminPayrollDetail}
                mode="admin"
                onAdminPayrollDownload={props.actions.onAdminPayrollDownload}
                onPayrollDownload={props.actions.onPayrollDownload}
                onDeletePayrollStatement={props.actions.onDeletePayrollStatement}
                formatDateOnly={props.formatters.formatDateOnly}
                formatMonthDay={props.formatters.formatMonthDay}
              />
            ) : null}

            {activePanel === "payroll-statements" ? (
              <PayrollStatementListPanel data={props.data} actions={props.actions} formatters={props.formatters} />
            ) : null}
          </div>

          <div className="payroll-side stack-section">
          </div>
        </section>
      ) : null}
    </section>
  );
}

function PayrollStartPanel({ data }: Pick<PayrollSectionProps, "data">) {
  return (
    <section className="panel action-panel payroll-start-panel">
      <div className="panel-header">
        <div>
          <p className="panel-kicker">次の操作</p>
          <h3>{data.payrollTypeLabel}の確認・登録</h3>
        </div>
      </div>
      <div className="summary-strip">
        <div>
          <span className="detail-label">取込履歴</span>
          <strong>{data.filteredPayrollHistory.length} 件</strong>
        </div>
        <div>
          <span className="detail-label">取込バッチ</span>
          <strong>{data.filteredPayrollBatches.length} 件</strong>
        </div>
        <div>
          <span className="detail-label">公開済み明細</span>
          <strong>{data.filteredPayrollStatements.length} 件</strong>
        </div>
      </div>
      <p className="compact-empty">
        上部のページ内メニューから「取込履歴」「公開済み」「CSV登録」などを選ぶと、対象の一覧や登録フォームを表示できます。
      </p>
    </section>
  );
}
