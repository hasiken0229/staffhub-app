import { expect, test, type Page } from "@playwright/test";
import { fillLoginCredentials } from "../helpers/login";

async function mockAdminSession(page: Page, options?: { failAttendance?: boolean; includePayrollStatement?: boolean }) {
  await page.addInitScript(() => {
    window.sessionStorage.setItem("staffhub-session-token", "e2e-token");
    window.sessionStorage.setItem("staffhub-session-audience", "ADMIN");
  });

  await page.route("**/dakoku/up", async (route) => {
    await route.fulfill({ status: 200, contentType: "text/plain", body: "ok" });
  });

  await page.route("**/dakoku/api/**", async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;

    if (path.endsWith("/api/auth/me")) {
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            id: 1,
            role: "ADMIN",
            name: "E2E 管理者",
            isAdmin: true,
            canUseEmployeePortal: true,
          },
        }),
      });
      return;
    }

    if (path.endsWith("/api/mobile/home")) {
      await route.fulfill({ json: { data: {
        employee: { id: 1, employeeCode: "E0001", name: "青木 花子" },
        todayAttendance: null, pendingLeaveCount: 0, unreadNotificationCount: 0, leaveTypes: [],
      } } });
      return;
    }

    if (options?.includePayrollStatement && path.endsWith("/api/payroll/statements/101")) {
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            id: 101,
            employeeId: 1,
            employeeCode: "E0001",
            employeeName: "青木 花子",
            statementType: "PAYROLL",
            statementTypeLabel: "給与",
            targetYearMonth: "2026-08",
            originalFileName: "payroll.pdf",
            payDate: "2026-09-20",
            periodStartOn: "2026-08-01",
            periodEndOn: "2026-08-31",
            grossAmount: 300000,
            deductionAmount: 50000,
            netAmount: 250000,
            legacyMode: false,
            lines: [],
            sections: { pay: [], deduction: [], summary: [], other: [] },
          },
        }),
      });
      return;
    }

    if (options?.includePayrollStatement && path.endsWith("/api/payroll/statements")) {
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: Array.from({ length: 12 }, (_, i) => ({
            id: 101 + i,
            employeeId: 1,
            employeeCode: "E0001",
            employeeName: "青木 花子",
            statementType: "PAYROLL",
            statementTypeLabel: "給与",
            targetYearMonth: new Date(Date.UTC(2026, 7 - i, 1)).toISOString().slice(0, 7),
            originalFileName: "payroll.pdf",
            payDate: "2026-09-20",
            publishedAt: "2026-09-20T09:00:00+09:00",
          })),
        }),
      });
      return;
    }

    if (options?.failAttendance && path.endsWith("/api/admin/attendance/daily-grid")) {
      await route.fulfill({
        status: 503,
        contentType: "application/json",
        body: JSON.stringify({ error: { message: "勤怠APIに接続できません。" } }),
      });
      return;
    }

    if (path.endsWith("/api/admin/reports/hub")) {
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            todayAttendanceCount: 0,
            pendingAttendanceApprovalCount: 0,
            pendingLeaveCount: 0,
            publishedPayrollCount: 0,
          },
        }),
      });
      return;
    }

    if (path.endsWith("/api/admin/system-masters")) {
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            departments: [],
            locations: [],
            employmentTypes: [],
            workTypes: [],
            requestTypes: [],
            leaveTypes: [],
            paidLeaveSettings: [],
            attendanceAlerts: [],
            attendanceErrorRules: [],
            dailyFieldSettings: [],
          },
        }),
      });
      return;
    }

    if (path.endsWith("/api/admin/attendance/month-close/precheck")) {
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            targetYearMonth: "2026-09",
            canClose: true,
            blockers: [],
            summary: {
              unsubmittedDailyCount: 0,
              pendingApprovalCount: 0,
              returnedApprovalCount: 0,
              openErrorCount: 0,
              inProgressErrorCount: 0,
              pendingDailyEditRequestCount: 0,
              dailyCount: 0,
              closedDailyCount: 0,
              openDailyCount: 0,
              payrollBatchCount: 0,
              monthCloseStatus: "OPEN",
            },
            payrollReady: false,
            payrollBlockers: [],
            payrollWarnings: [],
          },
        }),
      });
      return;
    }

    if (path.endsWith("/api/admin/attendance/month-close")) {
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            targetYearMonth: "2026-09",
            status: "OPEN",
            note: null,
            closedAt: null,
            closedByName: null,
            reopenedAt: null,
            reopenedByName: null,
            dailyCount: 0,
            closedDailyCount: 0,
            openDailyCount: 0,
            pendingApprovalCount: 0,
            payrollBatchCount: 0,
          },
        }),
      });
      return;
    }

    if (path.endsWith("/api/admin/attendance/break-rules")) {
      await route.fulfill({ contentType: "application/json", body: JSON.stringify({ data: null }) });
      return;
    }

    if (path.endsWith("/api/admin/attendance/devices")) {
      await route.fulfill({
        contentType: "application/json",
        body: JSON.stringify({
          data: [
            {
              id: 1,
              deviceCode: "FRONT-01",
              name: "玄関打刻端末",
              locationName: "玄関",
              appVersion: "1.2.3",
              lastSeenAt: "2026-09-11T09:00:00+09:00",
              clientTimeAt: "2026-09-11T09:00:05+09:00",
              clockDriftSeconds: 5,
              pendingOfflineCount: 0,
              readerStatus: "READY",
              isActive: true,
            },
          ],
        }),
      });
      return;
    }

    await route.fulfill({ contentType: "application/json", body: JSON.stringify({ data: [] }) });
  });
}

test("ログイン失敗時に理由を表示し、入力内容を保持する", async ({ page }) => {
  await page.route("**/dakoku/api/auth/login", async (route) => {
    expect(route.request().postDataJSON()).toEqual({
      loginId: "staff@example.test",
      password: "incorrect-password",
    });

    await route.fulfill({
      status: 401,
      contentType: "application/json",
      body: JSON.stringify({ error: { message: "メールアドレスまたはパスワードが正しくありません。" } }),
    });
  });

  await page.goto("/dakoku/admin/");
  await fillLoginCredentials(page, "staff@example.test", "incorrect-password");
  await page.getByRole("button", { name: "ログインする" }).click();

  await expect(page.getByRole("main").getByRole("alert")).toHaveText("メールアドレスとパスワードを確認して、もう一度ログインしてください。");
  await expect(page.getByLabel("メールアドレス")).toHaveValue("staff@example.test");
});

test("ログイン欄は初期読取専用で、Tabキーで入力できる", async ({ page }) => {
  await page.goto("/dakoku/admin/");
  const email = page.getByLabel("メールアドレス", { exact: true });
  const password = page.getByLabel("パスワード", { exact: true });
  await expect(email).toHaveAttribute("readonly", "");
  await expect(password).toHaveAttribute("readonly", "");
  await page.keyboard.press("Tab");
  await expect(email).toBeFocused();
  await expect(email).toBeEditable();
  await page.keyboard.type("keyboard@example.test");
  await page.keyboard.press("Tab");
  await expect(password).toBeFocused();
  await expect(password).toBeEditable();
  await expect(email).toHaveValue("keyboard@example.test");
});

test("パスワード再設定メールを送信できる", async ({ page }) => {
  await page.route("**/dakoku/api/auth/password/forgot", async (route) => {
    expect(route.request().postDataJSON()).toEqual({ email: "staff@example.test" });
    await route.fulfill({
      contentType: "application/json",
      body: JSON.stringify({ data: { success: true } }),
    });
  });

  await page.goto("/dakoku/admin/");
  await page.getByRole("button", { name: "パスワードを忘れた方" }).click();
  await page.getByLabel("メールアドレス").fill("staff@example.test");
  await page.getByRole("button", { name: "再設定メールを送信する" }).click();

  await expect(page.getByText("再設定用メールを送信しました。メールをご確認ください。")).toBeVisible();
});

test("狭い画面で管理操作を保ち、各幅でページが横にはみ出さない", async ({ page }) => {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  await page.setViewportSize({ width: 390, height: 844 });
  await mockAdminSession(page);
  await page.goto("/dakoku/admin/");

  await expect(page.locator(".workspace-account-actions .workspace-user-label")).toBeVisible();
  await expect(page.getByRole("button", { name: "ログアウト" })).toBeVisible();
  await expect(page.getByRole("tablist", { name: "画面切替" })).toBeVisible();
  await expect(page.getByRole("button", { name: "管理メニューを開く" })).toBeVisible();
  await expect(page.getByRole("navigation", { name: "管理メニュー" })).not.toBeVisible();

  await page.getByRole("button", { name: "管理メニューを開く" }).click();
  await expect(page.getByRole("navigation", { name: "管理メニュー" })).toBeVisible();
  await page.getByRole("button", { name: "日次勤怠" }).click();

  await expect(page.getByRole("heading", { name: "日次勤怠", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "管理メニューを開く" })).toBeVisible();
  await page.getByRole("button", { name: "打刻端末" }).click();
  await expect(page.getByRole("heading", { name: "打刻端末の状態" })).toBeVisible();
  await expect(page.getByText("玄関打刻端末")).toBeVisible();

  for (const width of [360, 390, 768, 972, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    const overflow = await page.evaluate(() => ({
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      bodyWidth: document.body.scrollWidth,
    }));
    expect(overflow.documentWidth, `document overflow at ${width}px`).toBeLessThanOrEqual(overflow.viewportWidth);
    expect(overflow.bodyWidth, `body overflow at ${width}px`).toBeLessThanOrEqual(overflow.viewportWidth);
    await expect(page.getByRole("button", { name: "ログアウト" })).toBeVisible();
  }
  expect(pageErrors).toEqual([]);
});

test("管理メニューは各幅で項目名を表示し、ダッシュボードのレポートから帳票メニューへ直接移動する", async ({ page }, testInfo) => {
  await mockAdminSession(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => {
    if (message.type() === "error") errors.push(message.text());
  });
  await page.goto("/dakoku/admin/");
  await expect(page).toHaveTitle("勤怠管理システム");
  const menu = page.getByRole("navigation", { name: "管理メニュー" });
  const subnav = page.getByRole("navigation", { name: "ページ内メニュー" });
  const shortcut = subnav.getByRole("button", { name: "レポート", exact: true });
  const group = menu.getByRole("button", { name: "業務管理" });
  const expectedLabels = ["ダッシュボード", "お知らせ", "職員管理", "カード管理", "日次勤怠", "届出・有給管理", "給与明細", "HARMOS移行", "レポート", "監査ログ", "システム管理"];

  for (const width of [1440, 1024, 768, 390]) {
    await page.setViewportSize({ width, height: 900 });
    if (width === 390) {
      await expect(menu).not.toBeVisible();
      await page.getByRole("button", { name: "管理メニューを開く" }).click();
    }
    await expect(menu.locator(".nav-item")).toHaveText(expectedLabels);
    for (const label of await menu.locator(".nav-label").all()) {
      await expect(label).toBeVisible();
      expect(await label.evaluate((node) => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
    }
    await expect(menu.getByRole("button", { name: "ダッシュボード" })).toHaveAttribute("aria-current", "page");
    await group.click();
    await expect(group).toHaveAttribute("aria-expanded", "false");
    await expect(menu.getByRole("button", { name: "職員管理" })).not.toBeVisible();
    await group.click();
    await expect(menu.getByRole("button", { name: "職員管理" })).toBeVisible();
    if (width === 390) await page.getByRole("button", { name: "管理メニューを閉じる" }).click();
    await expect(subnav.getByRole("button")).toHaveText(["本日の勤怠状況", "部門別サマリー", "承認待ちの届出", "レポート"]);
    await expect(shortcut).toBeInViewport({ ratio: 1 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await page.screenshot({ path: testInfo.outputPath(`dashboard-${width}.png`) });
  }

  await shortcut.focus();
  await page.keyboard.press("Enter");
  await expect(subnav.getByRole("button", { name: "レポート出力" })).toHaveAttribute("aria-current", "page");
  await expect(page.getByRole("button", { name: /^月次レポート/ })).toBeVisible();

  await subnav.getByRole("button", { name: "今日の出退勤" }).click();
  await expect(page.getByRole("heading", { name: "今日の出退勤レポート" })).toBeVisible();
  await page.getByRole("button", { name: "管理メニューを開く" }).click();
  await menu.getByRole("button", { name: "ダッシュボード" }).click();
  await expect(menu).not.toBeVisible();
  await shortcut.click();
  await expect(page.getByRole("button", { name: /^月次レポート/ })).toBeVisible();
  await expect(page.getByRole("heading", { name: "今日の出退勤レポート" })).not.toBeVisible();
  expect(errors).toEqual([]);
});

test("勤怠取得失敗時は空表示や確定件数と混同しない", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockAdminSession(page, { failAttendance: true });
  await page.goto("/dakoku/admin/");

  await page.getByRole("button", { name: "管理メニューを開く" }).click();
  await page.getByRole("button", { name: "日次勤怠" }).click();

  await expect(page.getByRole("alert").getByText("勤怠の取得に失敗しました。")).toBeVisible();
  await expect(page.getByRole("button", { name: "勤怠一覧を再試行" })).toBeVisible();
  await expect(page.getByText("対象月の日次勤怠はありません")).toHaveCount(0);
});

test("勤怠の取得失敗中は月締前チェックを未確認とし、締め操作を無効にする", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockAdminSession(page, { failAttendance: true });
  await page.goto("/dakoku/admin/");

  await page.getByRole("button", { name: "管理メニューを開く" }).click();
  await page.getByRole("button", { name: "日次勤怠" }).click();
  await page.getByRole("button", { name: "月締め" }).click();

  await expect(page.getByText("取得失敗", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "この月を締める" })).toBeDisabled();
  await expect(page.getByText("未処理項目はありません。", { exact: true })).toHaveCount(0);
});

test("職員の給与明細詳細はスマートフォン幅に収まり、明細と操作を表示する", async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockAdminSession(page, { includePayrollStatement: true });
  await page.goto("/dakoku/admin/");

  await page.getByRole("tab", { name: "利用者画面" }).click();
  await page.getByRole("button", { name: "給与明細", exact: true }).click();
  const rows = page.locator("#employee-payroll-mobile-list article");
  await expect(rows).toHaveCount(12);
  await rows.first().getByRole("button", { name: "明細を見る" }).click();
  const selectedHeading = rows.first().getByRole("heading", { name: "青木 花子 / 給与 2026-08" });
  await expect(selectedHeading).toBeFocused();
  await expect(selectedHeading).toBeInViewport();
  await page.screenshot({ path: testInfo.outputPath("selected-month.png"), animations: "disabled" });

  for (const width of [360, 390, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    const overflow = await page.evaluate(() => ({
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      bodyWidth: document.body.scrollWidth,
    }));
    expect(overflow.documentWidth, `employee payroll detail overflows at ${width}px`).toBeLessThanOrEqual(overflow.viewportWidth);
    expect(overflow.bodyWidth, `employee payroll body overflows at ${width}px`).toBeLessThanOrEqual(overflow.viewportWidth);
    await expect(page.locator(".payroll-detail-card").getByRole("button", { name: "PDF保存" })).toBeVisible();
  }

  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: testInfo.outputPath("mobile-payroll-detail.png"), fullPage: true, animations: "disabled" });
});

test("月をまたぐ単日休暇と同種2件の取消対象を職員が確認できる", async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 375, height: 667 });
  await mockAdminSession(page);
  const cancelled: string[] = [];
  await page.route("**/api/leave/requests", (route) => route.fulfill({ json: { data: [1, 2].map((id) => ({
    id, leaveTypeName: "有給休暇", requestCategory: "LEAVE", startDate: `2026-12-0${id}`,
    endDate: `2026-12-0${id}`, quantityDays: 1, status: "PENDING",
  })) } }));
  await page.route("**/api/leave/requests/*/cancel", (route) => {
    cancelled.push(new URL(route.request().url()).pathname);
    return route.fulfill({ json: { data: { id: 2, status: "CANCELLED" } } });
  });
  await page.goto("/dakoku/admin/");
  await page.getByRole("tab", { name: "利用者画面" }).click();
  const navigation = page.getByRole("navigation", { name: "職員画面メニュー" });
  const leaveButton = navigation.getByRole("button", { name: "休暇申請" });
  await leaveButton.click();
  const start = page.getByLabel("開始日", { exact: true });
  const end = page.getByLabel("終了日", { exact: true });
  await start.fill("2026-11-30");
  await start.fill("2026-12-01");
  await expect(end).toHaveValue("2026-12-01");
  expect(await start.evaluate((node) => parseFloat(getComputedStyle(node).fontSize))).toBeGreaterThanOrEqual(16);
  expect(await leaveButton.evaluate((node) => node.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
  await navigation.getByRole("button", { name: "申請状況" }).click();
  const row = page.locator(".mobile-record-card").filter({ hasText: "2026-12-02" });
  await row.getByRole("button", { name: "この休暇申請を取消" }).click();
  const dialog = page.getByRole("dialog", { name: "休暇申請を取り消しますか？" });
  await expect(dialog).toContainText("2026/12/2");
  await expect(dialog).toContainText("有給休暇");
  await page.screenshot({ path: testInfo.outputPath("confirm-leave-date.png"), animations: "disabled" });
  expect(cancelled).toHaveLength(0);
  await dialog.getByRole("button", { name: "申請を取り消す" }).click();
  await expect.poll(() => cancelled).toEqual(["/dakoku/api/leave/requests/2/cancel"]);
});

test("動きを減らす設定でも管理者・職員の本文とボタンが表示される", async ({ page }) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await mockAdminSession(page);
  await page.goto("/dakoku/admin/");
  await expect(page.getByRole("heading", { name: "本日の勤怠状況" })).toBeVisible();
  const visiblePanels = () => page.locator(".section-enter").evaluateAll((elements) => elements.every((element) => {
    const style = getComputedStyle(element);
    return Number(style.opacity) === 1 && style.visibility === "visible";
  }));
  expect(await visiblePanels()).toBe(true);
  await page.getByRole("tab", { name: "利用者画面" }).click();
  await expect(page.getByRole("heading", { name: "職員画面", exact: true })).toBeVisible();
  await expect(page.getByRole("navigation", { name: "職員画面メニュー" }).getByRole("button", { name: "休暇申請", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "ログアウト" })).toBeVisible();
  expect(await visiblePanels()).toBe(true);
});

test("スマホの職員表は氏名幅を保ち、表の中だけ横スクロールする", async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockAdminSession(page);
  await page.route("**/dakoku/api/admin/employees*", (route) => route.fulfill({
    contentType: "application/json", body: JSON.stringify({ data: [{
      id: 1, employeeCode: "E0001", name: "検証 花子", employmentType: "FULL_TIME", status: "ACTIVE", joinedOn: "2026-04-01",
    }] }),
  }));
  await page.goto("/dakoku/admin/");
  await page.getByRole("button", { name: "管理メニューを開く" }).click();
  await page.getByRole("button", { name: "職員管理", exact: true }).click();
  const name = page.getByRole("button", { name: "検証 花子", exact: true });
  await expect(name).toBeVisible();
  expect(await name.evaluate((element) => element.closest("td")!.getBoundingClientRect().width)).toBeGreaterThan(140);
  expect(await name.evaluate((element) => element.getBoundingClientRect().height)).toBeLessThan(30);
  expect(await page.locator(".employee-table-wrap").evaluate((element) => element.scrollWidth > element.clientWidth)).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath("mobile-employee-list.png"), fullPage: true, animations: "disabled" });
});

test("再読込した期限切れセッションは更新して管理画面を開く", async ({ page }) => {
  await mockAdminSession(page);
  await page.addInitScript(() => window.sessionStorage.setItem("staffhub-session-refresh-token", "valid-refresh"));
  let refreshCount = 0;
  await page.route("**/dakoku/api/auth/refresh", (route) => {
    refreshCount += 1;
    return route.fulfill({ contentType: "application/json", body: JSON.stringify({ data: {
      accessToken: "renewed", refreshToken: "next-refresh", user: { role: "ADMIN" },
    } }) });
  });
  await page.route("**/dakoku/api/auth/me", (route) => route.fulfill({
    status: route.request().headers().authorization === "Bearer renewed" ? 200 : 401,
    contentType: "application/json", body: JSON.stringify({ data: {
      id: 1, role: "ADMIN", name: "E2E 管理者", isAdmin: true, canUseEmployeePortal: true,
    } }),
  }));
  await page.goto("/dakoku/admin/");
  await expect(page.getByRole("heading", { name: "本日の勤怠状況" })).toBeVisible();
  expect(refreshCount).toBe(1);
});

test("セッション更新の一時障害から資格を保持したまま再試行できる", async ({ page }) => {
  await mockAdminSession(page);
  await page.addInitScript(() => sessionStorage.setItem("staffhub-session-refresh-token", "valid-refresh"));
  let unavailable = true;
  let attempts = 0;
  await page.route("**/dakoku/api/auth/refresh", (route) => {
    attempts++;
    return route.fulfill({
      status: unavailable ? 503 : 200, contentType: "application/json",
      body: JSON.stringify(unavailable ? { error: { message: "一時障害" } } : { data: {
        accessToken: "renewed", refreshToken: "next-refresh", user: { role: "ADMIN" },
      } }),
    });
  });
  await page.route("**/dakoku/api/auth/me", (route) => route.fulfill({
    status: route.request().headers().authorization === "Bearer renewed" ? 200 : 401,
    contentType: "application/json", body: JSON.stringify({ data: {
      id: 1, role: "ADMIN", name: "試験管理者", isAdmin: true,
    } }),
  }));
  await page.goto("/dakoku/admin/");
  await expect(page.getByRole("heading", { name: "ログイン状態の確認" })).toBeVisible();
  expect(await page.evaluate(() => sessionStorage.getItem("staffhub-session-refresh-token"))).toBe("valid-refresh");
  unavailable = false;
  await page.getByRole("button", { name: "再試行", exact: true }).click();
  await expect(page.getByRole("heading", { name: "本日の勤怠状況" })).toBeVisible();
  expect(await page.evaluate(() => sessionStorage.getItem("staffhub-session-refresh-token"))).toBe("next-refresh");
  expect(attempts).toBe(2);
});

test("本人確認後のホーム集計障害を表示し再ログインなしで復帰する", async ({ page }) => {
  await mockAdminSession(page);
  let finish!: () => void;
  const pending = new Promise<void>((resolve) => { finish = resolve; });
  await page.route("**/dakoku/api/admin/reports/hub", async (route) => {
    await pending;
    await route.fulfill({
      status: 503, contentType: "application/json", body: JSON.stringify({ error: { message: "サマリー一時障害" } }),
    });
  });
  await page.goto("/dakoku/admin/");
  try {
    await expect(page.getByRole("status").filter({ hasText: "ログイン状態とホームの情報を確認しています" })).toBeVisible();
    await expect(page.getByLabel("パスワード", { exact: true })).toHaveCount(0);
  } finally { finish(); }
  await expect(page.getByRole("alert").filter({ hasText: "サマリー一時障害" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "管理ホーム" })).toBeVisible();
  await expect(page.getByLabel("パスワード", { exact: true })).toHaveCount(0);
  await page.unroute("**/dakoku/api/admin/reports/hub");
  await page.getByRole("button", { name: "再試行", exact: true }).click();
  await expect(page.getByRole("heading", { name: "本日の勤怠状況" })).toBeVisible();
});

test("タブレットの月次シフトとレポートは文書を横スクロールせず操作できる", async ({ page }) => {
  await mockAdminSession(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto("/dakoku/admin/");
  const menus = page.getByRole("navigation", { name: "管理メニュー" });
  const submenu = page.getByRole("navigation", { name: "ページ内メニュー" });
  for (const [section, panel] of [["日次勤怠","月次シフト"],["レポート","レポート出力"]]) {
    await page.setViewportSize({ width: 1440, height: 900 });
    await menus.getByRole("button", { name: section, exact: true }).click();
    await submenu.getByRole("button", { name: panel, exact: true }).click();
    for (const width of [390,720,721,768,820,1024,1440]) {
      await page.setViewportSize({ width, height: 900 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth), panel+" "+width).toBeLessThanOrEqual(width);
      const action = page.getByRole("button", { name: panel === "月次シフト" ? "シフトを保存" : /^日次勤怠データ出力/ });
      await expect(action).toBeVisible();
      const box = await action.boundingBox();
      expect(box!.x + box!.width).toBeLessThanOrEqual(width + 1);
    }
  }
});

test("Service Worker更新で他アプリのキャッシュと新しい版を保持する", async ({ page }) => {
  await page.goto("/dakoku/admin/");
  const names = await page.evaluate(async () => {
    for (const key of ["別アプリ-fixture", "staffhub-static-v4", "staffhub-static-v99"]) await caches.open(key);
    const registration = await navigator.serviceWorker.register("/dakoku/admin/sw.js");
    await navigator.serviceWorker.ready;
    const worker = registration.active ?? registration.installing ?? registration.waiting;
    if (!worker) throw new Error("Service Worker が登録されていません");
    if (worker.state !== "activated") {
      await new Promise<void>((resolve) => worker.addEventListener("statechange", () => {
        if (worker.state === "activated") resolve();
      }));
    }
    return caches.keys();
  });
  expect(names).toContain("別アプリ-fixture");
  expect(names).toContain("staffhub-static-v5");
  expect(names).toContain("staffhub-static-v99");
  expect(names).not.toContain("staffhub-static-v4");
});
