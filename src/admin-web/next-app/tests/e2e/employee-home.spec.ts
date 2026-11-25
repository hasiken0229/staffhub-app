import { test, expect } from "@playwright/test";

test.use({ serviceWorkers: "block", viewport: { width: 375, height: 812 } });

test("当日を優先し、勤怠の更新と各タブの失敗・再試行を独立させる", async ({ page, context }) => {
  const employee = { id: 93, name: "架空 職員", employeeCode: "TEST93" };
  let day = "2026-11-30";
  let checkedTime = "17:00:00";
  let out = "";
  let readFails = true;
  let paydayFails = true;
  let noticesFail = true;
  const seen: string[] = [];
  const pause = () => { let resume!: () => void; const wait = new Promise<void>(resolve => { resume = resolve; }); return { wait, resume }; };
  const identity = pause(), salary = pause(), notices = pause();
  const daily = (date: string) => ({ id: 8, employeeId: employee.id, targetDate: date, dayState: out ? "COMPLETE" : "WORKING", rawClockInAt: date + "T08:20:08+09:00", clockInAt: date + "T08:30:00+09:00",
    rawClockOutAt: out ? date + "T" + out + "+09:00" : null, clockOutAt: out ? date + "T" + out + "+09:00" : null });
  await page.addInitScript(() => { sessionStorage.setItem("staffhub-session-token", "qa"); sessionStorage.setItem("staffhub-session-refresh-token", "qa-refresh"); sessionStorage.setItem("staffhub-session-audience", "EMPLOYEE"); });
  await page.route("**/api/**", async (route) => {
    const url = new URL(route.request().url());
    const key = url.pathname.split("/api/")[1] + url.search;
    seen.push(key);
    const ok = (data: unknown) => route.fulfill({ json: { data } });
    const fail = () => route.fulfill({ status: 503, json: { error: { message: "隔離試験" } } });
    if (key === "auth/me") { await identity.wait; return ok({ ...employee, role: "EMPLOYEE", isAdmin: false }); }
    if (key === "mobile/home?view=today") return ok({ employee, checkedAt: day + "T" + checkedTime + "+09:00", todayAttendance: daily(day) });
    if (key === "mobile/home?view=attention") return ok({ returnedRequestCount: 0, unreadNotificationCount: 1 });
    if (key === "payroll/statements") { await salary.wait; return paydayFails ? fail() : ok([]); }
    if (key === "notifications") { await notices.wait; return noticesFail ? fail() : ok([
      { id: 2, title: "未読の配信", body: "確認の本文", isRead: false, sourceType: "NOTICE" },
      { id: 3, title: "以前の配信", body: "", isRead: true, sourceType: "NOTICE" }]); }
    if (key === "notifications/2/read") return readFails ? fail() : ok({ success: true });
    if (key.startsWith("attendance/daily?targetMonth=")) return ok([daily(url.searchParams.get("targetMonth") + "-01")]);
    if (key === "attendance/daily-edit-requests") return ok([]);
    if (key === "leave/ledger") return ok({ employeeId: employee.id, currentBalance: 8, items: [] });
    if (key === "leave/balance?asOf=" + day) return ok({ asOf: day, currentBalance: 8, availableDays: 6, futureApprovedDays: 2 });
    return ok([]);
  });
  await page.goto("/dakoku/admin/");
  await expect.poll(() => seen.includes("mobile/home?view=today")).toBe(true);
  await expect(page.getByText("架空 職員")).toHaveCount(0);
  await expect(page.getByRole("button", { name: /今日の詳細を見る/ })).toHaveCount(0);
  identity.resume();
  const home = page.getByRole("region", { name: "職員ホーム" });
  await expect(home).toContainText("出勤 08:20");
  await expect(home).toContainText("勤務計算 08:30");
  await expect(home).toContainText("最終確認 2026-11-30 17:00");
  expect(seen.filter(key => !["auth/me","mobile/home?view=today","mobile/home?view=attention"].includes(key))).toEqual([]);
  const nav = page.getByRole("navigation", { name: "職員画面メニュー" });
  const open = (name: string) => nav.getByRole("button", { name, exact: true }).click();
  for (const section of [{name:"給与明細", gate:salary, endpoint:"payroll/statements"}, {name:"通知",gate:notices, endpoint:"notifications"}]) {
    await open(section.name);
    await expect.poll(() => seen.includes(section.endpoint)).toBe(true);
    await expect(page.getByRole("status").filter({hasText:"この画面の情報を取得"})).toBeVisible();
    await open("ホーム");
    await expect(home).toContainText("実打刻 08:20");
    section.gate.resume();
    await open(section.name);
    await expect(page.getByRole("main").getByRole("alert")).toContainText("この画面の情報を取得できません");
    const start = seen.length;
    if (section.name === "給与明細") paydayFails = false; else noticesFail = false;
    await page.getByRole("button", { name: "この項目を再試行" }).click();
    await expect(page.getByRole("region", { name: section.name, exact: true })).toBeVisible();
    expect(seen.slice(start)).toEqual([section.endpoint]);
  }
  await expect(page.getByText("以前の配信").filter({visible:true})).toHaveCount(0);
  await page.getByRole("button", {name:"この通知を既読にする"}).click();
  await expect(page.getByRole("main").getByRole("alert")).toBeVisible();
  await expect(page.getByText("未読の配信").filter({visible:true})).toBeVisible();
  readFails = false;
  await page.getByRole("button", {name:"この通知を既読にする"}).click();
  await expect(page.getByText("未読のお知らせはありません").filter({visible:true})).toBeVisible();
  await page.getByRole("button", {name:"すべての通知"}).click();
  await expect(page.getByText("以前の配信").filter({visible:true})).toBeVisible();
  await open("勤怠確認");
  await page.getByLabel("対象月", {exact:true}).fill("2026-10");
  await expect(page.getByRole("article").filter({ hasText: "2026-10-01" })).toBeVisible();
  await open("ホーム");
  await home.getByRole("button",{name:/今日の詳細を見る/}).click();
  await expect(page.getByRole("heading", { name: "2026-11-30 今日の勤怠詳細" })).toBeFocused();
  await expect(page.getByRole("region", {name:"今日の勤怠詳細"})).toContainText("お急ぎの場合は管理者");
  await page.getByRole("button", { name:"この月の勤怠を見る" }).click();
  await expect(page.getByLabel("対象月", {exact:true})).toHaveValue("2026-11");
  await open("ホーム");
  await context.setOffline(true);
  await expect(home).toContainText("オフライン・前回確認 2026-11-30 17:00");
  let start = seen.length;
  out = "17:38:00"; checkedTime = "17:39:00";
  await context.setOffline(false);
  await expect(home).toContainText("退勤 17:38");
  expect(seen.slice(start)).toEqual(["mobile/home?view=today"]);
  start = seen.length;
  checkedTime = "23:59:59";
  await page.evaluate(() => document.dispatchEvent(new Event("visibilitychange")));
  await expect(home).toContainText("最終確認 2026-11-30 23:59");
  day = "2026-12-01"; checkedTime = "00:00:01"; out = "";
  await expect(home).toContainText("最終確認 2026-12-01 00:00");
  expect(seen.slice(start)).toEqual(["mobile/home?view=today","mobile/home?view=today"]);
  await open("有給台帳");
  await page.getByRole("button", { name: "現在の申請可能残数を見る" }).click();
  await expect(page.getByRole("status").filter({ hasText: "2026-12-01 時点の申請可能残数" })).toContainText("6日");
});
