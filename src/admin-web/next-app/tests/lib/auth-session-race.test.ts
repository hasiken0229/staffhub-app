import { afterEach, expect, it, vi } from "vitest";
import { loginPortal } from "@/lib/api/auth";
import { saveSession } from "@/lib/api/core";

vi.mock("@/lib/api/core", () => ({ API_BASE_URL: "https://example.invalid", ApiRequestError: class extends Error {}, fetchJson: vi.fn(), saveSession: vi.fn() }));
afterEach(() => { vi.unstubAllGlobals(); vi.clearAllMocks(); });

it("ログインの遅い応答が無効になったセッションを復元しない", async () => {
  let resolve!: (value: Response) => void;
  vi.stubGlobal("fetch", vi.fn().mockReturnValue(new Promise<Response>((done) => { resolve = done; })));
  let current = true;
  const request = loginPortal("admin", "password", undefined, () => current);
  current = false;
  resolve(new Response(JSON.stringify({ data: { accessToken: "obsolete-token", refreshToken: "obsolete-refresh", user: { role: "ADMIN" } } }), { headers: { "Content-Type": "application/json" } }));
  await request;
  expect(saveSession).not.toHaveBeenCalled();
});
