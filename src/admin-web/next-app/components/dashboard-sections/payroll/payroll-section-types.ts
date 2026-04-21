import type {
  ImportHistory,
  PayrollDataDefinition,
  PayrollImportBatch,
  PayrollImportBatchDetail,
  PayrollStatement,
  PayrollStatementDetail,
} from "@/types";

export type PayrollSectionProps = {
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
