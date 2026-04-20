import type {
  PayrollDataDefinition,
  PayrollImportBatch,
  PayrollImportBatchDetail,
  PayrollImportResult,
  PayrollStatement,
  PayrollStatementDetail,
} from "@/types";
import { buildQuery, downloadBlob, fetchJson } from "@/lib/api/core";

export async function uploadPayroll(formData: FormData) {
  return fetchJson<PayrollStatement>("/api/admin/payroll/statements", {
    method: "POST",
    body: formData,
  });
}

export async function importPayrollCsv(formData: FormData) {
  return fetchJson<PayrollImportResult>("/api/admin/payroll/import-csv", {
    method: "POST",
    body: formData,
  });
}

export async function loadPayrollDefinitions(statementType?: "PAYROLL" | "BONUS") {
  return fetchJson<PayrollDataDefinition[]>(`/api/admin/payroll/definitions${buildQuery({ statementType })}`);
}

export async function savePayrollDefinition(payload: {
  id?: number;
  statementType: "PAYROLL" | "BONUS";
  definitionName: string;
  isActive?: boolean;
}) {
  return fetchJson<PayrollDataDefinition>("/api/admin/payroll/definitions", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
}

export async function loadPayrollImportBatches(filters?: {
  statementType?: "PAYROLL" | "BONUS";
  targetYearMonth?: string;
}) {
  return fetchJson<PayrollImportBatch[]>(`/api/admin/payroll/import-batches${buildQuery(filters ?? {})}`);
}

export async function loadPayrollImportBatchDetail(id: number, filters?: {
  employeeCode?: string;
  employeeName?: string;
}) {
  return fetchJson<PayrollImportBatchDetail>(`/api/admin/payroll/import-batches/${id}${buildQuery(filters ?? {})}`);
}

export async function createPayrollImportBatch(formData: FormData) {
  return fetchJson<PayrollImportResult>("/api/admin/payroll/import-batches", {
    method: "POST",
    body: formData,
  });
}

export async function deletePayrollImportBatch(id: number) {
  return fetchJson<{ id: number; deleted: boolean }>(`/api/admin/payroll/import-batches/${id}`, {
    method: "DELETE",
  });
}

export async function exportPayrollImportBatchPdf(id: number, fileName?: string) {
  return downloadBlob(`/api/admin/payroll/import-batches/${id}/export-pdf`, fileName ?? `payroll_batch_${id}.zip`);
}

export async function loadAdminPayrollStatementDetail(id: number) {
  return fetchJson<PayrollStatementDetail>(`/api/admin/payroll/statements/${id}`);
}

export async function loadEmployeePayrollStatementDetail(id: number) {
  return fetchJson<PayrollStatementDetail>(`/api/payroll/statements/${id}`);
}

export async function deletePayrollStatement(id: number) {
  return fetchJson<{ id: number; deleted: boolean }>(`/api/admin/payroll/statements/${id}`, {
    method: "DELETE",
  });
}
