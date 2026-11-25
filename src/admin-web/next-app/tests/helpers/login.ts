import type { Page } from "@playwright/test";

export async function fillLoginCredentials(page: Page, email: string, password: string) {
  const emailInput = page.getByLabel("メールアドレス", { exact: true });
  await emailInput.focus();
  await emailInput.fill(email);
  await page.getByLabel("パスワード", { exact: true }).fill(password);
}
