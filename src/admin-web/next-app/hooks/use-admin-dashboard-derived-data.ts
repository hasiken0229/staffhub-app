import type { DashboardData } from "@/types";

type UseAdminDashboardDerivedDataParams = {
  dashboard: DashboardData;
  payrollStatementType: "PAYROLL" | "BONUS";
  payrollBatchTargetMonthFilter: string;
};

export function useAdminDashboardDerivedData(params: UseAdminDashboardDerivedDataParams) {
  const filteredPayrollDefinitions = params.dashboard.payrollDefinitions.filter(
    (definition) => definition.statementType === params.payrollStatementType,
  );

  const filteredPayrollBatches = params.dashboard.payrollImportBatches.filter((batch) => {
    if (batch.statementType !== params.payrollStatementType) {
      return false;
    }

    if (params.payrollBatchTargetMonthFilter && batch.targetYearMonth !== params.payrollBatchTargetMonthFilter) {
      return false;
    }

    return true;
  });

  const filteredPayrollStatements = params.dashboard.payroll.filter(
    (statement) => statement.statementType === params.payrollStatementType,
  );

  const filteredPayrollHistory = params.dashboard.importHistory.filter((row) =>
    params.payrollStatementType === "PAYROLL"
      ? row.importType === "PAYROLL_CSV" || row.importType === "PAYROLL_PDF_UPLOAD"
      : row.importType === "BONUS_CSV" || row.importType === "BONUS_PDF_UPLOAD",
  );

  const reportFileHistory = params.dashboard.importHistory.filter((row) =>
    ["MONTHLY_PAYROLL_CSV", "MONTHLY_WORKS_PDF", "PAYROLL_CSV", "BONUS_CSV"].includes(row.importType),
  );

  return {
    filteredPayrollDefinitions,
    filteredPayrollBatches,
    filteredPayrollStatements,
    filteredPayrollHistory,
    reportFileHistory,
    payrollTypeLabel: params.payrollStatementType === "PAYROLL" ? "給与" : "賞与",
  };
}
