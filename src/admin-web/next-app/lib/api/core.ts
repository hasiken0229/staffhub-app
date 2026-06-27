import type { ApiEnvelope, AuthAudience, CurrentUser } from "@/types";

function resolveApiBaseUrl() {
  const configured = process.env.NEXT_PUBLIC_API_BASE_URL?.trim();
  if (configured) {
    return configured.replace(/\/$/, "");
  }

  if (typeof window !== "undefined") {
    const originUrl = new URL(window.location.origin);
    if (originUrl.hostname.startsWith("www.")) {
      originUrl.hostname = originUrl.hostname.replace(/^www\./, "");
    }

    return `${originUrl.origin}/dakoku`;
  }

  return "https://ikegami-wakaba.jp/dakoku";
}

export const API_BASE_URL = resolveApiBaseUrl();
const SESSION_TOKEN_KEY = "staffhub-session-token";
const SESSION_AUDIENCE_KEY = "staffhub-session-audience";
const LEGACY_ADMIN_TOKEN_KEY = "staffhub-admin-token";

export function buildQuery(params: Record<string, string | number | undefined | null>) {
  const search = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === "") {
      return;
    }

    search.set(key, String(value));
  });

  const query = search.toString();
  return query ? `?${query}` : "";
}

export function toDateValue(date: Date) {
  const year = date.getFullYear();
  const month = `${date.getMonth() + 1}`.padStart(2, "0");
  const day = `${date.getDate()}`.padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export function toMonthValue(date: Date) {
  const year = date.getFullYear();
  const month = `${date.getMonth() + 1}`.padStart(2, "0");
  return `${year}-${month}`;
}

function getSessionToken() {
  if (typeof window === "undefined") {
    return "";
  }

  return window.localStorage.getItem(SESSION_TOKEN_KEY) ?? window.localStorage.getItem(LEGACY_ADMIN_TOKEN_KEY) ?? "";
}

export function saveSession(token: string, audience: AuthAudience) {
  if (typeof window !== "undefined") {
    window.localStorage.setItem(SESSION_TOKEN_KEY, token);
    window.localStorage.setItem(SESSION_AUDIENCE_KEY, audience);
    window.localStorage.removeItem(LEGACY_ADMIN_TOKEN_KEY);
  }
}

export function clearAdminToken() {
  if (typeof window !== "undefined") {
    window.localStorage.removeItem(SESSION_TOKEN_KEY);
    window.localStorage.removeItem(SESSION_AUDIENCE_KEY);
    window.localStorage.removeItem(LEGACY_ADMIN_TOKEN_KEY);
  }
}

export function loadAdminToken() {
  return getSessionToken();
}

export function loadSessionAudience(): AuthAudience | "" {
  if (typeof window === "undefined") {
    return "";
  }

  const audience = window.localStorage.getItem(SESSION_AUDIENCE_KEY);
  if (audience === "ADMIN" || audience === "EMPLOYEE") {
    return audience;
  }

  return getSessionToken() ? "ADMIN" : "";
}

export function buildHeaders(init?: HeadersInit) {
  const headers = new Headers(init);
  const token = getSessionToken();
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }

  return headers;
}

export async function fetchJson<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    cache: "no-store",
    headers: buildHeaders(init?.headers),
  });

  const contentType = response.headers.get("Content-Type") ?? response.headers.get("content-type") ?? "";
  if (!contentType.toLowerCase().includes("json")) {
    const text = await response.text();
    const htmlLike = text.includes("<!DOCTYPE") || text.includes("<html");
    if (htmlLike) {
      throw new Error("API応答がHTMLでした。www あり/なしのURL差異か公開設定を確認してください。");
    }

    throw new Error("API応答の形式が想定と異なります。");
  }

  const json = (await response.json()) as ApiEnvelope<T> & {
    error?: { message?: string };
  };

  if (!response.ok) {
    throw new Error(json.error?.message ?? "API呼び出しに失敗しました。");
  }

  return json.data;
}

export async function fetchJsonOptional<T>(path: string, fallback: T, init?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    cache: "no-store",
    headers: buildHeaders(init?.headers),
  });

  const contentType = response.headers.get("Content-Type") ?? response.headers.get("content-type") ?? "";
  if (!contentType.toLowerCase().includes("json")) {
    return fallback;
  }

  const json = (await response.json()) as ApiEnvelope<T> & {
    error?: { message?: string };
  };

  if (!response.ok) {
    return fallback;
  }

  return json.data;
}

export async function fetchHealthStatus(): Promise<string> {
  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}/up`, {
      cache: "no-store",
    });
  } catch {
    return "error";
  }

  return response.ok ? "ok" : "error";
}

export async function downloadBlob(path: string, fileName?: string) {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: "GET",
    headers: buildHeaders(),
  });

  if (!response.ok) {
    const contentType = response.headers.get("Content-Type") ?? response.headers.get("content-type") ?? "";
    if (contentType.toLowerCase().includes("json")) {
      const json = (await response.json().catch(() => null)) as (ApiEnvelope<unknown> & { error?: { message?: string } }) | null;
      throw new Error(json?.error?.message ?? `ファイルのダウンロードに失敗しました。(${response.status})`);
    }

    const text = await response.text().catch(() => "");
    const htmlLike = text.includes("<!DOCTYPE") || text.includes("<html");
    if (htmlLike) {
      throw new Error("API応答がHTMLでした。www あり/なしのURL差異か公開設定を確認してください。");
    }

    throw new Error(text.trim() || `ファイルのダウンロードに失敗しました。(${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = fileName ?? "download";
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

export type LoginPayload = ApiEnvelope<{
  accessToken: string;
  refreshToken: string;
  tokenType?: string;
  expiresInSeconds?: number;
  user: CurrentUser;
}> & {
  error?: { message?: string };
};
