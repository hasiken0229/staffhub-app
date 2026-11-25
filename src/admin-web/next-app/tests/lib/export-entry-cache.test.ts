import { execFileSync } from "node:child_process";
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { expect, it } from "vitest";

it("書き出し直しても管理入口から当該版のログインを開き、両入口のHTMLを保存させない", () => {
  const folder = mkdtempSync(join(tmpdir(), "staffhub-entry-cache-"));
  try {
    mkdirSync(join(folder, "out"));
    writeFileSync(join(folder, "out/index.html"), "<html><head></head><body></body></html>");
    const script = resolve("scripts/fix-export-basepath.mjs");
    for (let run = 0; run < 2; run++) {
      execFileSync(process.execPath, [script], { cwd: folder, stdio: "pipe" });
      const release = JSON.parse(readFileSync(join(folder, "out-login/release.json"), "utf8"));
      const html = readFileSync(join(folder, "out/index.html"), "utf8");
      expect(html.match(/id="staffhub-admin-entry-guard"/g)).toHaveLength(1);
      expect(html).toContain(`/dakoku/login/?v=${release.builtAt.replace(/\D/g, "")}`);
      for (const dir of ["out", "out-login"]) {
        const config = readFileSync(join(folder, dir, ".htaccess"), "utf8");
        expect(config).toContain('Header always set Cache-Control "no-store"');
        expect(config).toContain(".*\\.html");
      }
    }
  } finally {
    rmSync(folder, { recursive: true, force: true });
  }
});
