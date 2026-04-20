import type { CardAssignment } from "@/types";
import { fetchJson } from "@/lib/api/core";

export async function assignCard(employeeId: number, cardUid: string) {
  return fetchJson<CardAssignment>("/api/admin/cards/assign", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ employeeId, cardUid }),
  });
}
