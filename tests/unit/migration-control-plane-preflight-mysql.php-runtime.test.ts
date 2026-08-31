import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("migration 079 control plane: preflight, backup and the company/branch hierarchy (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("proves the preflight is read-only, the backup is verifiable and 079 only adds hierarchy structure", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/MigrationControlPlanePreflightMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    for (const marker of [
      "the withdrawn monthly-close 079 is absent from the migration source",
      "the canonical source carries exactly one migration 079",
      "the single 079 is the hierarchy migration",
      "preflight leaves schema, data and ledger byte-identical",
      "preflight reports production tip 078",
      "preflight reports code tip 079",
      "pending list contains only the hierarchy migration 079",
      "ledger has no checksum mismatch and no gap",
      "the withdrawn 079 is not pending",
      "a pre-079 database is not apply-ready for the current round",
      "the blocker names the chain shape it refused instead of failing silently",
      "sirketler carries id, kod, ad and durum",
      "company code and name are globally unique",
      "foreign key fk_subeler_sirket points at sirketler with ON DELETE RESTRICT",
      "foreign key fk_sgk_isverenler_sirket points at sirketler with ON DELETE RESTRICT",
      "foreign key fk_calisma_lokasyonlari_sube points at subeler with ON DELETE RESTRICT",
      "foreign key fk_user_sirketler_user points at users with ON DELETE CASCADE",
      "foreign key fk_user_sirketler_sirket points at sirketler with ON DELETE RESTRICT",
      "foreign key fk_user_sgk_isverenler_user points at users with ON DELETE CASCADE",
      "foreign key fk_user_sgk_isverenler_sgk points at sgk_isverenler with ON DELETE RESTRICT",
      "relation index idx_subeler_sirket exists",
      "relation index idx_sgk_isverenler_sirket exists",
      "the migration seeds no company and copies no user scope",
      "branch ids survive verbatim, including the 3 that does not exist in production",
      "no branch is renamed: a name that already carries the company prefix is left alone",
      "no branch or payroll employer is mapped to a company by the migration",
      "the branch manager keeps exactly the branch assignment it had",
      "personnel branch and payroll axes stay independent and unmoved",
      "a second full rerun is a no-op",
      "the idempotent rerun changes neither schema nor data",
      "a compatible partial state resumes instead of failing",
      "the resume restores exactly the missing pieces",
      "the resumed schema is identical to the one a single clean run produces",
      "an applied hierarchy reads as the expected preimage for the next round"
    ]) {
      expect(result.stdout).toContain(marker);
    }

    expect(result.stdout).toContain("verify-migration-control-plane-preflight-mysql: OK");
  });
});
