import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/FinalClosePersonnelInvariantHashTestRunner.php");

describe("final-close personnel invariant hash php runtime", () => {
  it("keeps the canonical personnel row inside the invariant and the join alias outside it", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-final-close-personnel-invariant-hash: OK");
    // These three lines only print when the hash input is the canonical row: a
    // narrowed seven-field projection fails them, which is the regression this
    // runner exists to catch.
    expect(result.stdout).toContain("join-derived company alias is not part of the hash input");
    expect(result.stdout).toContain("canonical column changes the invariant: tc_kimlik_no");
    expect(result.stdout).toContain("canonical column changes the invariant: maas_tutari");
  });
});
