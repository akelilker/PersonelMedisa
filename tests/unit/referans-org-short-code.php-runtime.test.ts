import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import path from "node:path";

describe("referans org short code php contract", () => {
  it("validates kisa_kod migration + normalize + response contract", () => {
    const runner = path.resolve(__dirname, "../php/ReferansOrgShortCodeTestRunner.php");
    const result = spawnSync("php", [runner], {
      encoding: "utf8",
      cwd: path.resolve(__dirname, "../..")
    });

    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("ALL_KISA_KOD_CONTRACT_CHECKS_PASSED");
    expect(result.stdout).toContain("[PASS] null kisa_kod stays null");
    expect(result.stdout).toContain("[PASS] trims kisa_kod and keeps Turkish chars");
    expect(result.stdout).toContain("[PASS] non-string kisa_kod fail-closed");
  });
});
