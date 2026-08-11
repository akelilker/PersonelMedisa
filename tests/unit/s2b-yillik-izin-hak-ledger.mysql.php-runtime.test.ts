import { describe, expect, it } from "vitest";
import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";

const runnerPath = resolve(process.cwd(), "tests/php/S2BYillikIzinHakLedgerMysqlTestRunner.php");

function resolvePhpCommand(): string | null {
  try {
    const isWindows = process.platform === "win32";
    if (isWindows) {
      return execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0]?.trim() || null;
    }
    execFileSync("php", ["-v"], { encoding: "utf8" });
    return "php";
  } catch {
    return null;
  }
}

describe("S2B yillik izin ledger mysql/php runtime", () => {
  it("runs S2BYillikIzinHakLedgerMysqlTestRunner when php is available", () => {
    const phpPath = resolvePhpCommand();
    if (!phpPath) {
      return;
    }

    const isWindows = process.platform === "win32";
    const phpArgs = isWindows
      ? [
          "-d",
          `extension_dir=${resolve(dirname(phpPath), "ext")}`,
          "-d",
          "extension=php_pdo_sqlite.dll",
          runnerPath
        ]
      : ["-d", "extension=pdo_sqlite", runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("S2B ledger mysql runner OK");
    expect(result.stdout).toContain("create DEVIR +8");
    expect(result.stdout).toContain("zero delta rejected");
    expect(result.stdout).toContain("FUTURE_DATED_LEDGER as-of Aug11 = +4");
    expect(result.stdout).toContain("netSumAsOf 2026-08-11 excludes future");
    expect(result.stdout).not.toContain("sqlite driver missing");
  });
});
