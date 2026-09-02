import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelUcretAktifSoftNullTestRunner.php");

describe("PersonelUcretController aktif soft-null (php runtime)", () => {
  it("returns 200/null for SALARY_MISSING and keeps 404/403 for real failures", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    const stdout = String(result.stdout);
    for (const marker of [
      "missing active wage returns HTTP 200",
      "missing active wage returns data null",
      "active wage returns HTTP 200",
      "active wage returns record object",
      "unknown personel returns HTTP 404",
      "unknown personel returns SALARY_RECORD_NOT_FOUND",
      "unauthorized role returns HTTP 403",
      "unauthorized role returns SALARY_ACCESS_FORBIDDEN",
    ]) {
      expect(stdout).toContain(marker);
    }
    expect(stdout).toContain("verify-personel-ucret-aktif-soft-null: OK");
    expect(stdout).not.toContain("[FAIL]");
  });
});
