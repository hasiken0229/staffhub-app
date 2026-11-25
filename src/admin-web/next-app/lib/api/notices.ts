import type { Notice } from "@/types";
import { fetchJson } from "@/lib/api/core";

export type ManagedNotice = Notice & {
  revision?: number;
  publicationStatus?: "SCHEDULED" | "PUBLISHED" | "ENDED" | "STOPPED";
  notificationStatus?: "UNKNOWN" | "SCHEDULED" | "QUEUED" | "SENT" | "INCOMPLETE" | "CANCELLED";
  stoppedAt?: string | null;
  updatedAt?: string;
};

export type NoticeEditPayload = {
  revision: number; noticeType: string; title: string; body: string;
  publishStartAt: string; publishEndAt?: string;
};

export function loadAdminNotice(id: number) {
  return fetchJson<ManagedNotice>(`/api/admin/notices/${id}`);
}
export function updateAdminNotice(id: number, payload: NoticeEditPayload) {
  return fetchJson<ManagedNotice>(`/api/admin/notices/${id}`, {
    method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload),
  });
}
export function stopAdminNotice(id: number, revision: number) {
  return fetchJson<ManagedNotice>(`/api/admin/notices/${id}/stop`, {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ revision }),
  });
}
