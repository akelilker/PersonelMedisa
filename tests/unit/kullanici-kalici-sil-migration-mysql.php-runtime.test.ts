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
      "verify-kullanici-kalici-sil-migration-mysql: OK",
    ]) {
      expect(stdout, marker).toContain(marker);
    }
    expect(stdout).not.toContain("[FAIL]");
  }, 300_000);
});
