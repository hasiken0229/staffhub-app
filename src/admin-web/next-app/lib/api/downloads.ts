import { buildQuery, downloadBlob } from "@/lib/api/core";
export type AttendanceExportFilters = { employeeCode?: string; departmentName?: string };

export async function downloadMonthlyAttendanceCsv(targetMonth: string, filters: AttendanceExportFilters = {}) {
  return downloadBlob(`/api/admin/reports/monthly-csv${buildQuery({targetMonth,...filters})}`, `attendance_monthly_${targetMonth}.csv`);
}

export async function downloadDailyAttendanceCsv(from: string, to: string, filters: AttendanceExportFilters = {}) {
  return downloadBlob(`/api/admin/reports/daily-csv${buildQuery({from,to,...filters})}`, `attendance_daily_${from}_${to}.csv`);
}

export async function downloadDailyAttendancePdf(targetMonth: string, filters: AttendanceExportFilters = {}) {
  return downloadBlob(`/api/admin/reports/daily-pdf${buildQuery({targetMonth,...filters})}`, `attendance_daily_${targetMonth}.pdf`);
}

export async function downloadMonthlyPayrollCsv(targetMonth: string) {
  return downloadBlob(
    `/api/admin/reports/monthly-payroll-csv?targetMonth=${encodeURIComponent(targetMonth)}`,
    `給与用_${targetMonth}.csv`,
  );
}

export async function downloadMonthlyWorksPdf(employeeId: number, targetMonth: string, fileName?: string) {
  return downloadBlob(
    `/api/admin/reports/monthly-works-pdf?employeeId=${employeeId}&targetMonth=${encodeURIComponent(targetMonth)}`,
    fileName ?? `works_${employeeId}_${targetMonth}.pdf`,
  );
}

export async function downloadFileHistory(id: number, fileName?: string) {
  return downloadBlob(`/api/admin/files/history/${id}/download`, fileName);
}

export async function downloadAdminCsvTemplate(kind: "employees" | "payroll" | "bonus") {
  const path =
    kind === "employees"
      ? "/api/admin/employees/template-csv"
      : `/api/admin/payroll/definitions/template-csv?statementType=${kind === "bonus" ? "BONUS" : "PAYROLL"}`;
  const fileName = kind === "employees" ? "employees_template.csv" : kind === "bonus" ? "sample-bonus.csv" : "sample-payroll.csv";
  return downloadBlob(path, fileName);
}

export async function downloadPayrollStatement(id: number, fileName?: string) {
  return downloadBlob(`/api/payroll/statements/${id}/download`, fileName ?? `statement_${id}.pdf`);
}

export async function downloadAdminPayrollStatement(id: number, fileName?: string) {
  return downloadBlob(`/api/admin/payroll/statements/${id}/download`, fileName ?? `statement_${id}.pdf`);
}
