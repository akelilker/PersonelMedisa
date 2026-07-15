import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/BildirimPuantajEtkiManualApplyTestRunner.php");

describe("BildirimPuantajEtkiManualApplyService php runtime", () => {
  it("passes manual apply mapping/hash scenarios", () => {
    const result = spawnSync("php", [runnerPath], {
      encoding: "utf8",
      cwd: process.cwd()
    });
    expect(result.status).toBe(0);
    expect(result.stdout).toContain("verify-bildirim-puantaj-etki-manual-apply: OK");
    expect(result.stdout).toContain("[PASS] GOREVDE manual mapping");
    expect(result.stdout).toContain("[PASS] manual hash deterministic");
    expect(result.stdout).toContain("[PASS] automatic hash schema unchanged");
  });
});
