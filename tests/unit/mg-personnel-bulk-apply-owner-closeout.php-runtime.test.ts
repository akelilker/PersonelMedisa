import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("MG personnel bulk apply owner closeout (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("covers incomplete create, bulk org delegation, postcheck and idempotency guards", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/MgPersonnelBulkApplyOwnerCloseoutMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "strict create without intent rejects missing IC org fields",
      "unauthorized actor cannot incomplete-create",
      "sube_id remains NULL",
      "external incomplete create keeps SGK NULL",
      "completeness includes pozisyon gap",
      "postcheck total 153 for 14 creates",
      "nine-create plan cannot claim 153/146",
      "bulk dry-run org row is READY",
      "bulk apply org update wrote audit row",
      "stale preimage blocks apply",
      "retry does not duplicate audit rows",
      "manager resolution does not create users",
    ]) {
      expect(stdout).toContain(marker);
    }
    expect(stdout).toContain("verify-mg-personnel-bulk-apply-owner-closeout-mysql: OK");
    expect(stdout).not.toContain("[FAIL]");
    expect(stdout).not.toMatch(/\b\d{11}\b/);
  });
});
