import type { AuditLog } from "@/types";
import { buildQuery, fetchJson } from "@/lib/api/core";

export async function loadAuditLogs(filters?: {
  actor?: string;
  action?: string;
  from?: string;
  to?: string;
  page?: number;
  perPage?: number;
}) {
  return fetchJson<AuditLog[]>(`/api/admin/audit-logs${buildQuery(filters ?? {})}`);
}
