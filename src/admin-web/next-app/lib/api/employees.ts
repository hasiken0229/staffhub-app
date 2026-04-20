import type { Employee, EmployeeImportResult, EmployeeUpdatePayload } from "@/types";
import { fetchJson } from "@/lib/api/core";

export async function importEmployeesCsv(formData: FormData) {
  return fetchJson<EmployeeImportResult>("/api/admin/employees/import-csv", {
    method: "POST",
    body: formData,
  });
}

export async function updateEmployee(id: number, payload: EmployeeUpdatePayload) {
  return fetchJson<Employee>(`/api/admin/employees/${id}`, {
    method: "PUT",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });
}
