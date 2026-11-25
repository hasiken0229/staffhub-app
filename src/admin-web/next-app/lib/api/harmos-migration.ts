import type { HarmosMigrationResult, ImportHistory } from "@/types";
import { buildQuery, fetchJson, fetchJsonPage } from "@/lib/api/core";

export async function previewHarmosMigration(formData: FormData) {
  return fetchJson<HarmosMigrationResult>("/api/admin/harmos-migration/preview", {
    method: "POST",
    body: formData,
  });
}

export async function importHarmosMigration(formData: FormData) {
  return fetchJson<HarmosMigrationResult>("/api/admin/harmos-migration/import", {
    method: "POST",
    body: formData,
  });
}

export function loadHarmosArchivePage(page: number, perPage: number, targetPeriod: string, signal: AbortSignal) {
  const query = buildQuery({ importType: "HARMOS_SOURCE_ARCHIVE", page, perPage, targetPeriod });
  return fetchJsonPage<ImportHistory>('/api/admin/files/history' + query, { signal });
}
