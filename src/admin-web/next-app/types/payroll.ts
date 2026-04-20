export type PayrollStatement = {
  id: number;
  employeeId: number;
  employeeCode?: string;
  employeeName?: string;
  statementType?: string;
  statementTypeLabel?: string;
  targetYearMonth: string;
  payDate?: string | null;
  periodStartOn?: string | null;
  periodEndOn?: string | null;
  definitionName?: string | null;
  importBatchId?: number | null;
  originalFileName: string;
  publishedAt?: string | null;
  fileSizeBytes?: number | null;
  viewCount?: number;
  lastViewedAt?: string | null;
  viewed?: boolean;
  downloadUrl?: string;
  previewUrl?: string;
  expiresAt?: string;
  remarks?: string | null;
};

export type PayrollStatementLine = {
  id: number;
  sectionType: string;
  sectionLabel: string;
  displayOrder: number;
  itemLabel: string;
  amount: number;
  formattedAmount: string;
  rawSourceKey?: string | null;
};

export type PayrollStatementDetail = PayrollStatement & {
  statementType: string;
  statementTypeLabel: string;
  employeeCode: string;
  employeeName: string;
  grossAmount: number;
  deductionAmount: number;
  netAmount: number;
  deleteAvailable?: boolean;
  legacyMode: boolean;
  lines: PayrollStatementLine[];
  sections: {
    pay: PayrollStatementLine[];
    deduction: PayrollStatementLine[];
    summary: PayrollStatementLine[];
    other: PayrollStatementLine[];
  };
};

export type PayrollDataDefinition = {
  id: number;
  statementType: string;
  statementTypeLabel: string;
  definitionName: string;
  templateVersion: number;
  fieldCount: number;
  sampleFileName?: string | null;
  sampleHeaders: string[];
  isActive: boolean;
  createdAt: string;
};

export type PayrollImportBatch = {
  id: number;
  statementType: string;
  statementTypeLabel: string;
  definitionName: string;
  targetYearMonth: string;
  periodStartOn: string;
  periodEndOn: string;
  payDate: string;
  publishDate: string;
  sourceFileName: string;
  processedCount: number;
  successCount: number;
  errorCount: number;
  status: string;
  createdAt: string;
};

export type PayrollImportBatchItem = {
  id: number;
  employeeId: number;
  employeeCode: string;
  employeeName: string;
  grossAmount: number;
  deductionAmount: number;
  netAmount: number;
  statementId?: number | null;
  lineNo: number;
  originalFileName?: string | null;
  deleted?: boolean;
};

export type PayrollImportError = {
  line: number;
  employeeCode?: string | null;
  message: string;
};

export type PayrollImportBatchDetail = PayrollImportBatch & {
  templateVersion: number;
  fieldCount: number;
  items: PayrollImportBatchItem[];
  errors: PayrollImportError[];
};

export type PayrollImportResult = {
  batchId?: number;
  statementType: string;
  statementTypeLabel: string;
  definitionId?: number;
  definitionName?: string;
  targetYearMonth: string;
  periodStartOn?: string;
  periodEndOn?: string;
  payDate?: string;
  publishDate?: string;
  processedCount: number;
  importedCount: number;
  errorCount: number;
  items?: Array<{
    line: number;
    employeeId: number;
    employeeCode: string;
    employeeName: string;
    statementId: number;
  }>;
  errors?: PayrollImportError[];
};
