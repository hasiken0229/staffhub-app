import { afterEach, expect, it, vi } from "vitest";
import { clearAdminToken, fetchJson, loadAdminToken, loadRefreshToken, saveSession } from "@/lib/api/core";

const json = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), {
  status, headers: { "Content-Type": "application/json" },
});
const rotated = () => json({ accessToken: "renewed", refreshToken: "next-refresh", user: { role: "ADMIN" } });
afterEach(() => { clearAdminToken(); vi.unstubAllGlobals(); vi.restoreAllMocks(); });

it("restores the current user with a valid refresh token after reloading an expired session", async () => {
  saveSession("expired", "ADMIN", "valid-refresh");
  const fetchMock = vi.fn().mockResolvedValueOnce(json(null, 401)).mockResolvedValueOnce(rotated())
    .mockResolvedValueOnce(json({ id: 1, role: "ADMIN" }));
  vi.stubGlobal("fetch", fetchMock);
  await expect(fetchJson("/api/auth/me")).resolves.toEqual({ id: 1, role: "ADMIN" });
  expect(fetchMock.mock.calls[1][0]).toMatch(/\/api\/auth\/refresh$/);
  expect(new Headers(fetchMock.mock.calls[2][1].headers).get("Authorization")).toBe("Bearer renewed");
  expect(loadRefreshToken()).toBe("next-refresh");
});

it("shares one refresh across current-user and dashboard startup requests", async () => {
  saveSession("expired", "ADMIN", "valid-refresh");
  let complete!: (value: Response) => void;
  const pending = new Promise<Response>((resolve) => { complete = resolve; });
  const fetchMock = vi.fn((url: string, init: RequestInit) => {
    if (url.endsWith("/api/auth/refresh")) return pending;
    return Promise.resolve(new Headers(init.headers).get("Authorization") === "Bearer renewed"
      ? json({ ready: true }) : json(null, 401));
  });
  vi.stubGlobal("fetch", fetchMock);
  const requests = Promise.all([fetchJson("/api/auth/me"), fetchJson("/api/admin/employees")]);
  await vi.waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url.endsWith("/api/auth/refresh"))).toHaveLength(1));
  complete(rotated());
  await expect(requests).resolves.toEqual([{ ready: true }, { ready: true }]);
  expect(fetchMock.mock.calls.filter(([url]) => url.endsWith("/api/auth/refresh"))).toHaveLength(1);
});

it("retries a delayed old-token 401 using the already rotated session without refreshing again", async () => {
  saveSession("expired", "ADMIN", "valid-refresh");
  let complete!: (value: Response) => void;
  const pending = new Promise<Response>((resolve) => { complete = resolve; });
  const fetchMock = vi.fn((url: string, init: RequestInit) => {
    if (url.endsWith("/api/auth/refresh")) return Promise.resolve(rotated());
    if (new Headers(init.headers).get("Authorization") === "Bearer renewed") return Promise.resolve(json({ ready: true }));
    return url.endsWith("/api/auth/me") ? pending : Promise.resolve(json(null, 401));
  });
  vi.stubGlobal("fetch", fetchMock);
  const user = fetchJson("/api/auth/me");
  await fetchJson("/api/admin/employees");
  complete(json(null, 401));
  await expect(user).resolves.toEqual({ ready: true });
  expect(fetchMock.mock.calls.filter(([url]) => url.endsWith("/api/auth/refresh"))).toHaveLength(1);
});

it("signals expiry when the refresh token is invalid and does not retry the current-user request", async () => {
  saveSession("expired", "ADMIN", "invalid-refresh");
  const fetchMock = vi.fn().mockResolvedValue(json(null, 401));
  const unauthorized = vi.fn(() => clearAdminToken());
  window.addEventListener("staffhub:unauthorized", unauthorized);
  vi.stubGlobal("fetch", fetchMock);
  try {
    await expect(fetchJson("/api/auth/me")).rejects.toMatchObject({ code: "SESSION_CHANGED" });
    expect(unauthorized).toHaveBeenCalledOnce();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(loadAdminToken()).toBe("");
  } finally { window.removeEventListener("staffhub:unauthorized", unauthorized); }
});

it.each(["login", "refresh", "logout", "password/forgot", "password/reset"])(
  "never recursively refreshes an unauthorized auth/%s request", async (endpoint) => {
    saveSession("expired", "ADMIN", "valid-refresh");
    const fetchMock = vi.fn().mockResolvedValue(json(null, 401));
    vi.stubGlobal("fetch", fetchMock);
    await expect(fetchJson(`/api/auth/${endpoint}`, { method: "POST" })).rejects.toMatchObject({ status: 401 });
    expect(fetchMock).toHaveBeenCalledOnce();
  },
);

it("keeps the credentials and never replays a mutation after a temporary refresh error", async () => {
  saveSession("expired", "ADMIN", "still-valid");
  const bodies: string[] = [];
  const fetchMock = vi.fn(async (url: string, init: RequestInit) => {
    bodies.push(init.method ?? "GET");
    return url.endsWith("/api/auth/refresh") ? json(null, 503) : json(null, 401);
  });
  vi.stubGlobal("fetch", fetchMock);
  await expect(fetchJson("/api/leave/requests", { method: "POST", body: "{}" }))
    .rejects.toMatchObject({ status: 503, code: "SESSION_REFRESH_UNAVAILABLE" });
  expect(fetchMock).toHaveBeenCalledTimes(2);
  expect(bodies).toEqual(["POST", "POST"]);
  expect(loadRefreshToken()).toBe("still-valid");
});
