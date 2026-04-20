import { API_BASE_URL, buildHeaders, downloadBlob } from "@/lib/api/core";

export async function downloadMonthlyAttendanceCsv(targetMonth: string) {
  return downloadBlob(`/api/admin/reports/monthly-csv?targetMonth=${encodeURIComponent(targetMonth)}`, `attendance_monthly_${targetMonth}.csv`);
}

export async function downloadDailyAttendanceCsv(from: string, to: string) {
  return downloadBlob(`/api/admin/reports/daily-csv?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, `attendance_daily_${from}_${to}.csv`);
}

export async function downloadDailyAttendancePdf(targetMonth: string) {
  return downloadBlob(`/api/admin/reports/daily-pdf?targetMonth=${encodeURIComponent(targetMonth)}`, `attendance_daily_${targetMonth}.pdf`);
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

  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: "GET",
    headers: buildHeaders(),
  });

  if (!response.ok) {
    throw new Error("テンプレートCSVのダウンロードに失敗しました。");
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download =
    kind === "employees" ? "employees_template.csv" : kind === "bonus" ? "r8.3syoyo.csv" : "r8.3kyuyo.csv";
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

export async function downloadPayrollStatement(id: number, fileName?: string) {
  const response = await fetch(`${API_BASE_URL}/api/payroll/statements/${id}/download`, {
    method: "GET",
    headers: buildHeaders(),
  });

  if (!response.ok) {
    throw new Error("明細PDFのダウンロードに失敗しました。");
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = fileName ?? `statement_${id}.pdf`;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

export async function downloadAdminPayrollStatement(id: number, fileName?: string) {
  return downloadBlob(`/api/admin/payroll/statements/${id}/download`, fileName ?? `statement_${id}.pdf`);
}
