import { DataTable } from "@/components/data-table";
import { PayrollStatementDetailCard } from "@/components/payroll-statement-detail-card";
import type {
  ImportHistory,
  PayrollDataDefinition,
  PayrollImportBatch,
  PayrollImportBatchDetail,
  PayrollStatement,
  PayrollStatementDetail,
} from "@/types";

type PayrollSectionProps = {
  data: {
    payrollTypeLabel: string;
    filteredPayrollDefinitions: PayrollDataDefinition[];
    filteredPayrollBatches: PayrollImportBatch[];
    filteredPayrollStatements: PayrollStatement[];
    filteredPayrollHistory: ImportHistory[];
    selectedPayrollBatchDetail: PayrollImportBatchDetail | null;
    selectedAdminPayrollDetail: PayrollStatementDetail | null;
    activePanel: string;
  };
  form: {
    payrollStatementType: "PAYROLL" | "BONUS";
    payrollDefinitionId: string;
    payrollDefinitionName: string;
    payrollDefinitionActive: boolean;
    payrollDefinitionResult: string;
    payrollBatchResult: string;
    payrollResult: string;
    payrollTargetYearMonth: string;
    payrollPeriodStartOn: string;
    payrollPeriodEndOn: string;
    payrollPayDate: string;
    payrollPublishDate: string;
    payrollRemarks: string;
    payrollBatchTargetMonthFilter: string;
    payrollBatchEmployeeCodeFilter: string;
    payrollBatchEmployeeNameFilter: string;
  };
  actions: {
    onPayrollStatementTypeChange: (value: "PAYROLL" | "BONUS") => void;
    onPayrollDefinitionIdChange: (value: string) => void;
    onPayrollDefinitionNameChange: (value: string) => void;
    onPayrollDefinitionActiveChange: (value: boolean) => void;
    onPayrollTargetYearMonthChange: (value: string) => void;
    onPayrollPeriodStartOnChange: (value: string) => void;
    onPayrollPeriodEndOnChange: (value: string) => void;
    onPayrollPayDateChange: (value: string) => void;
    onPayrollPublishDateChange: (value: string) => void;
    onPayrollRemarksChange: (value: string) => void;
    onPayrollBatchTargetMonthFilterChange: (value: string) => void;
    onPayrollBatchEmployeeCodeFilterChange: (value: string) => void;
    onPayrollBatchEmployeeNameFilterChange: (value: string) => void;
    onPayrollDefinitionSelect: (definition: PayrollDataDefinition) => void;
    onPayrollDefinitionSave: () => Promise<void>;
    onTemplateDownload: (kind: "payroll" | "bonus") => Promise<void>;
    onPayrollBatchCreate: (formData: FormData) => Promise<void>;
    onOpenPayrollBatchDetail: (batchId: number) => Promise<void>;
    onSearchPayrollBatchDetail: () => Promise<void>;
    onDeletePayrollBatch: (batchId: number) => Promise<void>;
    onExportPayrollBatch: (batchId: number, fileName?: string) => Promise<void>;
    onLoadAdminPayrollDetail: (statementId: number) => Promise<void>;
    onAdminPayrollDownload: (statementId: number, fileName?: string) => Promise<void>;
    onPayrollDownload: (statementId: number, fileName?: string) => Promise<void>;
    onDeletePayrollStatement: (statementId: number) => Promise<void>;
    onFileHistoryDownload: (historyId: number, fileName?: string) => Promise<void>;
  };
  formatters: {
    formatDateOnly: (value?: string | null) => string;
    formatDateTime: (value?: string | null) => string;
    formatMonthDay: (value?: string | null) => string;
    formatImportType: (value?: string | null) => string;
    formatPayrollBatchStatus: (value?: string | null) => string;
  };
};

export function PayrollSection(props: PayrollSectionProps) {
  const activePanel = props.data.activePanel || "payroll-type";

  function formatCurrency(value?: number | null) {
    if (value == null) {
      return "-";
    }

    return `${value.toLocaleString("ja-JP")} 円`;
  }

  function renderPayrollBatchDetailPanels() {
    if (!props.data.selectedPayrollBatchDetail) {
      return null;
    }

    const detail = props.data.selectedPayrollBatchDetail;

    return (
      <>
        <section id="payroll-batch-detail" className="panel action-panel anchor-panel">
          <div className="panel-header">
            <div>
              <p className="panel-kicker">取込詳細</p>
              <h3>
                {detail.statementTypeLabel} {detail.targetYearMonth}
              </h3>
            </div>
            <div className="button-row">
              <button
                type="button"
                className="table-action"
                onClick={() =>
                  void props.actions.onExportPayrollBatch(
                    detail.id,
                    `${detail.statementType === "BONUS" ? "bonus" : "payroll"}_batch_${detail.targetYearMonth}.zip`,
                  )
                }
              >
                一括PDF出力
              </button>
              <button type="button" className="table-action danger" onClick={() => void props.actions.onDeletePayrollBatch(detail.id)}>
                取込バッチ削除
              </button>
            </div>
          </div>

          <div className="detail-grid">
            <div>
              <span className="detail-label">データ定義</span>
              <strong>{detail.definitionName}</strong>
            </div>
            <div>
              <span className="detail-label">対象月</span>
              <strong>{detail.targetYearMonth}</strong>
            </div>
            <div>
              <span className="detail-label">対象期間</span>
              <strong>
                {props.formatters.formatDateOnly(detail.periodStartOn)} 〜 {props.formatters.formatDateOnly(detail.periodEndOn)}
              </strong>
            </div>
            <div>
              <span className="detail-label">支給日</span>
              <strong>{props.formatters.formatMonthDay(detail.payDate)}</strong>
            </div>
            <div>
              <span className="detail-label">公開日</span>
              <strong>{props.formatters.formatMonthDay(detail.publishDate)}</strong>
            </div>
            <div>
              <span className="detail-label">件数</span>
              <strong>{detail.successCount} 件</strong>
            </div>
          </div>

          <div className="panel-toolbar panel-toolbar-stacked">
            <div className="filter-grid filter-grid-compact">
              <label>
                社員番号
                <input
                  value={props.form.payrollBatchEmployeeCodeFilter}
                  onChange={(event) => props.actions.onPayrollBatchEmployeeCodeFilterChange(event.target.value)}
                />
              </label>
              <label>
                氏名
                <input
                  value={props.form.payrollBatchEmployeeNameFilter}
                  onChange={(event) => props.actions.onPayrollBatchEmployeeNameFilterChange(event.target.value)}
                />
              </label>
            </div>
            <div className="button-row">
              <button type="button" className="table-action" onClick={() => void props.actions.onSearchPayrollBatchDetail()}>
                検索
              </button>
              <button
                type="button"
                className="table-action secondary"
                onClick={() => {
                  props.actions.onPayrollBatchEmployeeCodeFilterChange("");
                  props.actions.onPayrollBatchEmployeeNameFilterChange("");
                  void props.actions.onOpenPayrollBatchDetail(detail.id);
                }}
              >
                条件解除
              </button>
            </div>
          </div>
        </section>

        <DataTable
          title="取込明細一覧"
          rows={detail.items}
          emptyMessage="明細データはありません"
          columns={[
            { key: "employeeCode", header: "社員番号", render: (row) => row.employeeCode },
            { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
            { key: "grossAmount", header: "支給合計", render: (row) => formatCurrency(row.grossAmount) },
            { key: "deductionAmount", header: "控除合計", render: (row) => formatCurrency(row.deductionAmount) },
            { key: "netAmount", header: "差引支給額", render: (row) => formatCurrency(row.netAmount) },
            {
              key: "action",
              header: "操作",
              render: (row) =>
                row.statementId ? (
                  <div className="button-row">
                    <button type="button" className="table-action" onClick={() => void props.actions.onLoadAdminPayrollDetail(row.statementId!)}>
                      個票詳細
                    </button>
                    <button
                      type="button"
                      className="table-action"
                      onClick={() => void props.actions.onAdminPayrollDownload(row.statementId!, row.originalFileName ?? undefined)}
                    >
                      PDF保存
                    </button>
                  </div>
                ) : (
                  "-"
                ),
            },
          ]}
        />

        <DataTable
          title="取込エラー"
          rows={detail.errors}
          emptyMessage="取込エラーはありません"
          columns={[
            { key: "line", header: "行", render: (row) => row.line },
            { key: "employeeCode", header: "社員番号", render: (row) => row.employeeCode ?? "-" },
            { key: "message", header: "内容", render: (row) => row.message },
          ]}
        />
      </>
    );
  }

  return (
    <section className="stack-section section-enter delay-3">
      {activePanel === "payroll-type" ? (
      <section id="payroll-type" className="panel action-panel payroll-switch-panel anchor-panel">
        <div className="segmented-control" role="tablist" aria-label="明細種別">
          <button
            type="button"
            className={props.form.payrollStatementType === "PAYROLL" ? "segmented-item is-active" : "segmented-item"}
            onClick={() => props.actions.onPayrollStatementTypeChange("PAYROLL")}
          >
            給与明細
          </button>
          <button
            type="button"
            className={props.form.payrollStatementType === "BONUS" ? "segmented-item is-active" : "segmented-item"}
            onClick={() => props.actions.onPayrollStatementTypeChange("BONUS")}
          >
            賞与明細
          </button>
        </div>
      </section>
      ) : null}

      {activePanel !== "payroll-type" ? (
      <section className="payroll-layout">
        <div className="payroll-main stack-section">
          {activePanel === "payroll-history" ? (
          <DataTable
            id="payroll-history"
            title="CSV・PDF履歴"
            rows={props.data.filteredPayrollHistory}
            emptyMessage="履歴はまだありません"
            columns={[
              { key: "createdAt", header: "実行日時", render: (row) => props.formatters.formatDateTime(row.createdAt) },
              { key: "importType", header: "区分", render: (row) => props.formatters.formatImportType(row.importType) },
              { key: "sourceFileName", header: "ファイル", render: (row) => row.sourceFileName },
              { key: "targetPeriod", header: "対象期間", render: (row) => row.targetPeriod ?? "-" },
              { key: "successCount", header: "成功", render: (row) => row.successCount },
              { key: "errorCount", header: "失敗", render: (row) => row.errorCount },
              {
                key: "action",
                header: "操作",
                render: (row) =>
                  row.downloadAvailable ? (
                    <button
                      type="button"
                      className="table-action"
                      onClick={() => void props.actions.onFileHistoryDownload(row.id, row.downloadFileName ?? row.sourceFileName)}
                    >
                      再取得
                    </button>
                  ) : (
                    "-"
                  ),
              },
            ]}
          />
          ) : null}

          {activePanel === "payroll-batches" ? (
          <section id="payroll-batches" className="panel action-panel anchor-panel">
            <div className="panel-header">
              <div>
                <p className="panel-kicker">一覧</p>
                <h3>{props.data.payrollTypeLabel}の取込バッチ</h3>
              </div>
              <span className="panel-meta">{props.data.filteredPayrollBatches.length} 件</span>
            </div>
            <div className="panel-toolbar">
              <label className="compact-field">
                対象月
                <input
                  type="month"
                  value={props.form.payrollBatchTargetMonthFilter}
                  onChange={(event) => props.actions.onPayrollBatchTargetMonthFilterChange(event.target.value)}
                />
              </label>
              <div className="button-row">
                <button type="button" className="table-action secondary" onClick={() => props.actions.onPayrollBatchTargetMonthFilterChange("")}>
                  解除
                </button>
              </div>
            </div>
            {props.form.payrollBatchResult ? <p className="feedback">{props.form.payrollBatchResult}</p> : null}
          </section>
          ) : null}

          {activePanel === "payroll-batches" ? (
          <DataTable
            title="取込バッチ一覧"
            rows={props.data.filteredPayrollBatches}
            emptyMessage="取込バッチはまだありません"
            columns={[
              { key: "targetYearMonth", header: "対象月", render: (row) => row.targetYearMonth },
              { key: "definitionName", header: "データ定義", render: (row) => row.definitionName },
              {
                key: "period",
                header: "対象期間",
                render: (row) => `${props.formatters.formatDateOnly(row.periodStartOn)} 〜 ${props.formatters.formatDateOnly(row.periodEndOn)}`,
              },
              { key: "payDate", header: "支給日", render: (row) => props.formatters.formatMonthDay(row.payDate) },
              { key: "publishDate", header: "公開日", render: (row) => props.formatters.formatMonthDay(row.publishDate) },
              { key: "successCount", header: "件数", render: (row) => row.successCount },
              { key: "status", header: "状態", render: (row) => props.formatters.formatPayrollBatchStatus(row.status) },
              {
                key: "action",
                header: "操作",
                render: (row) => (
                  <div className="button-row">
                    <button type="button" className="table-action" onClick={() => void props.actions.onOpenPayrollBatchDetail(row.id)}>
                      詳細
                    </button>
                    <button
                      type="button"
                      className="table-action"
                      onClick={() =>
                        void props.actions.onExportPayrollBatch(
                          row.id,
                          `${row.statementType === "BONUS" ? "bonus" : "payroll"}_batch_${row.targetYearMonth}.zip`,
                        )
                      }
                    >
                      一括PDF出力
                    </button>
                  </div>
                ),
              },
            ]}
          />
          ) : null}

          {activePanel === "payroll-batch-detail" ? renderPayrollBatchDetailPanels() : null}

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
          <DataTable
            id="payroll-statements"
            title={`公開済み${props.data.payrollTypeLabel}一覧`}
            rows={props.data.filteredPayrollStatements}
            emptyMessage="公開済み明細はまだありません"
            columns={[
              { key: "employeeCode", header: "社員番号", render: (row) => row.employeeCode ?? "-" },
              { key: "employeeName", header: "氏名", render: (row) => row.employeeName ?? "-" },
              { key: "targetYearMonth", header: "対象月", render: (row) => row.targetYearMonth },
              { key: "payDate", header: "支給日", render: (row) => props.formatters.formatMonthDay(row.payDate ?? row.publishedAt) },
              { key: "definitionName", header: "データ定義", render: (row) => row.definitionName ?? "-" },
              { key: "publishedAt", header: "公開日時", render: (row) => props.formatters.formatDateTime(row.publishedAt) },
              {
                key: "action",
                header: "操作",
                render: (row) => (
                  <div className="button-row">
                    <button type="button" className="table-action" onClick={() => void props.actions.onLoadAdminPayrollDetail(row.id)}>
                      個票詳細
                    </button>
                    <button
                      type="button"
                      className="table-action"
                      onClick={() => void props.actions.onAdminPayrollDownload(row.id, row.originalFileName)}
                    >
                      PDF保存
                    </button>
                  </div>
                ),
              },
            ]}
          />
          ) : null}
        </div>

        <div className="payroll-side stack-section">
          {activePanel === "payroll-definitions" ? (
          <section id="payroll-definitions" className="panel action-panel anchor-panel">
            <div className="panel-header">
              <div>
                <p className="panel-kicker">定義</p>
                <h3>データ定義を整える</h3>
              </div>
            </div>
            <div className="definition-list">
              {props.data.filteredPayrollDefinitions.length === 0 ? (
                <p className="compact-empty">データ定義はまだありません。</p>
              ) : (
                props.data.filteredPayrollDefinitions.map((definition) => (
                  <button
                    key={definition.id}
                    type="button"
                    className={props.form.payrollDefinitionId === String(definition.id) ? "definition-chip is-active" : "definition-chip"}
                    onClick={() => props.actions.onPayrollDefinitionSelect(definition)}
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
                <input value={props.form.payrollDefinitionName} onChange={(event) => props.actions.onPayrollDefinitionNameChange(event.target.value)} />
              </label>
              <label className="checkbox-row">
                <input
                  type="checkbox"
                  checked={props.form.payrollDefinitionActive}
                  onChange={(event) => props.actions.onPayrollDefinitionActiveChange(event.target.checked)}
                />
                使用中にする
              </label>
              <div className="button-row">
                <button
                  type="button"
                  className="secondary"
                  onClick={() => void props.actions.onTemplateDownload(props.form.payrollStatementType === "BONUS" ? "bonus" : "payroll")}
                >
                  サンプルCSV
                </button>
                <button type="button" onClick={() => void props.actions.onPayrollDefinitionSave()}>
                  定義を保存
                </button>
              </div>
            </div>
            {props.form.payrollDefinitionResult ? <p className="feedback">{props.form.payrollDefinitionResult}</p> : null}
          </section>
          ) : null}

          {activePanel === "payroll-register" ? (
          <section id="payroll-register" className="panel action-panel anchor-panel">
            <div className="panel-header">
              <div>
                <p className="panel-kicker">登録</p>
                <h3>{props.data.payrollTypeLabel}CSVを登録</h3>
              </div>
            </div>
            <form
              className="stack-form"
              action={async (formData) => {
                formData.set("statementType", props.form.payrollStatementType);
                if (props.form.payrollDefinitionId) {
                  formData.set("definitionId", props.form.payrollDefinitionId);
                }
                await props.actions.onPayrollBatchCreate(formData);
              }}
            >
              <input type="hidden" name="statementType" value={props.form.payrollStatementType} readOnly />
              <div className="form-grid">
                <label>
                  データ定義
                  <select
                    name="definitionId"
                    value={props.form.payrollDefinitionId}
                    onChange={(event) => props.actions.onPayrollDefinitionIdChange(event.target.value)}
                  >
                    <option value="">自動選択</option>
                    {props.data.filteredPayrollDefinitions.map((definition) => (
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
                    value={props.form.payrollTargetYearMonth}
                    onChange={(event) => props.actions.onPayrollTargetYearMonthChange(event.target.value)}
                  />
                </label>
              </div>
              <div className="form-grid form-grid-3">
                <label>
                  対象期間 開始
                  <input
                    name="periodStartOn"
                    type="date"
                    value={props.form.payrollPeriodStartOn}
                    onChange={(event) => props.actions.onPayrollPeriodStartOnChange(event.target.value)}
                  />
                </label>
                <label>
                  対象期間 終了
                  <input
                    name="periodEndOn"
                    type="date"
                    value={props.form.payrollPeriodEndOn}
                    onChange={(event) => props.actions.onPayrollPeriodEndOnChange(event.target.value)}
                  />
                </label>
                <label>
                  支給日
                  <input
                    name="payDate"
                    type="date"
                    value={props.form.payrollPayDate}
                    onChange={(event) => props.actions.onPayrollPayDateChange(event.target.value)}
                  />
                </label>
              </div>
              <div className="form-grid">
                <label>
                  公開日
                  <input
                    name="publishDate"
                    type="date"
                    value={props.form.payrollPublishDate}
                    onChange={(event) => props.actions.onPayrollPublishDateChange(event.target.value)}
                  />
                </label>
                <label>
                  備考
                  <input
                    name="remarks"
                    value={props.form.payrollRemarks}
                    onChange={(event) => props.actions.onPayrollRemarksChange(event.target.value)}
                    placeholder="任意"
                  />
                </label>
              </div>
              <label>
                CSVファイル
                <input name="file" type="file" accept=".csv,text/csv" />
              </label>
              <p className="compact-empty">選択したデータ定義と列名・列順・列数が一致しないCSVは登録できません。</p>
              <button type="submit">CSVを登録する</button>
            </form>
          </section>
          ) : null}

          {activePanel === "payroll-operation" ? (
          <section id="payroll-operation" className="panel action-panel anchor-panel">
            <div className="panel-header">
              <div>
                <p className="panel-kicker">運用</p>
                <h3>PDFはCSVから自動生成</h3>
              </div>
            </div>
            <div className="stack-form">
              <p className="compact-empty">{props.data.payrollTypeLabel}CSVを登録すると、各職員の明細PDFが自動生成されます。</p>
              <p className="compact-empty">
                生成後は「取込バッチ一覧」または{`「公開済み${props.data.payrollTypeLabel}一覧」`}の「PDF保存」から取得してください。
              </p>
            </div>
            {props.form.payrollResult ? <p className="feedback">{props.form.payrollResult}</p> : null}
          </section>
          ) : null}
        </div>
      </section>
      ) : null}
    </section>
  );
}
