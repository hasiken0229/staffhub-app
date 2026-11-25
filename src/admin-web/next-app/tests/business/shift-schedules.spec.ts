import { expect, test, type Page } from "@playwright/test";
import { fillLoginCredentials } from "../helpers/login";
import fs from "node:fs/promises";
import path from "node:path";

const api = process.env.STAFFHUB_E2E_API_URL!;
const root = process.env.STAFFHUB_VERIFICATION_ROOT!;

async function login(page: Page) {
  await page.goto("/dakoku/admin/");
  await fillLoginCredentials(page, "verify-admin@example.test", "VerifyOnly-2026!");
  const response = page.waitForResponse((result) => result.url() === `${api}/api/auth/login` && result.request().method() === "POST");
  await page.getByRole("button", { name: "ログインする", exact: true }).click();
  const result = await response;
  expect(result.status()).toBe(200);
  await expect(page.getByRole("button", { name: "ログアウト", exact: true }).first()).toBeVisible();
  return (await result.json()).data.accessToken as string;
}

async function openAttendance(page: Page) {
  const menu = page.getByRole("button", { name: "管理メニューを開く", exact: true });
  if (await menu.isVisible()) await menu.click();
  await page.getByRole("navigation", { name: "管理メニュー" }).getByRole("button", { name: /^日次勤怠(?:\s+打刻漏れなど要確認の日次があります)?$/, exact: true }).click();
}

async function selectMonth(page: Page, month: string) {
  await page.getByRole("button", { name: "検索条件", exact: true }).click();
  await page.getByRole("textbox", { name: "対象月", exact: true }).fill(month);
  const response = page.waitForResponse((result) => result.url().includes("/api/admin/attendance/daily-grid?") && new URL(result.url()).searchParams.get("targetMonth") === month);
  await page.getByRole("button", { name: "条件で再表示", exact: true }).click();
  expect((await response).ok()).toBeTruthy();
  await expect(page.getByRole("button", { name: "条件で再表示", exact: true })).toBeEnabled();
  const shifts = page.waitForResponse((result) => result.request().method() === "GET" && result.url().includes("/api/admin/attendance/shift-schedules?") && new URL(result.url()).searchParams.get("targetMonth") === month);
  await page.getByRole("button", { name: "月次シフト", exact: true }).click();
  expect((await shifts).ok()).toBeTruthy();
  await expect(page.getByRole("button", { name: "シフトを保存", exact: true })).toBeEnabled();
}

test("月次シフトは選択月への保存・既存値の再表示・翌日時刻・再読込を維持し締め済み月を拒否する", async ({ page, request }) => {
  const token = await login(page);
  const fixture = JSON.parse(await fs.readFile(path.join(root, "fixtures.json"), "utf8"));
  const headers = { Authorization: `Bearer ${token}`, Accept: "application/json" };
  await openAttendance(page);
  await selectMonth(page, "2026-06");
  const panel = page.locator("#attendance-shifts");
  await expect(panel).toContainText("対象月 2026-06");
  await expect(panel.getByLabel("日付", { exact: true })).toHaveValue("2026-06-01");
  await panel.getByRole("combobox", { name: "職員", exact: true }).selectOption(String(fixture.employeeId));
  await panel.getByLabel("日付", { exact: true }).fill("2026-06-10");
  await panel.getByRole("combobox", { name: "勤務区分", exact: true }).selectOption("");
  await panel.getByLabel("予定出勤", { exact: true }).fill("22:00");
  await panel.getByLabel("予定退勤", { exact: true }).fill("02:00");
  await panel.getByLabel("メモ", { exact: true }).fill("専用環境・翌日シフト確認");
  const saved = page.waitForResponse((result) => result.url() === `${api}/api/admin/attendance/shift-schedules` && result.request().method() === "POST");
  await panel.getByRole("button", { name: "シフトを保存", exact: true }).click();
  const savedResponse = await saved;
  expect(savedResponse.ok(), await savedResponse.text()).toBeTruthy();
  const savedRows = (await savedResponse.json()).data;
  expect(savedRows).toEqual(expect.arrayContaining([expect.objectContaining({ employeeId: fixture.employeeId, targetDate: "2026-06-10", scheduledClockInTime: "22:00", scheduledClockOutTime: "02:00" })]));
  await expect(panel.getByText("翌日 02:00", { exact: true })).toBeVisible();
  await panel.getByLabel("日付", { exact: true }).fill("2026-06-11");
  await expect(panel.getByLabel("メモ", { exact: true })).toHaveValue("");
  await panel.getByLabel("日付", { exact: true }).fill("2026-06-10");
  await expect(panel.getByLabel("予定出勤", { exact: true })).toHaveValue("22:00");
  await expect(panel.getByLabel("予定退勤", { exact: true })).toHaveValue("02:00");
  await expect(panel.getByLabel("メモ", { exact: true })).toHaveValue("専用環境・翌日シフト確認");
  const reloaded = page.waitForResponse((result) => result.request().method() === "GET" && result.url().includes("/api/admin/attendance/shift-schedules?targetMonth=2026-06"));
  await panel.getByRole("button", { name: "シフトを再読込", exact: true }).click();
  expect((await reloaded).ok()).toBeTruthy();
  await expect(panel.getByLabel("日付", { exact: true })).toHaveValue("2026-06-10");
  await expect(panel.getByLabel("予定出勤", { exact: true })).toHaveValue("22:00");
  await expect(panel.getByLabel("予定退勤", { exact: true })).toHaveValue("02:00");
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: path.join(root, `admin-shifts-${width}.png`), fullPage: true, animations: "disabled" });
  }
  const attendance = await request.get(`${api}/api/admin/attendance/daily?targetMonth=2026-06`, { headers });
  expect(attendance.ok()).toBeTruthy();
  expect((await attendance.json()).data).toEqual([]);

  // The CLI-only fixture seeds May as CLOSED; no live month is closed by this test.
  await selectMonth(page, "2026-05");
  await expect(panel.getByLabel("日付", { exact: true })).toHaveValue("2026-05-01");
  await panel.getByRole("combobox", { name: "職員", exact: true }).selectOption(String(fixture.employeeId));
  await panel.getByLabel("予定出勤", { exact: true }).fill("09:00");
  await panel.getByLabel("予定退勤", { exact: true }).fill("18:00");
  const rejected = page.waitForResponse((result) => result.url() === `${api}/api/admin/attendance/shift-schedules` && result.request().method() === "POST");
  await panel.getByRole("button", { name: "シフトを保存", exact: true }).click();
  const rejectedResponse = await rejected;
  expect(rejectedResponse.status()).toBe(422);
  expect((await rejectedResponse.json()).error.code).toBe("CLOSED_PERIOD");
  await expect(panel.getByRole("alert")).toBeVisible();
  const unchanged = await request.get(`${api}/api/admin/attendance/shift-schedules?targetMonth=2026-05`, { headers });
  expect(unchanged.ok()).toBeTruthy();
  expect((await unchanged.json()).data).toEqual([]);
});
