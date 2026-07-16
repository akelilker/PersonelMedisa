import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/DonemKapanisPreflightTestRunner.php");

describe("DonemKapanisPreflightService php runtime", () => {
  it("covers empty month, blockers, warnings, sealed state, hash and scope filters", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, "-d", "extension=php_pdo_sqlite.dll", runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr).toBe(0);
    expect(result.stdout).toContain("[PASS] empty month is closable");
    expect(result.stdout).toContain("[PASS] draft bildirim is blocker");
    expect(result.stdout).toContain("[PASS] HAZIR candidate is blocker");
    expect(result.stdout).toContain("[PASS] BEKLIYOR puantaj is blocker");
    expect(result.stdout).toContain("[PASS] salary warning does not block close preflight");
    expect(result.stdout).toContain("[PASS] sealed period does not emit blockers");
    expect(result.stdout).toContain("[PASS] preflight hash is deterministic lowercase sha256");
    expect(result.stdout).toContain("verify-donem-kapanis-preflight: OK");
  });
});
