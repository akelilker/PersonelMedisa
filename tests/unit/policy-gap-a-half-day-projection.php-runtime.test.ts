import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import { resolve } from "node:path";

const root = resolve(__dirname, "../..");
const runner = resolve(root, "tests/php/ResmiTatilTakvimProjectionHalfDayTestRunner.php");

describe("policy-gap-a half-day projection php runtime", () => {
  it("safe interval minutes + fail-closed ambiguous break", () => {
    const result = spawnSync("php", [runner], {
      cwd: root,
      encoding: "utf8"
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("ALL_PROJECTION_HALF_DAY_TESTS_PASSED");
  });
});
