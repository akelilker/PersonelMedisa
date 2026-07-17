import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/MevzuatParametreServiceTestRunner.php");

describe("MevzuatParametreService php runtime", () => {
  it("covers typed values, inclusive ranges, future update, cancel and audit", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-mevzuat-parametre-service: OK");
  });
});
