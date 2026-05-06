import type { AuthAudience, CurrentUser } from "@/types";
import { API_BASE_URL, fetchJson, saveSession, type LoginPayload } from "@/lib/api/core";

export async function loginPortal(loginId: string, password: string, audience?: AuthAudience) {
  const response = await fetch(`${API_BASE_URL}/api/auth/login`, {
    method: "POST",
    cache: "no-store",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(audience ? { loginId, password, audience } : { loginId, password }),
  });

  const contentType = response.headers.get("Content-Type") ?? response.headers.get("content-type") ?? "";
  if (!contentType.toLowerCase().includes("json")) {
    throw new Error("ログインAPIの応答が不正です。公開URL設定と www あり/なしの差異を確認してください。");
  }

  const json = (await response.json()) as LoginPayload;
  if (!response.ok) {
    throw new Error(json.error?.message ?? "ログインに失敗しました。");
  }

  const accessToken = json.data.accessToken;
  saveSession(accessToken, json.data.user.role);
  return json.data;
}

export async function loginAdmin(loginId: string, password: string) {
  return loginPortal(loginId, password, "ADMIN");
}

export async function loginEmployee(loginId: string, password: string) {
  return loginPortal(loginId, password, "EMPLOYEE");
}

export async function loadCurrentUser() {
  return fetchJson<CurrentUser>("/api/auth/me");
}

export async function requestPasswordReset(email: string) {
  return fetchJson<{ success: boolean }>("/api/auth/password/forgot", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ email }),
  });
}

export async function resetPassword(email: string, token: string, password: string, passwordConfirmation: string) {
  return fetchJson<{ success: boolean }>("/api/auth/password/reset", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      email,
      token,
      password,
      passwordConfirmation,
    }),
  });
}
