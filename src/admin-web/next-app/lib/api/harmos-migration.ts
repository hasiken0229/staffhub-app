import type { HarmosMigrationResult } from "@/types";
import { fetchJson } from "@/lib/api/core";

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
