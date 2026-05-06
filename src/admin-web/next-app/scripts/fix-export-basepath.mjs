import fs from "node:fs";
import path from "node:path";

const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? "/dakoku/admin";
const outDir = path.resolve("out");
const loginOutDir = path.resolve("out-login");
const sessionTokenKey = "staffhub-session-token";
const sessionAudienceKey = "staffhub-session-audience";
const buildTimestamp = new Date().toISOString();

function walk(dir) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(fullPath);
      continue;
    }

    if (!/\.(html|js|txt)$/i.test(entry.name)) {
      continue;
    }

    const source = fs.readFileSync(fullPath, "utf8");
    const replaced = source
      .replaceAll('href="/_next/', `href="${basePath}/_next/`)
      .replaceAll('src="/_next/', `src="${basePath}/_next/`)
      .replaceAll(':HL["/_next/', `:HL["${basePath}/_next/`)
      .replaceAll('"p":""', `"p":"${basePath}"`);

    if (replaced !== source) {
      fs.writeFileSync(fullPath, replaced, "utf8");
    }
  }
}

function injectAdminEntryGuard() {
  const adminIndexPath = path.join(outDir, "index.html");
  if (!fs.existsSync(adminIndexPath)) {
    return;
  }

  const source = fs.readFileSync(adminIndexPath, "utf8");
  if (source.includes("staffhub-admin-entry-guard")) {
    return;
  }

  const guardScript = `
    <script id="staffhub-admin-entry-guard">
      (function () {
        try {
          var url = new URL(window.location.href);
          if (url.hostname.indexOf("www.") === 0) {
            url.hostname = url.hostname.replace(/^www\\./, "");
            window.location.replace(url.toString());
            return;
          }

          var token = window.localStorage.getItem("${sessionTokenKey}");
          var audience = window.localStorage.getItem("${sessionAudienceKey}");
          if (!token || !audience) {
            window.location.replace(url.origin + "/dakoku/login/");
          }
        } catch (error) {
          console.error(error);
        }
      })();
    </script>
  `;

  const replaced = source.replace("</head>", `${guardScript}\n</head>`);
  fs.writeFileSync(adminIndexPath, replaced, "utf8");
}

function writeReleaseManifest(dir, target) {
  fs.writeFileSync(
    path.join(dir, "release.json"),
    JSON.stringify(
      {
        builtAt: buildTimestamp,
        basePath,
        target,
      },
      null,
      2,
    ) + "\n",
    "utf8",
  );
}

if (fs.existsSync(outDir)) {
  walk(outDir);
  injectAdminEntryGuard();
  writeReleaseManifest(outDir, "admin");
  fs.rmSync(loginOutDir, { recursive: true, force: true });
  fs.mkdirSync(loginOutDir, { recursive: true });
  fs.writeFileSync(
    path.join(loginOutDir, "index.html"),
    `<!DOCTYPE html>
<html lang="ja">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>勤怠管理システム ログイン</title>
    <link rel="icon" href="${basePath}/icon.svg" type="image/svg+xml" />
    <style>
      :root {
        color-scheme: light;
        --canvas: #eef4ff;
        --surface: rgba(255, 255, 255, 0.84);
        --surface-strong: #ffffff;
        --ink: #27344d;
        --muted: #7083a0;
        --line: rgba(108, 140, 224, 0.16);
        --rail: #6c8ce0;
        --rail-soft: #92abf6;
        --primary-start: #6f8fe5;
        --primary-end: #94abf7;
        --shadow: 0 24px 60px rgba(118, 139, 191, 0.18);
        --shadow-soft: 0 16px 36px rgba(118, 139, 191, 0.1);
        --radius-xl: 26px;
        --radius-md: 12px;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        min-height: 100vh;
        font-family: "BIZ UDPGothic", "BIZ UD Gothic", "Yu Gothic UI", "Hiragino Sans", "Meiryo", sans-serif;
        color: var(--ink);
        background:
          radial-gradient(circle at top left, rgba(255, 255, 255, 0.86), transparent 26%),
          radial-gradient(circle at 84% 12%, rgba(197, 161, 232, 0.18), transparent 18%),
          linear-gradient(180deg, #f4f8ff 0%, #e8efff 52%, #eef1ff 100%);
      }
      .login-shell {
        display: grid;
        grid-template-columns: minmax(320px, 440px);
        min-height: 100vh;
        align-content: center;
        align-items: center;
        justify-content: center;
        gap: 16px;
        padding: 16px;
      }
      .login-card {
        border: 1px solid var(--line);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-soft);
      }
      .panel-kicker {
        margin: 0;
        color: var(--muted);
        font-size: 11px;
        font-weight: 900;
        letter-spacing: 0.1em;
      }
      .login-card h2 {
        margin: 0;
        font-size: 16px;
        line-height: 1.5;
        font-weight: 700;
      }
      .login-card {
        align-self: center;
        width: 100%;
        padding: 24px;
        background: var(--surface);
        backdrop-filter: blur(16px);
      }
      .login-card-header {
        margin-bottom: 18px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--line);
      }
      .login-note {
        margin: 4px 0 0;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.8;
      }
      .login-form {
        display: grid;
        gap: 14px;
      }
      label {
        display: block;
        color: var(--ink);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.03em;
      }
      input {
        width: 100%;
        min-height: 42px;
        margin-top: 6px;
        padding: 10px 12px;
        border: 1px solid var(--line);
        font: inherit;
        border-radius: var(--radius-md);
        color: var(--ink);
        background: var(--surface-strong);
        font-size: 13px;
        line-height: 1.45;
      }
      input:focus {
        outline: none;
        border-color: rgba(111, 143, 229, 0.48);
        box-shadow: 0 0 0 4px rgba(111, 143, 229, 0.12);
      }
      button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 46px;
        margin-top: 4px;
        padding: 10px 14px;
        border: 0;
        border-radius: var(--radius-md);
        font: inherit;
        font-size: 13px;
        font-weight: 700;
        color: #fff;
        background: linear-gradient(135deg, var(--primary-start) 0%, var(--primary-end) 100%);
        cursor: pointer;
      }
      button:disabled {
        opacity: .7;
        cursor: wait;
      }
      .secondary {
        color: var(--ink);
        border: 1px solid var(--line);
        background: var(--surface-strong);
      }
      .link-button {
        justify-content: flex-start;
        width: auto;
        min-height: auto;
        padding: 0;
        color: var(--primary-start);
        background: transparent;
        text-decoration: underline;
        text-underline-offset: 3px;
      }
      .is-hidden {
        display: none;
      }
      .error {
        margin-top: 14px;
        min-height: 24px;
        color: #d87790;
        font-size: 14px;
      }
      @media (max-width: 860px) {
        .login-shell {
          grid-template-columns: minmax(0, 520px);
          align-content: center;
        }
      }
      @media (max-width: 640px) {
        .login-shell {
          padding: 12px;
        }
        .login-card {
          border-radius: 18px;
        }
      }
    </style>
  </head>
  <body>
    <main class="login-shell">
      <section class="login-card">
        <div class="login-card-header">
          <h2 id="login-title">ログイン情報</h2>
          <p id="login-note" class="login-note">メールアドレスとパスワードを入力してください。</p>
        </div>
      <form id="login-form" class="login-form">
        <label>
          メールアドレス
          <input id="login-id" type="email" autocomplete="username" />
        </label>
        <label>
          パスワード
          <input id="password" type="password" autocomplete="current-password" />
        </label>
        <button id="submit-button" type="submit">ログインする</button>
        <button id="forgot-mode-button" class="link-button" type="button">パスワードを忘れた方</button>
        <div id="error-message" class="error"></div>
      </form>
      <form id="forgot-form" class="login-form is-hidden">
        <label>
          メールアドレス
          <input id="forgot-email" type="email" autocomplete="email" />
        </label>
        <button id="forgot-submit-button" type="submit">再設定メールを送信する</button>
        <button id="forgot-back-button" class="secondary" type="button">ログインに戻る</button>
        <div id="forgot-message" class="error"></div>
      </form>
      <form id="reset-form" class="login-form is-hidden">
        <label>
          メールアドレス
          <input id="reset-email" type="email" autocomplete="email" />
        </label>
        <label>
          新しいパスワード
          <input id="reset-password" type="password" autocomplete="new-password" />
        </label>
        <label>
          新しいパスワード（確認）
          <input id="reset-password-confirmation" type="password" autocomplete="new-password" />
        </label>
        <input id="reset-token" type="hidden" />
        <button id="reset-submit-button" type="submit">パスワードを再設定する</button>
        <button id="reset-back-button" class="secondary" type="button">ログインに戻る</button>
        <div id="reset-message" class="error"></div>
      </form>
      </section>
    </main>
    <script>
      const SESSION_TOKEN_KEY = "${sessionTokenKey}";
      const SESSION_AUDIENCE_KEY = "${sessionAudienceKey}";
      const LEGACY_ADMIN_TOKEN_KEY = "staffhub-admin-token";
      function normalizedOrigin() {
        const url = new URL(window.location.origin);
        if (url.hostname.startsWith("www.")) {
          url.hostname = url.hostname.replace(/^www\\./, "");
        }
        return url.origin;
      }
      const origin = normalizedOrigin();
      const apiBase = origin + "/dakoku";
      const adminUrl = origin + "/dakoku/admin/";
      if (window.localStorage.getItem(SESSION_TOKEN_KEY) && window.localStorage.getItem(SESSION_AUDIENCE_KEY)) {
        window.location.replace(adminUrl);
      }
      const title = document.getElementById("login-title");
      const note = document.getElementById("login-note");
      const form = document.getElementById("login-form");
      const forgotForm = document.getElementById("forgot-form");
      const resetForm = document.getElementById("reset-form");
      const loginIdInput = document.getElementById("login-id");
      const passwordInput = document.getElementById("password");
      const submitButton = document.getElementById("submit-button");
      const errorMessage = document.getElementById("error-message");
      const forgotModeButton = document.getElementById("forgot-mode-button");
      const forgotEmailInput = document.getElementById("forgot-email");
      const forgotSubmitButton = document.getElementById("forgot-submit-button");
      const forgotMessage = document.getElementById("forgot-message");
      const forgotBackButton = document.getElementById("forgot-back-button");
      const resetEmailInput = document.getElementById("reset-email");
      const resetPasswordInput = document.getElementById("reset-password");
      const resetPasswordConfirmationInput = document.getElementById("reset-password-confirmation");
      const resetTokenInput = document.getElementById("reset-token");
      const resetSubmitButton = document.getElementById("reset-submit-button");
      const resetMessage = document.getElementById("reset-message");
      const resetBackButton = document.getElementById("reset-back-button");
      function showMode(mode) {
        form.classList.toggle("is-hidden", mode !== "login");
        forgotForm.classList.toggle("is-hidden", mode !== "forgot");
        resetForm.classList.toggle("is-hidden", mode !== "reset");
        title.textContent = mode === "login" ? "ログイン情報" : mode === "forgot" ? "パスワード再設定" : "新しいパスワード";
        note.textContent = mode === "login"
          ? "メールアドレスとパスワードを入力してください。"
          : mode === "forgot"
            ? "職員登録済みのメールアドレスへ再設定用URLを送信します。"
            : "メールに記載されたURLから新しいパスワードを設定します。";
      }
      const params = new URLSearchParams(window.location.search);
      const initialResetToken = params.get("resetToken") || "";
      if (initialResetToken) {
        resetTokenInput.value = initialResetToken;
        resetEmailInput.value = params.get("email") || "";
        showMode("reset");
      }
      forgotModeButton.addEventListener("click", () => showMode("forgot"));
      forgotBackButton.addEventListener("click", () => showMode("login"));
      resetBackButton.addEventListener("click", () => showMode("login"));
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        submitButton.disabled = true;
        errorMessage.textContent = "";
        try {
          const response = await fetch(apiBase + "/api/auth/login", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              loginId: loginIdInput.value.trim(),
              password: passwordInput.value,
            }),
          });
          const contentType = response.headers.get("content-type") || "";
          if (!contentType.toLowerCase().includes("json")) {
            throw new Error("ログインAPIの応答が不正です。公開URL設定と www あり/なしの差異を確認してください。");
          }
          const json = await response.json();
          if (!response.ok) {
            throw new Error(json?.error?.message || (response.status === 403 ? "このアカウントではログインできません。" : "ログインに失敗しました。"));
          }
          window.localStorage.setItem(SESSION_TOKEN_KEY, json.data.accessToken);
          window.localStorage.setItem(SESSION_AUDIENCE_KEY, json.data.user.role);
          window.localStorage.removeItem(LEGACY_ADMIN_TOKEN_KEY);
          window.location.replace(adminUrl);
        } catch (error) {
          errorMessage.textContent = error instanceof Error ? error.message : "ログインに失敗しました。";
          submitButton.disabled = false;
        }
      });
      forgotForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        forgotSubmitButton.disabled = true;
        forgotMessage.textContent = "";
        try {
          const response = await fetch(apiBase + "/api/auth/password/forgot", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email: forgotEmailInput.value.trim() }),
          });
          const json = await response.json();
          if (!response.ok) {
            throw new Error(json?.error?.message || "再設定メールの送信に失敗しました。");
          }
          forgotMessage.textContent = "再設定用メールを送信しました。メールをご確認ください。";
        } catch (error) {
          forgotMessage.textContent = error instanceof Error ? error.message : "再設定メールの送信に失敗しました。";
        } finally {
          forgotSubmitButton.disabled = false;
        }
      });
      resetForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        resetSubmitButton.disabled = true;
        resetMessage.textContent = "";
        try {
          const response = await fetch(apiBase + "/api/auth/password/reset", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              email: resetEmailInput.value.trim(),
              token: resetTokenInput.value,
              password: resetPasswordInput.value,
              passwordConfirmation: resetPasswordConfirmationInput.value,
            }),
          });
          const json = await response.json();
          if (!response.ok) {
            throw new Error(json?.error?.message || "パスワード再設定に失敗しました。");
          }
          loginIdInput.value = resetEmailInput.value.trim();
          resetMessage.textContent = "パスワードを再設定しました。新しいパスワードでログインしてください。";
          window.history.replaceState(null, "", window.location.pathname);
          showMode("login");
        } catch (error) {
          resetMessage.textContent = error instanceof Error ? error.message : "パスワード再設定に失敗しました。";
        } finally {
          resetSubmitButton.disabled = false;
        }
      });
    </script>
  </body>
</html>`,
    "utf8",
  );
  writeReleaseManifest(loginOutDir, "login");
  console.log(`fixed export base path: ${basePath}`);
  console.log(`generated login entry: ${loginOutDir}`);
}
