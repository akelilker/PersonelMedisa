import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("organisation audit owners: migration 080, permanent branch change, branch create and user scope (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("applies migration 080, keeps the audit tables append-only and rolls every write back when the audit fails", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/OrganizasyonAuditOwnersMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "migration 079 up",
      "migration 080 up: personel_sube_degisiklik_auditleri",
      "migration 080 up: sube_olusturma_auditleri",
      "migration 080 up: user_org_scope_auditleri",
      "migration 080 is idempotent on re-run",
      "audit foreign keys present",
      "index idx_psda_personel_created present",
      "index uq_soa_sube present",
      "index idx_uosa_target_created present",
      "role BOLUM_YONETICISI cannot permanently move a person",
      "role MUHASEBE cannot permanently move a person",
      "role IK_SORUMLUSU cannot permanently move a person",
      "denied roles left the branch untouched",
      "a stale expected branch is refused",
      "a missing target branch is refused",
      "an inactive target branch is refused",
      "an SGK employer / target company mismatch is refused",
      "a missing justification is refused",
      "no refused attempt left an audit row",
      "an unwritable audit row aborts the move",
      "audit failure rolled the branch change back",
      "authorised move succeeds",
      "sube_id moved to the target branch",
      "sgk_isveren_id preserved",
      "calisma_lokasyonu_id preserved",
      "audit records the exact before branch",
      "audit records the exact after branch",
      "audit records the preserved work location",
      "audit records the preserved SGK employer",
      "audit records the justification",
      "a retried move is refused as stale rather than reapplied",
      "a retry produced no duplicate audit row",
      "personnel branch audit rows cannot be updated",
      "personnel branch audit rows cannot be deleted",
      "branch create audit names the branch",
      "branch create audit records a canonical department list",
      "the derived display name is unchanged by auditing",
      "a duplicate branch code still conflicts",
      "a rejected branch create left no audit row",
      "an unwritable audit row aborts branch creation",
      "audit failure rolled the branch insert back",
      "audit failure rolled the department writes back",
      "the legacy flat branch route audits through the same owner",
      "branch create audit rows cannot be updated",
      "a changed branch scope produces an audit row",
      "scope audit records the exact sorted before set",
      "scope audit records the exact sorted after set",
      "a resent identical scope set produces no audit row",
      "an unwritable scope audit aborts the transaction",
      "audit failure rolled the scope mutation back",
      "scope audit rows cannot be deleted",
    ]) {
      expect(stdout).toContain(marker);
    }
    expect(stdout).toContain("verify-organizasyon-audit-owners-mysql: OK");
    expect(stdout).not.toContain("[FAIL]");
  });
});
