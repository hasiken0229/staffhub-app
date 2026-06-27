import type { CardAssignment } from "@/types";
import { fetchJson } from "@/lib/api/core";

export async function assignCard(employeeId: number, cardUid: string) {
  return fetchJson<CardAssignment>("/api/admin/cards/assign", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ employeeId, cardUid }),
  });
}

export async function revokeCard(cardId: number) {
  return fetchJson<{ success: boolean }>("/api/admin/cards/revoke", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ cardId }),
  });
}

export async function deleteCard(cardId: number) {
  return fetchJson<{ success: boolean }>("/api/admin/cards/delete", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ cardId }),
  });
}
