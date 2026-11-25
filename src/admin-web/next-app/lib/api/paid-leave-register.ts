import type { Employee } from "@/types";
import type { PaidLeaveRegister } from "@/types/paid-leave-register";
import { buildQuery, fetchJson } from "./core";

export type RegisterEmployee = Pick<Employee, "id" | "employeeCode" | "name" | "status">;
export type RegisterQuery = { employeeId: number; from: string; to: string };

export function loadRegisterEmployees(signal: AbortSignal) {
  return fetchJson<RegisterEmployee[]>("/api/admin/employees", { signal });
}

export function loadPaidLeaveRegister(query: RegisterQuery, signal: AbortSignal) {
  return fetchJson<PaidLeaveRegister>(`/api/admin/reports/paid-leave-register${buildQuery(query)}`, { signal });
}

