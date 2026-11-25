import fs from "node:fs";
import path from "node:path";
const loginMessages = JSON.parse(fs.readFileSync(new URL("../lib/login-messages.json", import.meta.url), "utf8"));

const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? "/dakoku/admin";
const outDir = path.resolve("out");
const loginOutDir = path.resolve("out-login");
const sessionTokenKey = "staffhub-session-token";
const sessionRefreshTokenKey = "staffhub-session-refresh-token";
const sessionAudienceKey = "staffhub-session-audience";
const buildTimestamp = new Date().toISOString();
const assetVersion = buildTimestamp.replace(/\D/g, "");

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
    let replaced = source
      .replaceAll('href="/_next/', `href="${basePath}/_next/`)
      .replaceAll('src="/_next/', `src="${basePath}/_next/`)
      .replaceAll(':HL["/_next/', `:HL["${basePath}/_next/`)
      .replaceAll('"p":""', `"p":"${basePath}"`);

    if (/\.(html|txt)$/i.test(entry.name)) {
      replaced = replaced.replace(
        new RegExp(`(${basePath.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}/_next/static/[^"'\\s]+\\.(?:js|css))(?!\\?)`, "g"),
        `$1?v=${assetVersion}`,
      );
    }

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

  const source = fs.readFileSync(adminIndexPath, "utf8")
    .replace(/\s*<script id="staffhub-admin-entry-guard">[\s\S]*?<\/script>\s*<\/head>/, "</head>");

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

          var token = window.sessionStorage.getItem("${sessionTokenKey}");
          var refreshToken = window.sessionStorage.getItem("${sessionRefreshTokenKey}");
          var audience = window.sessionStorage.getItem("${sessionAudienceKey}");
          if (!token || !refreshToken || !audience) {
            window.location.replace(url.origin + "/dakoku/login/?v=${assetVersion}");
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
        basePath: target === "login" ? "/dakoku/login" : basePath,
        target,
      },
      null,
      2,
    ) + "\n",
    "utf8",
  );
}

function writeSecurityHeaders(dir) {
  fs.writeFileSync(
    path.join(dir, ".htaccess"),
    `<IfModule mod_headers.c>
  <FilesMatch "(?i)^(?:.*\\.html|index\\.txt|release\\.json|sw\\.js)$">
    Header always set Cache-Control "no-store"
  </FilesMatch>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "DENY"
  Header always set Referrer-Policy "no-referrer"
  Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self' https://attendance.example.org; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; upgrade-insecure-requests"
</IfModule>
`,
    "utf8",
  );
}

if (fs.existsSync(outDir)) {
  walk(outDir);
  injectAdminEntryGuard();
  writeReleaseManifest(outDir, "admin");
  writeSecurityHeaders(outDir);
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
        --muted: #495b73;
        --line: rgba(108, 140, 224, 0.16);
        --rail: #6c8ce0;
        --rail-soft: #92abf6;
        --primary-start: #254d91;
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
        min-height: 44px;
        margin-top: 6px;
        padding: 10px 12px;
        border: 1px solid var(--line);
        font: inherit;
        border-radius: var(--radius-md);
        color: var(--ink);
        background: var(--surface-strong);
        font-size: 16px;
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
        min-height: 48px;
        margin-top: 4px;
        padding: 10px 14px;
        border: 0;
        border-radius: var(--radius-md);
        font: inherit;
        font-size: 13px;
        font-weight: 700;
        color: #fff;
        background: var(--primary-start);
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
        min-height: 44px;
        padding: 0;
        color: var(--primary-start);
        background: transparent;
        text-decoration: underline;
        text-underline-offset: 3px;
      }
      .is-hidden {
        display: none;
      }
      :is(input, button):focus-visible { outline: 3px solid #2d5296; outline-offset: 2px; }
      .login-feedback { margin: 8px 0; color: #31455d; font-size: 15px; line-height: 1.5; }
      .login-feedback[role="alert"] { color: #8b263b; }
      .login-feedback:empty { margin: 0; }
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
          <p id="login-status" class="login-feedback" role="status" aria-live="polite" aria-atomic="true"></p>
          <p id="error-message" class="login-feedback" role="alert" aria-atomic="true"></p>
        </div>
      <form id="login-form" class="login-form" method="post" action="${basePath}/" autocomplete="off">
        <label>
          メールアドレス
          <input id="login-id" name="username" type="email" autocomplete="off" readonly />
        </label>
        <label>
          パスワード
          <input id="password" name="password" type="password" autocomplete="new-password" readonly />
        </label>
        <button id="submit-button" type="submit">ログインする</button>
        <button id="forgot-mode-button" class="link-button" type="button">パスワードを忘れた方</button>
      </form>
      <form id="forgot-form" class="login-form is-hidden">
        <label>
          メールアドレス
          <input id="forgot-email" type="email" autocomplete="email" />
        </label>
        <button id="forgot-submit-button" type="submit">再設定メールを送信する</button>
        <button id="forgot-back-button" class="secondary" type="button">ログインに戻る</button>
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
      </form>
      </section>
    </main>
    <script>
      const SESSION_TOKEN_KEY = "${sessionTokenKey}";
      const SESSION_REFRESH_TOKEN_KEY = "${sessionRefreshTokenKey}";
      const SESSION_AUDIENCE_KEY = "${sessionAudienceKey}";
      const LEGACY_ADMIN_TOKEN_KEY = "staffhub-admin-token";
      const pageUrl = new URL(window.location.href);
      if (pageUrl.hostname.startsWith("www.")) {
        pageUrl.hostname = pageUrl.hostname.replace(/^www\\./, "");
        window.location.replace(pageUrl.toString());
      }
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
      if (window.sessionStorage.getItem(SESSION_TOKEN_KEY) && window.sessionStorage.getItem(SESSION_REFRESH_TOKEN_KEY) && window.sessionStorage.getItem(SESSION_AUDIENCE_KEY)) {
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
      const statusMessage = document.getElementById("login-status");
      const messages = ${JSON.stringify(loginMessages)};
      const forgotModeButton = document.getElementById("forgot-mode-button");
      const forgotEmailInput = document.getElementById("forgot-email");
      const forgotSubmitButton = document.getElementById("forgot-submit-button");
      const forgotBackButton = document.getElementById("forgot-back-button");
      const resetEmailInput = document.getElementById("reset-email");
      const resetPasswordInput = document.getElementById("reset-password");
      const resetPasswordConfirmationInput = document.getElementById("reset-password-confirmation");
      const resetTokenInput = document.getElementById("reset-token");
      const resetSubmitButton = document.getElementById("reset-submit-button");
      const resetBackButton = document.getElementById("reset-back-button");
      let passwordChangeToken = "";
      function openSession(session) {
        if (!session?.accessToken || !session.refreshToken || !session.user?.role) throw new Error("ログインの応答を確認できませんでした。もう一度ログインしてください。");
        window.sessionStorage.removeItem("staffhub-employee-navigation");
        window.sessionStorage.setItem(SESSION_TOKEN_KEY, session.accessToken);
        window.sessionStorage.setItem(SESSION_REFRESH_TOKEN_KEY, session.refreshToken);
        window.sessionStorage.setItem(SESSION_AUDIENCE_KEY, session.user.role);
        [SESSION_TOKEN_KEY, SESSION_REFRESH_TOKEN_KEY, SESSION_AUDIENCE_KEY, LEGACY_ADMIN_TOKEN_KEY].forEach(key => window.localStorage.removeItem(key));
        window.location.assign(adminUrl);
      }
      function setLoginAutofillEnabled(enabled) {
        form.autocomplete = enabled ? "on" : "off";
        loginIdInput.readOnly = !enabled;
        loginIdInput.autocomplete = enabled ? "username" : "off";
        passwordInput.readOnly = !enabled;
        passwordInput.autocomplete = enabled ? "current-password" : "new-password";
      }
      [loginIdInput, passwordInput].forEach((input) => {
        input.addEventListener("pointerdown", () => setLoginAutofillEnabled(true));
        input.addEventListener("focus", () => setLoginAutofillEnabled(true));
      });
      function showMode(mode) {
        if (mode !== "initial") passwordChangeToken = "";
        resetPasswordInput.value = "";
        resetPasswordConfirmationInput.value = "";
        resetPasswordInput.required = resetPasswordConfirmationInput.required = mode === "initial";
        resetPasswordInput.minLength = resetPasswordConfirmationInput.minLength = mode === "initial" ? 8 : 0;
        resetEmailInput.closest("label").classList.toggle("is-hidden", mode === "initial");
        resetSubmitButton.textContent = mode === "initial" ? "変更して管理画面を開く" : "パスワードを再設定する";
        statusMessage.textContent = "";
        errorMessage.textContent = "";
        if (mode !== "login") {
          setLoginAutofillEnabled(false);
        }
        form.classList.toggle("is-hidden", mode !== "login");
        forgotForm.classList.toggle("is-hidden", mode !== "forgot");
        resetForm.classList.toggle("is-hidden", mode !== "reset" && mode !== "initial");
        title.textContent = mode === "login" ? "ログイン情報" : mode === "forgot" ? "パスワード再設定" : mode === "initial" ? messages.initialTitle : "新しいパスワード";
        note.textContent = mode === "login"
          ? "メールアドレスとパスワードを入力してください。"
          : mode === "forgot"
            ? "職員登録済みのメールアドレスへ再設定用URLを送信します。"
            : mode === "initial" ? messages.initialNote : "メールに記載されたURLから新しいパスワードを設定します。";
        if (mode === "initial") resetPasswordInput.focus();
      }
      window.addEventListener("pagehide", () => { if (passwordChangeToken) showMode("login"); });
      const params = new URLSearchParams(window.location.search);
      if (window.sessionStorage.getItem("staffhub-login-notice") === "session-expired") {
        window.sessionStorage.removeItem("staffhub-login-notice");
        statusMessage.textContent = messages.expired;
      }
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
        if (submitButton.disabled) return;
        submitButton.disabled = true;
        forgotModeButton.disabled = true;
        errorMessage.textContent = "";
        statusMessage.textContent = "";
        try {
          const response = await fetch(apiBase + "/api/auth/login", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({
              loginId: loginIdInput.value.trim(),
              password: passwordInput.value,
            }),
          });
          const contentType = response.headers.get("content-type") || "";
          if (!contentType.toLowerCase().includes("json")) {
            throw new Error(response.status === 429 ? "アクセスが集中しています。しばらくしてから再試行してください。" : "ログインの応答を確認できませんでした。接続先またはサーバーの状態を確認してください。");
          }
          const json = await response.json().catch(() => { throw new Error("ログインの応答を読み取れませんでした。もう一度ログインしてください。"); });
          if (!response.ok) {
            if (response.status === 401) throw new Error(messages.invalidCredentials);
            throw new Error(json?.error?.message || (response.status === 403 ? "このアカウントではログインできません。" : "ログインに失敗しました。"));
          }
          if (json.data?.requiresPasswordChange) {
            if (!json.data.passwordChangeToken || !(json.data.expiresInSeconds > 0)) throw new Error("初回パスワード変更の応答を確認できませんでした。もう一度ログインしてください。");
            [SESSION_TOKEN_KEY, SESSION_REFRESH_TOKEN_KEY, SESSION_AUDIENCE_KEY, LEGACY_ADMIN_TOKEN_KEY, "staffhub-employee-navigation"].forEach(key => {
              window.sessionStorage.removeItem(key);
              window.localStorage.removeItem(key);
            });
            passwordChangeToken = json.data.passwordChangeToken;
            passwordInput.value = "";
            submitButton.disabled = false;
            forgotModeButton.disabled = false;
            showMode("initial");
            return;
          }
          openSession(json.data);
        } catch (error) {
          errorMessage.textContent = error instanceof TypeError ? messages.offlineLogin : error instanceof Error ? error.message : "ログインに失敗しました。";
          submitButton.disabled = false;
          forgotModeButton.disabled = false;
        }
      });
      forgotForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        forgotSubmitButton.disabled = true;
        errorMessage.textContent = "";
        statusMessage.textContent = "";
        try {
          const response = await fetch(apiBase + "/api/auth/password/forgot", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({ email: forgotEmailInput.value.trim() }),
          });
          const json = await response.json().catch(() => { throw new Error("再設定メールの受付を確認できません。受信メールを確認してください。"); });
          if (!response.ok) {
            throw new Error(json?.error?.message || "再設定メールの送信に失敗しました。");
          }
          statusMessage.textContent = "再設定用メールを送信しました。メールをご確認ください。";
        } catch (error) {
          errorMessage.textContent = error instanceof TypeError ? messages.offlineForgot : error instanceof Error ? error.message : "再設定メールの送信に失敗しました。";
        } finally {
          forgotSubmitButton.disabled = false;
        }
      });
      resetForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (resetSubmitButton.disabled) return;
        if (passwordChangeToken) {
          const password = resetPasswordInput.value, passwordConfirmation = resetPasswordConfirmationInput.value;
          if (Array.from(password).length < 8 || password !== passwordConfirmation) {
            errorMessage.textContent = Array.from(password).length < 8 ? messages.initialShort : messages.initialMismatch;
            return;
          }
          const token = passwordChangeToken;
          resetSubmitButton.disabled = resetBackButton.disabled = true;
          resetPasswordInput.disabled = resetPasswordConfirmationInput.disabled = true;
          errorMessage.textContent = "";
          try {
            const response = await fetch(apiBase + "/api/auth/password/initial", {
              method: "POST", cache: "no-store", headers: { "Content-Type": "application/json", "Accept": "application/json" },
              body: JSON.stringify({ passwordChangeToken: token, password, passwordConfirmation }),
            });
            const json = await response.json().catch(() => null);
            if (passwordChangeToken !== token) return;
            if (response.status === 422) {
              errorMessage.textContent = json?.error?.details?.[0]?.message || json?.error?.message || "入力内容を確認してください。";
              return;
            }
            if (!response.ok) {
              showMode("login");
              errorMessage.textContent = response.status === 401 ? messages.initialExpired : messages.initialUnknown;
              return;
            }
            openSession(json?.data);
            showMode("login");
          } catch {
            if (passwordChangeToken === token) {
              showMode("login");
              errorMessage.textContent = messages.initialUnknown;
            }
          } finally {
            resetSubmitButton.disabled = resetBackButton.disabled = false;
            resetPasswordInput.disabled = resetPasswordConfirmationInput.disabled = false;
          }
          return;
        }
        resetSubmitButton.disabled = true;
        errorMessage.textContent = "";
        statusMessage.textContent = "";
        try {
          const response = await fetch(apiBase + "/api/auth/password/reset", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({
              email: resetEmailInput.value.trim(),
              token: resetTokenInput.value,
              password: resetPasswordInput.value,
              passwordConfirmation: resetPasswordConfirmationInput.value,
            }),
          });
          const json = await response.json().catch(() => { throw new Error(messages.offlineReset); });
          if (!response.ok) {
            throw new Error(json?.error?.message || "パスワード再設定に失敗しました。");
          }
          loginIdInput.value = resetEmailInput.value.trim();
          passwordInput.value = "";
          resetPasswordInput.value = "";
          resetPasswordConfirmationInput.value = "";
          resetTokenInput.value = "";
          window.history.replaceState(null, "", window.location.pathname);
          showMode("login");
          statusMessage.textContent = messages.resetComplete;
        } catch (error) {
          errorMessage.textContent = error instanceof TypeError ? messages.offlineReset : error instanceof Error ? error.message : "パスワード再設定に失敗しました。";
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
  writeSecurityHeaders(loginOutDir);
  console.log(`fixed export base path: ${basePath}`);
  console.log(`generated login entry: ${loginOutDir}`);
}
