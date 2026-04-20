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
        --bg: #f5efe4;
        --panel: rgba(255,255,255,.92);
        --line: rgba(29,48,43,.14);
        --text: #18211d;
        --muted: #66726c;
        --brand: #1f3a35;
        --accent: #c77939;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        min-height: 100vh;
        font-family: "BIZ UDPGothic","BIZ UDゴシック","Hiragino Kaku Gothic ProN","Yu Gothic","Meiryo",sans-serif;
        color: var(--text);
        background:
          radial-gradient(circle at top right, rgba(199,121,57,.18), transparent 28%),
          linear-gradient(180deg, #faf6ef 0%, var(--bg) 100%);
        display: grid;
        place-items: center;
        padding: 24px;
      }
      .login-card {
        width: min(460px, 100%);
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 28px;
        box-shadow: 0 30px 80px rgba(31,58,53,.12);
        padding: 36px 32px;
      }
      h1 {
        margin: 0 0 10px;
        font-size: 28px;
        line-height: 1.3;
      }
      p {
        margin: 0 0 24px;
        color: var(--muted);
        font-size: 15px;
        line-height: 1.8;
      }
      label {
        display: block;
        margin-bottom: 14px;
        font-size: 14px;
        font-weight: 700;
      }
      input {
        width: 100%;
        margin-top: 8px;
        border: 1px solid var(--line);
        border-radius: 16px;
        padding: 14px 16px;
        font: inherit;
        background: #fff;
      }
      button {
        width: 100%;
        border: 0;
        border-radius: 999px;
        padding: 14px 18px;
        margin-top: 10px;
        font: inherit;
        font-weight: 700;
        color: #fff;
        background: linear-gradient(135deg, var(--brand), #28463f);
        cursor: pointer;
      }
      button:disabled {
        opacity: .7;
        cursor: wait;
      }
      .error {
        margin-top: 14px;
        min-height: 24px;
        color: #a74f1d;
        font-size: 14px;
      }
    </style>
  </head>
  <body>
    <main class="login-card">
      <h1>勤怠管理システム</h1>
      <p>ログインIDとパスワードを入力してください。</p>
      <form id="login-form">
        <label>
          ログインID
          <input id="login-id" autocomplete="username" />
        </label>
        <label>
          パスワード
          <input id="password" type="password" autocomplete="current-password" />
        </label>
        <button id="submit-button" type="submit">ログインする</button>
        <div id="error-message" class="error"></div>
      </form>
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
      const form = document.getElementById("login-form");
      const loginIdInput = document.getElementById("login-id");
      const passwordInput = document.getElementById("password");
      const submitButton = document.getElementById("submit-button");
      const errorMessage = document.getElementById("error-message");
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
    </script>
  </body>
</html>`,
    "utf8",
  );
  writeReleaseManifest(loginOutDir, "login");
  console.log(`fixed export base path: ${basePath}`);
  console.log(`generated login entry: ${loginOutDir}`);
}
