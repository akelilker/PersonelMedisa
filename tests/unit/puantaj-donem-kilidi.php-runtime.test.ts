import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/PuantajDonemKilidiIntegrationTestRunner.php");

describe("PuantajDonemKilidiService real PDO integration", () => {
  it("serializes seal/apply and protects manual apply rollback and idempotency", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr).toBe(0);
    expect(result.stdout).toContain("[PASS] seal holds shared period lock against apply");
    expect(result.stdout).toContain("[PASS] manual apply rollback removes puantaj and candidate mutation");
    expect(result.stdout).toContain("[PASS] same manual request is idempotent without duplicate puantaj");
    expect(result.stdout).toContain("verify-puantaj-donem-kilidi-integration: OK");
  });
});
