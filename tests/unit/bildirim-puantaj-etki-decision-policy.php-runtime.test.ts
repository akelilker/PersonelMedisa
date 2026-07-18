import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { phpQuietCliArgs } from "../scripts/php-cli-args.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/BildirimPuantajEtkiDecisionPolicyTestRunner.php");
const policyPath = resolve(process.cwd(), "api/src/Services/BildirimPuantajEtkiDecisionPolicy.php");
const policySource = readFileSync(policyPath, "utf8");

describe("BildirimPuantajEtkiDecisionPolicy PHP runtime", () => {
  it("runs 16 decision policy scenarios via PHP CLI", () => {
    // Local php.ini duplicates mysqli and leaves display_errors=1; startup Warnings
    // were landing on stdout and breaking JSON.parse. Keep stdout JSON-only.
    const output = execFileSync("php", [...phpQuietCliArgs(), runnerPath], { encoding: "utf8" });
    const result = JSON.parse(output.trim()) as {
      total: number;
      passed: number;
      failed: number;
      failures: string[];
    };
    expect(result.total).toBe(16);
    expect(result.passed).toBe(16);
    expect(result.failed).toBe(0);
    expect(result.failures).toEqual([]);
  });

  it("locks permission and state constants in policy service", () => {
    expect(policySource).toContain("puantaj.bildirim_etki.apply");
    expect(policySource).toContain("puantaj.bildirim_etki.dismiss");
    expect(policySource).toContain("'UYGULANDI'");
    expect(policySource).toContain("'YOK_SAYILDI'");
    expect(policySource).toContain("isApplyAllowed");
    expect(policySource).toContain("isDismissAllowed");
    expect(policySource).not.toContain("MIN_DISMISS_REASON_LENGTH");
    expect(policySource).not.toContain("validateDismissReason");
    expect(policySource).not.toContain("MANUEL_INCELEME");
  });

  it("does not write to operational tables", () => {
    expect(policySource).not.toMatch(/INSERT\s+INTO\s+gunluk_puantaj/i);
    expect(policySource).not.toMatch(/UPDATE\s+gunluk_puantaj/i);
    expect(policySource).not.toMatch(/UPDATE\s+onayli_bildirim_puantaj_etki_adaylari/i);
  });

  it("test runner lives outside production api tree", () => {
    expect(runnerPath.replace(/\\/g, "/")).toContain("tests/php/");
    expect(() =>
      readFileSync(resolve(process.cwd(), "api/tests/BildirimPuantajEtkiDecisionPolicyTestRunner.php"))
    ).toThrow();
  });
});
