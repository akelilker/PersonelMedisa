import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/DonemKapanisCloseIntegrationTestRunner.php");

describe("DonemKapanis close integration php runtime", () => {
  it("covers blocked audit, idempotent retry, seal success and S74/S75 smoke", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr).toBe(0);
    expect(result.stdout).toContain("[PASS] blocker returns 409 PERIOD_CLOSE_BLOCKED");
    expect(result.stdout).toContain("[PASS] blocked retry audit is idempotent");
    expect(result.stdout).toContain("[PASS] clean period seals successfully");
    expect(result.stdout).toContain("[PASS] seal retry is idempotent");
    expect(result.stdout).toContain("[PASS] warnings do not block close");
    expect(result.stdout).toContain("[PASS] salary missing does not block close");
    expect(result.stdout).toContain("[PASS] S74 period lock serializes concurrent acquire attempts");
    expect(result.stdout).toContain("verify-donem-kapanis-close-integration: OK");
  });
});
