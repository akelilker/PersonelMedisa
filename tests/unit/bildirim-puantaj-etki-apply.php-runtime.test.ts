import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const runnerPath = resolve(process.cwd(), "tests/php/BildirimPuantajEtkiApplyTestRunner.php");

describe("BildirimPuantajEtkiApplyService php runtime", () => {
  it("passes apply mapping/hash/state scenarios", () => {
    const result = spawnSync("php", [runnerPath], {
      encoding: "utf8",
      cwd: process.cwd()
    });
    expect(result.status).toBe(0);
    expect(result.stdout).toContain("verify-bildirim-puantaj-etki-apply: OK");
    expect(result.stdout).toContain("[PASS] GEC dakika mapping authoritative");
    expect(result.stdout).toContain("[PASS] UCRETSIZ IZIN blocked");
    expect(result.stdout).toContain("[PASS] GOREVDE mapping");
    expect(result.stdout).toContain("[PASS] hash deterministic");
  });

  it("keeps apply runner outside api/tests", () => {
    expect(() =>
      readFileSync(resolve(process.cwd(), "api/tests/BildirimPuantajEtkiApplyTestRunner.php"))
    ).toThrow();
  });
});
