import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("MG personnel bulk postcheck dynamic contract (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 120_000);

  it("passes reconciliation scenarios and blocks stale or tampered contracts", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/MgPersonnelBulkPostcheckDynamicContractMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "scenario A expected total 153",
      "scenario A expected active 146",
      "scenario B expected active 144",
      "org-only plan has zero total delta",
      "stale fingerprint blocks apply",
      "checksum drift blocks apply",
      "pasif exit preimage blocked in dry-run",
      "tampered exit preimage fails validateContract",
      "duplicate replay writes no extra audit",
      "inventory drift blocks apply with stale checksum",
    ]) {
      expect(stdout).toContain(marker);
    }
    expect(stdout).toContain("verify-mg-personnel-bulk-postcheck-dynamic-contract-mysql: OK");
    expect(stdout).not.toContain("[FAIL]");
  });
});
