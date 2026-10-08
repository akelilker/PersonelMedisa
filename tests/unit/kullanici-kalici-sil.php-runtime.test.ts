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
      "verify-kullanici-kalici-sil-mysql: OK",
    ]) {
      expect(stdout, marker).toContain(marker);
    }
    expect(stdout).not.toContain("[FAIL]");
  }, 300_000);
});
