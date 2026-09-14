import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("MG personnel bulk multi-axis preimage rebase (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("rebases overlapping axis preimages inside one row transaction", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/MgPersonnelBulkMultiAxisPreimageRebaseMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "rebase dry-run is can_apply",
      "both rows plan as MULTI_AXIS with basic and organization axes",
      "org axis preimage still carries the pre-basic overlapping bagli_amir_id",
      "preimage rebase starts from a clean idempotency ledger",
      "overlapping multi-axis rows apply without PERSONEL_ORGANIZASYON_STALE_PREIMAGE",
      "multi-axis rows keep independent row transactions (no 160/211 split path)",
      "basic axis manager and org axis gorev land together for the NULL-preimage row",
      "each row writes exactly one org audit and one idempotency ledger row in one transaction",
      "external preimage drift after dry-run stays fail-closed",
    ]) {
      expect(stdout).toContain(marker);
    }
    expect(stdout).toContain("verify-mg-personnel-bulk-multi-axis-preimage-rebase-mysql: OK");
    expect(stdout).not.toContain("[FAIL]");
  });
});
