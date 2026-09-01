import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("user access change audit: migration 082 and the fail-closed update owner (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("audits every security-impacting user change exactly once and rolls back when it cannot", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/UserAccessChangeAuditMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "the canonical chain tip is migration 082",
      "migration 082 created the access change audit table",
      "actor and target both carry a foreign key to users",
      "index idx_ueda_target_created present",
      "index idx_ueda_event_created present",
      "the restore fixture starts from the recorded production preimage",
      "an unprivileged actor is refused with 403",
      "the refused actor wrote no audit row",
      "a taken username is refused with 409",
      "the duplicate username attempt wrote no audit row",
      "a security change without migration 082 is refused with 409",
      "the refusal names USER_ACCESS_AUDIT_SCHEMA_NOT_READY",
      "a non-security edit still succeeds without migration 082",
      "binding an already bound personnel record is refused with 409",
      "the binding conflict rolled the status and role back too",
      "an unwritable audit row fails the request",
      "audit failure rolled the username, status, role and binding back",
      "audit failure rolled the credential reset back",
      "audit failure left no partial audit row",
      "the combined access restore succeeds",
      "username moved from 042 to sinemH",
      "status moved from PASIF to AKTIF",
      "role moved to GENEL_YONETICI",
      "the account is bound to personnel 173",
      "the credential reset forces a password change",
      "the restore added no explicit scope row",
      "the multi-field restore produced exactly one audit row",
      "the event is typed COMBINED_ACCESS_CHANGE",
      "the audit names the real actor",
      "the audit carries the exact status before and after",
      "the audit carries the exact role before and after",
      "the audit carries the exact username before and after",
      "the audit carries the exact personnel binding before and after",
      "the audit row carries no password_hash column",
      "access change audit rows cannot be updated",
      "access change audit rows cannot be deleted",
      "a non-security edit produced no additional access audit row",
      "a role-only change is typed ROLE_CHANGE",
      "a username-only change is typed USERNAME_CHANGE",
      "a lone reactivation is typed ACCESS_RESTORE",
      "a binding-only change is typed PERSONEL_BINDING_CHANGE",
      "resending unchanged access values produced no audit row",
      "the revocation wrote exactly one row into its own audit table",
      "the revocation owner wrote nothing into the access change table",
      "verify-user-access-change-audit-mysql: OK",
    ]) {
      expect(stdout, marker).toContain(marker);
    }
    expect(stdout).not.toContain("[FAIL]");
  }, 300_000);
});
