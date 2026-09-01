import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("MG personnel bulk dry-run apply parity (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("blocks bad hierarchy in dry-run and applies inferred hierarchy once", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/MgPersonnelBulkDryRunApplyParityMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "invalid bolum without departman is blocked in dry-run",
      "dry-run plan carries inferred departman_id=1",
      "canonical create payload from dry-run plan inserts successfully",
      "dry-run and apply share canonical_ready create payload",
      "turkish departman name resolves to canonical row",
      "ambiguous reference name resolves to null",
      "dry-run blocked row leaves personel count unchanged",
      "idempotent replay preserves single create row",
    ]) {
      expect(stdout).toContain(marker);
    }
    expect(stdout).toContain("verify-mg-personnel-bulk-dry-run-apply-parity-mysql: OK");
    expect(stdout).not.toContain("[FAIL]");
  });
});
