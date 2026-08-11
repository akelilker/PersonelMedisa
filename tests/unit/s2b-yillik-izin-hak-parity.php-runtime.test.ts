import { describe, expect, it } from "vitest";
import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";

const runnerPath = resolve(process.cwd(), "tests/php/S2BYillikIzinHakParityTestRunner.php");

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

describe("S2B yillik izin legal parity PHP runtime", () => {
  it("runs S2BYillikIzinHakParityTestRunner when php is available", () => {
    const phpPath = resolvePhpCommand();
    if (!phpPath) {
      return;
    }

    const isWindows = process.platform === "win32";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toMatch(/S2B(\/S2C)? legal parity OK/);
  });
});
