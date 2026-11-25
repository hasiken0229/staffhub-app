import { afterEach, expect, it, vi } from "vitest";
import { changeInitialPassword, loginPortal } from "@/lib/api/auth";
import { clearAdminToken, loadAdminToken, saveSession } from "@/lib/api/core";

afterEach(() => { vi.unstubAllGlobals(); clearAdminToken(); });

it("変更用トークンを保存せず、変更の成功時だけ通常セッションを保存する", async () => {
  const token = crypto.randomUUID();
  const password = crypto.randomUUID();
  const session = { accessToken: crypto.randomUUID(), refreshToken: crypto.randomUUID(), user: { role: "ADMIN" } };
  const envelope = (data: unknown) => Response.json({ data });
  const fetcher = vi.fn().mockResolvedValueOnce(envelope({ requiresPasswordChange: true, passwordChangeToken: token, expiresInSeconds: 600 }))
    .mockResolvedValueOnce(envelope(session));
  vi.stubGlobal("fetch", fetcher);
  saveSession(crypto.randomUUID(), "EMPLOYEE", crypto.randomUUID());
  const required = await loginPortal("review@example.invalid", password);
  expect(required).toMatchObject({ passwordChangeToken: token });
  expect(loadAdminToken()).toBe("");
  expect(Object.values(sessionStorage)).not.toContain(token);
  expect(Object.values(localStorage)).not.toContain(token);
  await changeInitialPassword(token, password, password);
  expect(fetcher.mock.calls[1][0]).toMatch(/\/api\/auth\/password\/initial$/);
  expect(loadAdminToken()).toBe(session.accessToken);
});

it("初回変更の401で一般セッションの更新や期限切れ通知を発生させない", async () => {
  const fetcher = vi.fn().mockResolvedValue(Response.json({ error: { code: "PASSWORD_CHANGE_EXPIRED", message: "再ログインしてください。" } }, { status: 401 }));
  vi.stubGlobal("fetch", fetcher);
  const expired = vi.fn();
  window.addEventListener("staffhub:unauthorized", expired);
  try {
    saveSession(crypto.randomUUID(), "EMPLOYEE", crypto.randomUUID());
    const password = crypto.randomUUID();
    await expect(changeInitialPassword(crypto.randomUUID(), password, password)).rejects.toMatchObject({ status: 401 });
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(expired).not.toHaveBeenCalled();
  } finally { window.removeEventListener("staffhub:unauthorized", expired); }
});
