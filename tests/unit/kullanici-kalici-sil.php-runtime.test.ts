import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

describe("kullanici kalıcı sil: the safe hard-delete owner and its fail-closed eligibility (MariaDB runtime)", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("deletes only truly independent accounts and refuses every protected or unverifiable case", () => {
    const result = runPhpMysqlRunner(
      resolve(process.cwd(), "tests/php/KullaniciKaliciSilMysqlTestRunner.php")
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    if (String(result.stdout).includes("SKIP:")) {
      expect(result.stdout).toContain("Disposable MariaDB");
      return;
    }

    const stdout = String(result.stdout);
    for (const marker of [
      "the canonical chain tip is migration 099",
      "migration 099 refuses a populated database without verified protected-account IDs",
      "migration 099 leaves no partial audit table when the protected-account IDs are missing",
      "migration 099 leaves no partial protected-account flag when the IDs are missing",
      "migration 099 leaves no partial registry table when the IDs are missing",
      "migration 099 refuses a populated database with an orphaned FK-less reference",
      "migration 099 leaves no partial audit table when an orphan is present",
      "migration 099 records only the operator-verified protected-account IDs",
      "migration 099 flags the verified protected accounts without username matching",
      "migration 099 created the kalici silme audit table",
      "the audit actor column carries a foreign key to users",
      "the append-only trigger trg_ksa_no_update is present",
      "the append-only trigger trg_ksa_no_delete is present",
      "an unprivileged actor is refused with 403 for eligibility",
      "an unprivileged actor is refused with 403 for deletion",
      "the refused actor changed no user row",
      "a standalone account is reported SİLİNEBİLİR",
      "the protected manager account ilkerA is reported ENGELLENDİ",
      "ilkerA names the protected account blocker",
      "the protected manager account serhan.kose is reported ENGELLENDİ",
      "serhan.kose names the protected account blocker",
      "a GENEL_YONETICI account is reported ENGELLENDİ",
      "the GENEL_YONETICI account names the admin role blocker",
      "self-delete is reported ENGELLENDİ",
      "self-delete names the self blocker",
      "a personnel-bound account is reported ENGELLENDİ",
      "the personnel-bound account names the binding blocker",
      "the standalone account is physically deleted",
      "the delete result confirms deletion",
      "the deleted user row no longer exists",
      "the scope cleanup removed exactly the one user_subeler row",
      "no scope row survives the deleted user",
      "the deleted user left immutable audit evidence",
      "the audit names the deleted target id",
      "the audit names the deleted username",
      "the audit names the real actor",
      "the audit carries the mandatory justification",
      "the audit carries the request fingerprint",
      "kalici silme audit rows cannot be updated",
      "kalici silme audit rows cannot be deleted",
      "resubmitting the same delete is refused with 404",
      "the resubmit names NOT_FOUND",
      "a dependency-carrying account is reported ENGELLENDİ",
      "the dependency blocker names DEPENDENCY_EXISTS",
      "the dependency-carrying deletion is refused with 409",
      "the blocked account was not deleted",
      "the cascade/set-null account is reported ENGELLENDİ",
      "a CASCADE reference is inventoried with its CASCADE rule",
      "a SET NULL reference is inventoried with its SET NULL rule",
      "multiple simultaneous references are each reported as blockers",
      "a failure writing audit evidence fails the request",
      "the rollback left the user row intact",
      "the rollback restored the scope row",
      "the rollback left no partial audit row",
      "an unknown dependency resolves to DOĞRULANAMADI",
      "the unverified entry names DEPENDENCY_UNVERIFIED",
      "the Erişimi Kaldır owner still succeeds",
      "the revocation wrote exactly one row into its own audit table",
      "the revocation wrote nothing into the kalici silme audit table",
      "the revocation deactivated the account without deleting it",
      "the revoked account still exists (not deleted)",
      "migration 099 added the stable protected-account flag",
      "migration 099 created the explicit protected-account registry",
      "migration 099 protects the classified reference legal_holdlar.released_by with a users FK",
      "the classified legal_holdlar.released_by reference is inventoried",
      "a classified reference blocks deletion",
      "the classified reference names DEPENDENCY_EXISTS",
      "a new unclassified user-reference column resolves to DOĞRULANAMADI",
      "the unclassified column names DEPENDENCY_UNVERIFIED",
      "a flagged protected account is reported ENGELLENDİ",
      "the flag names the protected account blocker",
      "a renamed protected account stays ENGELLENDİ",
      "the renamed account still names the protected blocker via the stable flag",
      "C0 the canonical runner drains the full chain (incl. 067) on an empty database",
      "C0 067 (company-specific catalog correction) is ledgered without execution on an empty catalog",
      "C0 the fresh ledger verifies against the canonical chain",
      "C0 the full chain applies to an empty database without any company-specific account",
      "C1 a weak password is refused and nothing is written",
      "C2 the first admin is an active GENEL_YONETICI flagged silinmesi_korunur",
      "C3 the first admin password is stored hashed and verifies",
      "C4 the bootstrap refuses once any GENEL_YONETICI exists (one-time, no second admin)",
      "C5 Kalıcı Sil eligibility works on a fresh install with an empty registry",
      "C6 the first admin cannot delete itself",
      "C7 Kalıcı Sil deletes an independent old account on a fresh install",
      "C8 the fresh-install delete is audited",
      "B1 an empty protected-account registry no longer disables eligibility (person-independent)",
      "B2 a silinmesi_korunur account stays protected without any registry row",
      "B3 the flag alone refuses deleting a protected account",
      "B4 a GENEL_YONETICI target stays blocked by role, independent of registry",
      "B5 a registry row whose user lacks the flag resolves eligibility to DOĞRULANAMADI",
      "B6 registry drift refuses deletion with 409",
      "B7 the registry drift refusal deleted nothing",
      "the first delete acquires the advisory lock",
      "a concurrent delete cannot acquire the held advisory lock",
      "the final dependency check sees no released_by reference",
      "a classified late writer is blocked while the target row is locked after final verification",
      "the raced account was deleted only after the late writer was blocked",
      "the classified late writer cannot create an orphan after the user DELETE",
      "a missing audit trigger resolves eligibility to DOĞRULANAMADI",
      "the missing-trigger case names AUDIT_SCHEMA_NOT_READY",
      "the missing-trigger deletion is refused with 409",
      "the missing-trigger refusal deleted nothing",
      "a missing audit table resolves eligibility to DOĞRULANAMADI",
      "the missing-audit-table deletion is refused with 409",
      "the missing-audit-table refusal deleted nothing",
      "verify-kullanici-kalici-sil-mysql: OK",
    ]) {
      expect(stdout, marker).toContain(marker);
    }
    expect(stdout).not.toContain("[FAIL]");
  }, 300_000);
});
