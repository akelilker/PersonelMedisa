import { execFileSync, spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/FinalCloseBranchPreimageReadIsolationTestRunner.php");

describe("final-close branch preimage read isolation php runtime", () => {
  it("attributes each failing preimage read to its own bounded code", () => {
    const isWindows = process.platform === "win32";
    const phpPath = isWindows
      ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
      : "php";
    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, runnerPath]
      : [runnerPath];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: process.cwd() });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("FINAL_CLOSE_BRANCH_PREIMAGE_READ_ISOLATION: OK");
    // The two live reads of the owner are separated: the target table read and the
    // manager map read each answer with their own bounded code.
    expect(result.stdout).toContain(
      "[PASS] subeler target read failure (silent driver) => FINAL_CLOSE_BRANCH_TABLE_READ_FAILED"
    );
    expect(result.stdout).toContain(
      "[PASS] subeler target read failure (exception driver) => FINAL_CLOSE_BRANCH_TABLE_READ_FAILED"
    );
    expect(result.stdout).toContain(
      "[PASS] manager map read failure (silent driver) => FINAL_CLOSE_MANAGER_MAP_READ_FAILED"
    );
    expect(result.stdout).toContain(
      "[PASS] manager map read failure (exception driver) => FINAL_CLOSE_MANAGER_MAP_READ_FAILED"
    );
    // The pre-existing bounded codes are untouched.
    expect(result.stdout).toContain(
      "[PASS] unready manager schema keeps the schema code => FINAL_CLOSE_MANAGER_SCHEMA_REQUIRED"
    );
    expect(result.stdout).toContain(
      "[PASS] absent target keeps the missing-branch code => FINAL_CLOSE_BRANCH_MISSING"
    );
    expect(result.stdout).toContain(
      "[PASS] empty target list keeps the invalid-target code => FINAL_CLOSE_BRANCH_TARGET_INVALID"
    );
    expect(result.stdout).toContain(
      "[PASS] surfaced target-read failure is the bounded code only"
    );
  });
});
