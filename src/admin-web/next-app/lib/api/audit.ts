import type { AuditLog } from "@/types";
import { buildQuery, fetchJsonPage } from "@/lib/api/core";

export async function loadAuditLogsPage(filters?: {
  actor?: string;
  action?: string;
  from?: string;
  to?: string;
  page?: number;
  perPage?: number;
}) {
  return fetchJsonPage<AuditLog>(`/api/admin/audit-logs${buildQuery({ perPage: 100, ...filters })}`);
}
