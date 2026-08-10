import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import { resolve } from "node:path";

const root = resolve(__dirname, "../..");
const runner = resolve(root, "tests/php/TatilDonemiSourceHashTestRunner.php");

describe("policy-gap-a tatil_donemi source hash", () => {
  it("attendance fingerprint includes authoritative tatil_donemi minutes", () => {
    const result = spawnSync("php", [runner], {
      cwd: root,
      encoding: "utf8"
    });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("ALL_TATIL_DONEMI_SOURCE_HASH_TESTS_PASSED");
  });
});
