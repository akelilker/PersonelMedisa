import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("kullanıcı kalıcı sil 099 migration owner: preflight + apply + recovery (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("fails closed on missing/invalid ids and orphans, applies cleanly, and detects partial state", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/KullaniciKaliciSilMigrationMysqlTestRunner.php"),
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "preflight blocks missing protected-account ids",
      "missing ids leave no audit table behind",
      "preflight blocks a nonexistent protected-account id",
      "preflight blocks an orphaned FK-less reference",
      "apply refuses to run while an orphan is present",
      "an orphan leaves no audit table behind before the first DDL",
      "preflight passes with verified ids and no orphan",
      "apply applied exactly migration 099",
      "postcheck confirms the applied 099 round",
      "the ledger records migration 099",
      "the registry records only the verified protected-account ids",
      "the verified accounts carry the protected flag",
      "migration 099 protected ek_odeme_kesinti.created_by with a RESTRICT users FK",
      "migration 099 protected retention_imha_auditleri.actor_user_id with a RESTRICT users FK",
      "a second run is refused on an already-applied ledger",
      "preflight flags a pre-existing partial fk_p099_* constraint",
      "the partial-state report names the exact pre-existing constraint",
      "the 099 backup is read back and verified",
      "the 099 backup scope covers users, the ten FK tables and the ledger",
      "the 099 backup captures the ten FK tables schema-only",
      "the 099 backup captures the full users row set",
      "the 099 backup captures the full ledger preimage",
      "the 099 backup metadata never carries the server path",
      "the 099 dump exists on disk outside the webroot",
      "the 099 dump on disk hashes to the published digest",
      "the 099 dump carries user rows",
      "the 099 dump does not copy ek_odeme_kesinti rows (schema-only)",
      "the 099 dump never drops the schema-only table offline_mutation_idempotency",
      "the 099 dump carries the row-safe FK rollback for offline_mutation_idempotency",
      "the 099 backup preimage apply succeeds",
      "099 added the protected-account flag before restore",
      "restoring the 099 backup removes the protected-account flag",
      "restoring the 099 backup removes every fk_p099_* constraint",
      "restoring the 099 backup removes the 099 ledger row",
      "restoring the 099 backup returns the verified users",
      "restoring the 099 backup preserves the offline_mutation_idempotency row",
      "restoring the 099 backup keeps the retention_imha_auditleri table",
      "verify-kullanici-kalici-sil-migration-mysql: OK",
    ]) {
      expect(stdout, marker).toContain(marker);
    }
    expect(stdout).not.toContain("[FAIL]");
  }, 300_000);
});
