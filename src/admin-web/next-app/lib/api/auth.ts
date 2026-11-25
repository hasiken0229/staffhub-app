import type { AuthAudience, CurrentUser } from "@/types";
import loginMessages from "@/lib/login-messages.json";
import { API_BASE_URL, ApiRequestError, clearAdminToken, fetchJson, saveSession, type LoginPayload, type LoginSession } from "@/lib/api/core";

export async function loginPortal(loginId: string, password: string, audience?: AuthAudience, shouldSaveSession?: () => boolean) {
  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}/api/auth/login`, {
      method: "POST",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
      },
      body: JSON.stringify(audience ? { loginId, password, audience } : { loginId, password }),
    });
  } catch {
    throw new ApiRequestError(loginMessages.offlineLogin, 0, "NETWORK_ERROR");
  }

  const contentType = response.headers.get("Content-Type") ?? response.headers.get("content-type") ?? "";
  if (!contentType.toLowerCase().includes("json")) {
    throw new ApiRequestError(response.status === 429 ? "アクセスが集中しています。しばらくしてから再試行してください。" : response.status >= 500 ? "ログインサーバーで障害が発生しています。しばらくしてから再試行してください。" : "ログインの応答形式を確認できませんでした。接続先またはサーバーの設定を確認してください。", response.status, "INVALID_RESPONSE");
  }

  const json = (await response.json().catch(() => {
    throw new ApiRequestError("ログインの応答を読み取れませんでした。もう一度ログインしてください。", response.status, "INVALID_RESPONSE");
  })) as LoginPayload;
  if (!response.ok) {
    if (response.status === 401) throw new ApiRequestError(loginMessages.invalidCredentials, 401, "INVALID_CREDENTIALS");
    throw new Error(json.error?.message ?? "ログインに失敗しました。");
  }

  if (json.data && "requiresPasswordChange" in json.data) {
    if (!json.data.requiresPasswordChange || !json.data.passwordChangeToken || !(json.data.expiresInSeconds > 0)) throw new Error("初回パスワード変更の応答を確認できませんでした。もう一度ログインしてください。");
    if (shouldSaveSession?.() ?? true) clearAdminToken();
    return json.data;
  }
  return acceptLoginSession(json.data, shouldSaveSession);
}

function acceptLoginSession(session: LoginSession, shouldSave?: () => boolean) {
  if (!session?.accessToken || !session.refreshToken || !session.user?.role) throw new Error("ログインの応答を確認できませんでした。もう一度ログインしてください。");
  if (shouldSave?.() ?? true) saveSession(session.accessToken, session.user.role, session.refreshToken);
  return session;
}

export async function changeInitialPassword(passwordChangeToken: string, password: string, passwordConfirmation: string, shouldSave?: () => boolean) {
  const session = await fetchJson<LoginSession>("/api/auth/password/initial", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ passwordChangeToken, password, passwordConfirmation }),
  });
  return acceptLoginSession(session, shouldSave);
}

export async function loginAdmin(loginId: string, password: string) {
  return loginPortal(loginId, password, "ADMIN");
}

export async function loginEmployee(loginId: string, password: string) {
  return loginPortal(loginId, password, "EMPLOYEE");
}

export async function logoutPortal() {
  await fetchJson<{ success: boolean }>("/api/auth/logout", {
    method: "POST",
    // Keep sending the revocation request while the browser moves back to the
    // login page. Local session cleanup still happens even if it cannot send.
    keepalive: true,
  });
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
