import { Blob as NodeBlob } from "node:buffer";
import { afterEach, expect, it, vi } from "vitest";
import { fetchJson, validateDownload } from "@/lib/api/core";

afterEach(() => vi.unstubAllGlobals());

it("keeps individual validation errors so forms can identify and focus the affected fields", async () => {
  vi.stubGlobal("fetch", vi.fn().mockResolvedValue(new Response(JSON.stringify({ error: {
    code: "VALIDATION_ERROR", message: "入力内容を確認してください。", details: [
      { field: "startDate", message: "開始日を入力してください。" },
      { field: "endDate", message: "終了日は開始日以降にしてください。" },
    ],
  } }), { status: 422, headers: { "Content-Type": "application/json" } })));
  await expect(fetchJson("/api/test")).rejects.toMatchObject({ status: 422, fieldErrors: {
    startDate: ["開始日を入力してください。"], endDate: ["終了日は開始日以降にしてください。"],
  } });
});

it("preserves HTTP failure meaning even when the server returns HTML", async () => {
  vi.stubGlobal("fetch", vi.fn().mockResolvedValue(new Response("<html>Error</html>", { status: 403, headers: { "Content-Type": "text/html" } })));
  await expect(fetchJson("/api/test")).rejects.toMatchObject({ status: 403, message: "この操作の権限がありません。" });
});

it("asks for JSON and refuses a successful HTML response", async () => {
  const fetch = vi.fn().mockResolvedValue(new Response("<html>Login</html>", { status: 200, headers: { "Content-Type": "text/html" } }));
  vi.stubGlobal("fetch", fetch);
  await expect(fetchJson("/api/test")).rejects.toMatchObject({ code: "INVALID_RESPONSE" });
  expect(fetch.mock.calls[0][1].headers.get("Accept")).toBe("application/json");
});

it.each([
  ["", "application/pdf", "empty.pdf"],
  ["<html>Error</html>", "application/pdf", "bad.pdf"],
  ["not a pdf", "application/pdf", "bad.pdf"],
  ["%PDF-1.4", "text/html", "bad.pdf"],
  ["not a zip", "application/zip", "bad.zip"],
])("refuses invalid downloadable content %s", async (content, type, name) => {
  await expect(validateDownload(new NodeBlob([content], { type }) as unknown as Blob, name)).rejects.toThrow();
});

it("accepts a PDF signature and ordinary CSV", async () => {
  await expect(validateDownload(new NodeBlob(["%PDF-1.4\n"], { type: "application/pdf" }) as unknown as Blob, "p.pdf")).resolves.toBeUndefined();
  await expect(validateDownload(new NodeBlob(["code,name\n1,test"], { type: "text/csv" }) as unknown as Blob, "p.csv")).resolves.toBeUndefined();
});
