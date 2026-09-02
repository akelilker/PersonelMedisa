import { describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

describe("SubeReadModel unit (no DB)", () => {
  it("composes short/full display names without inventing Karyapı/Şenay names", () => {
    const result = spawnSync(
      "php",
      [resolve(process.cwd(), "tests/php/SubeReadModelUnitTestRunner.php")],
      { encoding: "utf8" }
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[PASS] Medisa Ankara");
    expect(result.stdout).toContain("[PASS] Medisa Fabrika");
    expect(result.stdout).toContain("[PASS] Karyapı no duplication");
    expect(result.stdout).toContain("[PASS] Şenay no duplication");
    expect(result.stdout).toContain("verify-sube-read-model-unit: OK");
  });
});
