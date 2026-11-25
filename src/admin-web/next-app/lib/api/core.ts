import type { ApiEnvelope, AuthAudience, CurrentUser, PaginationMeta } from "@/types";

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

  return "https://attendance.example.org/dakoku";
}

export const API_BASE_URL = resolveApiBaseUrl();
const SESSION_TOKEN_KEY = "staffhub-session-token";
const SESSION_REFRESH_TOKEN_KEY = "staffhub-session-refresh-token";
const SESSION_AUDIENCE_KEY = "staffhub-session-audience";
const LEGACY_ADMIN_TOKEN_KEY = "staffhub-admin-token";
let sessionGeneration = 0;
let refreshInFlight: { generation: number; promise: Promise<boolean> } | null = null;

export class ApiRequestError extends Error {
  public readonly fieldErrors: Record<string, string[]>;
  constructor(
    message: string,
    public readonly status: number,
    public readonly code?: string,
    public readonly details: unknown = [],
  ) {
    super(message);
    this.name = "ApiRequestError";
    this.fieldErrors = {};
    if (Array.isArray(details)) {
      for (const item of details) {
        if (item && typeof item.field === "string" && typeof item.message === "string") {
          (this.fieldErrors[item.field] ??= []).push(item.message);
        }
      }
    }
  }
}

function notifyUnauthorized(status: number) {
  if (status === 401 && typeof window !== "undefined") {
    window.dispatchEvent(new Event("staffhub:unauthorized"));
  }
}

function assertSessionGeneration(generation: number) {
  if (generation !== sessionGeneration) throw new ApiRequestError("ログイン状態が変更されたため以前の応答を破棄しました。更新操作の結果は最新の一覧・履歴で確認してください。", 0, "SESSION_CHANGED");
}

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

  return window.sessionStorage.getItem(SESSION_TOKEN_KEY) ?? "";
}

export const EMPLOYEE_NAVIGATION_KEY = "staffhub-employee-navigation";

export function saveSession(token: string, audience: AuthAudience, refreshToken?: string) {
  sessionGeneration += 1;
  if (typeof window !== "undefined") window.sessionStorage.removeItem(EMPLOYEE_NAVIGATION_KEY);
  writeSession(token, audience, refreshToken);
}

function writeSession(token: string, audience: AuthAudience, refreshToken?: string) {
  if (typeof window !== "undefined") {
    window.sessionStorage.setItem(SESSION_TOKEN_KEY, token);
    window.sessionStorage.setItem(SESSION_AUDIENCE_KEY, audience);
    if (refreshToken) {
      window.sessionStorage.setItem(SESSION_REFRESH_TOKEN_KEY, refreshToken);
    } else window.sessionStorage.removeItem(SESSION_REFRESH_TOKEN_KEY);
    // Do not carry sessions over from older versions that used persistent storage.
    window.localStorage.removeItem(SESSION_TOKEN_KEY);
    window.localStorage.removeItem(SESSION_REFRESH_TOKEN_KEY);
    window.localStorage.removeItem(SESSION_AUDIENCE_KEY);
    window.localStorage.removeItem(LEGACY_ADMIN_TOKEN_KEY);
  }
}

export function clearAdminToken() {
  sessionGeneration += 1;
  if (typeof window !== "undefined") {
    window.sessionStorage.removeItem(EMPLOYEE_NAVIGATION_KEY);
    window.sessionStorage.removeItem(SESSION_TOKEN_KEY);
    window.sessionStorage.removeItem(SESSION_REFRESH_TOKEN_KEY);
    window.sessionStorage.removeItem(SESSION_AUDIENCE_KEY);
    window.localStorage.removeItem(SESSION_TOKEN_KEY);
    window.localStorage.removeItem(SESSION_REFRESH_TOKEN_KEY);
    window.localStorage.removeItem(SESSION_AUDIENCE_KEY);
    window.localStorage.removeItem(LEGACY_ADMIN_TOKEN_KEY);
  }
}

export function loadAdminToken() {
  return getSessionToken();
}

export function loadRefreshToken() {
  if (typeof window === "undefined") {
    return "";
  }

  return window.sessionStorage.getItem(SESSION_REFRESH_TOKEN_KEY) ?? "";
}

export function loadSessionAudience(): AuthAudience | "" {
  if (typeof window === "undefined") {
    return "";
  }

  const audience = window.sessionStorage.getItem(SESSION_AUDIENCE_KEY);
  if (audience === "ADMIN" || audience === "EMPLOYEE") {
    return audience;
  }

  return getSessionToken() ? "ADMIN" : "";
}

export function buildHeaders(init?: HeadersInit) {
  const headers = new Headers(init);
  if (!headers.has("Accept")) headers.set("Accept", "application/json");
  const token = getSessionToken();
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }

  return headers;
}

function withPagination(path: string, page: number, perPage: number) {
  const [pathname, query = ""] = path.split("?", 2);
  const params = new URLSearchParams(query);
  params.set("page", String(page));
  params.set("perPage", String(perPage));
  return `${pathname}?${params.toString()}`;
}

async function fetchApiEnvelope<T>(path: string, init?: RequestInit) {
  const generation = sessionGeneration;
  let response: Response;
  try { response = await fetchWithSessionRefresh(path, init); }
  catch (error) {
    if (error instanceof ApiRequestError) throw error;
    const mutation = !["GET", "HEAD"].includes((init?.method ?? "GET").toUpperCase());
    throw new ApiRequestError(mutation
      ? "通信が途切れたため処理結果を確認できませんでした。再実行する前に一覧・取込履歴を確認してください。"
      : "サーバーに接続できませんでした。通信状況を確認して再読込してください。", 0, mutation ? "UNKNOWN_RESULT" : "NETWORK_ERROR");
  }
  assertSessionGeneration(generation);

  const contentType = response.headers.get("Content-Type")?.toLowerCase() ?? "";
  if (!response.ok) {
    if (!path.startsWith("/api/auth/password/")) notifyUnauthorized(response.status);
    const body = contentType.includes("json") ? await response.json().catch(() => null) : null;
    assertSessionGeneration(generation);
    throw new ApiRequestError(body?.error?.message ?? responseErrorMessage(response.status), response.status, body?.error?.code, body?.error?.details);
  }
  if (!contentType.includes("json")) {
    throw new ApiRequestError("サーバーから想定外の形式で応答が返りました。更新操作の場合は履歴を確認してください。", response.status, "INVALID_RESPONSE");
  }
  const json = await response.json().catch(() => { throw new ApiRequestError("サーバー応答を読み取れませんでした。更新操作の場合は履歴を確認してください。", response.status, "INVALID_RESPONSE"); }) as ApiEnvelope<T>;
  assertSessionGeneration(generation);

  return json;
}

async function fetchWithSessionRefresh(path: string, init?: RequestInit, retryAfterRefresh = true): Promise<Response> {
  const generation = sessionGeneration;
  const assertCurrentSession = () => assertSessionGeneration(generation);
  let sentToken = "";
  const request = () => {
    const headers = buildHeaders(init?.headers);
    sentToken = headers.get("Authorization") ?? "";
    return fetch(`${API_BASE_URL}${path}`, {
      ...init,
      cache: "no-store",
      headers,
    });
  };

  let response = await request();
  assertCurrentSession();
  const pathname = path.split("?", 1)[0];
  const excludesRefresh = ["/api/auth/login", "/api/auth/refresh", "/api/auth/logout",
    "/api/auth/password/forgot", "/api/auth/password/reset", "/api/auth/password/initial"].includes(pathname);
  if (response.status !== 401 || !retryAfterRefresh || excludesRefresh) {
    return response;
  }

  // A parallel request may already have rotated the session before this 401 arrived.
  const currentToken = getSessionToken();
  const refreshed = (currentToken && sentToken !== `Bearer ${currentToken}`) || await refreshSession();
  assertCurrentSession();
  if (refreshed) {
    response = await request();
    assertCurrentSession();
  }

  return response;
}

async function refreshSession(): Promise<boolean> {
  const refreshToken = loadRefreshToken();
  const audience = loadSessionAudience();
  if (!refreshToken || !audience) {
    return false;
  }

  const generation = sessionGeneration;
  if (refreshInFlight?.generation === generation) {
    return refreshInFlight.promise;
  }

  const flight = { generation, promise: Promise.resolve(false) };
  refreshInFlight = flight;
  flight.promise = (async () => {
    try {
      const response = await fetch(`${API_BASE_URL}/api/auth/refresh`, {
        method: "POST",
        cache: "no-store",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ refreshToken }),
      });
      const contentType = response.headers.get("Content-Type") ?? response.headers.get("content-type") ?? "";
      if (response.status === 401 && contentType.toLowerCase().includes("json")) return false;
      if (!response.ok || !contentType.toLowerCase().includes("json")) {
        throw new Error("Session refresh is temporarily unavailable");
      }

      const json = (await response.json()) as ApiEnvelope<LoginSession>;
      const nextSession = json.data;
      if (!nextSession?.accessToken || !nextSession.refreshToken || nextSession.user?.role !== audience) {
        throw new Error("Invalid session refresh response");
      }

      if (generation !== sessionGeneration || loadRefreshToken() !== refreshToken || loadSessionAudience() !== audience) return false;
      // Token rotation stays within the same login generation; logout/new login invalidates it.
      writeSession(nextSession.accessToken, audience, nextSession.refreshToken);
      return true;
    } catch {
      assertSessionGeneration(generation);
      throw new ApiRequestError("ログイン状態を更新できませんでした。通信が復旧したら再試行してください。", 503, "SESSION_REFRESH_UNAVAILABLE");
    } finally {
      if (refreshInFlight === flight) refreshInFlight = null;
    }
  })();

  return flight.promise;
}

export async function fetchJson<T>(path: string, init?: RequestInit): Promise<T> {
  const first = await fetchApiEnvelope<T>(path, init);
  const total = first.meta?.total ?? 0;
  const query = new URLSearchParams(path.split("?", 2)[1] ?? "");
  const firstPage = Array.isArray(first.data) ? first.data : null;
  const canLoadAllPages = firstPage !== null && !query.has("page") && !query.has("perPage");

  if (!canLoadAllPages || total <= (firstPage?.length ?? 0)) {
    return first.data;
  }

  const perPage = 100;
  const pageCount = Math.ceil(total / perPage);
  const pages = await Promise.all(
    Array.from({ length: pageCount }, (_, index) =>
      fetchApiEnvelope<unknown[]>(withPagination(path, index + 1, perPage), init),
    ),
  );

  return pages.flatMap((page) => page.data) as T;
}

export async function fetchJsonPage<T>(path: string, init?: RequestInit): Promise<{ items: T[]; meta: PaginationMeta }> {
  const response = await fetchApiEnvelope<T[]>(path, init);

  return {
    items: response.data,
    meta: {
      page: response.meta?.page ?? 1,
      perPage: response.meta?.perPage ?? response.data.length,
      total: response.meta?.total ?? response.data.length,
    },
  };
}

export async function fetchJsonOptional<T>(path: string, fallback: T, init?: RequestInit): Promise<T> {
  try {
    return await fetchJson<T>(path, init);
  } catch (error) {
    // These endpoints are optional only while a server is being upgraded.
    // A real network/server failure must remain visible instead of looking
    // like a successful empty result ("データがありません").
    if (error instanceof ApiRequestError && error.status === 404) {
      return fallback;
    }

    throw error;
  }
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

function responseErrorMessage(status: number) {
  if (status === 401) return "ログインの有効期限が切れました。再度ログインしてください。";
  if (status === 403) return "この操作の権限がありません。";
  if (status === 422) return "入力内容を確認してください。";
  if (status === 429) return "アクセスが集中しています。しばらくしてから再試行してください。";
  return `サーバーで処理できませんでした（HTTP ${status}）。更新操作の場合は再実行する前に履歴を確認してください。`;
}

export async function validateDownload(blob: Blob, fileName: string) {
  if (blob.size === 0) throw new Error("空のファイルが返されたため保存を中止しました。");
  const head = new Uint8Array(await blob.slice(0, 512).arrayBuffer());
  const text = new TextDecoder().decode(head).trimStart();
  if (blob.type.includes("html") || blob.type.includes("json") || /^<(?:!doctype|html)/i.test(text)) {
    throw new Error("ファイルではなくエラー応答が返されたため保存を中止しました。");
  }
  if (/\.pdf$/i.test(fileName) && (!blob.type.includes("pdf") || !text.startsWith("%PDF-"))) {
    throw new Error("正常なPDFではないため保存を中止しました。");
  }
  if (/\.zip$/i.test(fileName) && !(head[0] === 0x50 && head[1] === 0x4b && [3, 5, 7].includes(head[2]))) {
    throw new Error("正常なZIPではないため保存を中止しました。");
  }
}

export async function downloadBlob(path: string, fileName?: string) {
  const generation = sessionGeneration;
  const response = await fetchWithSessionRefresh(path, { method: "GET", headers: { Accept: "application/pdf, application/zip, text/csv, application/octet-stream, application/json" } });
  assertSessionGeneration(generation);
  if (!response.ok) {
    notifyUnauthorized(response.status);
    const body = response.headers.get("Content-Type")?.includes("json") ? await response.json().catch(() => null) : null;
    throw new ApiRequestError(body?.error?.message ?? responseErrorMessage(response.status), response.status, body?.error?.code);
  }
  const disposition = response.headers.get("Content-Disposition") ?? "";
  const encodedName = /filename\*=UTF-8''([^;]+)/i.exec(disposition)?.[1];
  let serverName = /filename="?([^";]+)"?/i.exec(disposition)?.[1];
  if (encodedName) { try { serverName = decodeURIComponent(encodedName); } catch { /* use plain filename */ } }
  const name = (fileName ?? serverName ?? "download").replace(/[\\/\r\n]/g, "_");
  const blob = await response.blob();
  await validateDownload(blob, name);
  assertSessionGeneration(generation);
  return saveDownloadBlob(blob, name);
}

export function saveDownloadBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = name;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  // Keep the object alive while the browser accepts the download.
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
  window.dispatchEvent(new CustomEvent("staffhub:download-started", { detail: { fileName: name } }));
  return { status: "download_started" as const, fileName: name, size: blob.size, contentType: blob.type };
}

export type LoginSession = {
  accessToken: string;
  refreshToken: string;
  tokenType?: string;
  expiresIn?: number;
  refreshExpiresIn?: number;
  user: CurrentUser;
};
export type InitialPasswordChallenge = {
  requiresPasswordChange: true;
  passwordChangeToken: string;
  expiresInSeconds: number;
};
export type LoginPayload = ApiEnvelope<LoginSession | InitialPasswordChallenge> & {
  error?: { message?: string };
};
