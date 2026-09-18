import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelFirstLoginCredentialsMysqlTestRunner.php");

describe("PERSONEL canonical first-login credential disposable MariaDB acceptance", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("proves canonical username, template password, forced change, ilkerA invariant, read-only preflight and fail-closed cohorts", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("[DONE] PersonelFirstLoginCredentialsMysqlTestRunner");
    expect(result.stdout).toContain("[PASS] A: Serhan Kose baslangic sifresi Kose123 hash ile dogrulandi");
    expect(result.stdout).toContain("[PASS] B: ÇELİK -> Celik123");
    expect(result.stdout).toContain("[PASS] C: template credential ile login SUCCESS (token)");
    expect(result.stdout).toContain("[PASS] D: DB must_change_password = 0");
    expect(result.stdout).toContain("[PASS] E: eski template sifresi DENIED");
    expect(result.stdout).toContain("[PASS] F: hedef activation_required = 0");
    expect(result.stdout).toContain("[PASS] G: rezerve hesap before/after exact invariant");
    expect(result.stdout).toContain("[PASS] H: collision halinde hicbir satir mutate edilmedi");
    expect(result.stdout).toContain("[PASS] I: anomaly user 14 untouched");
    expect(result.stdout).toContain("[PASS] BIZ: USERNAME_COLLISION_COUNT = 0");
    expect(result.stdout).toContain("[PASS] BIZ: NAME_UNRESOLVED_COUNT = 0");
    expect(result.stdout).toContain("[PASS] BIZ: plan personel 108 -> hakanAc");
    expect(result.stdout).toContain("[PASS] BIZ: plan personel 109 -> hakanAt");
    expect(result.stdout).toContain("[PASS] BIZ: plan personel 206 -> abdullah");
    expect(result.stdout).toContain("[PASS] BIZ: plan personel 200 -> raedF");
    expect(result.stdout).toContain("[PASS] BIZ: plan personel 201 -> saifA");
    expect(result.stdout).toContain("[PASS] BIZ: 207 name correction uygulandi");
    expect(result.stdout).toContain("[PASS] BIZ: 207 template sifre hash dogrulandi");
    expect(result.stdout).toContain("[PASS] ABDULLAH_SURNAME_MUTATED=NO (206 ad/soyad dokunulmadi)");
    expect(result.stdout).toContain("[PASS] L: template credential ilk giris SUCCESS (raedF / Fawaz123)");
    expect(result.stdout).toContain("[PASS] M: forced password change PASS");
    expect(result.stdout).toContain("[PASS] N: yeni sifre ile login SUCCESS");
    expect(result.stdout).toContain("[PASS] O: eski template sifresi DENIED");
    expect(result.stdout).toContain("[PASS] P: PASIF anomaly login fail-closed");
    expect(result.stdout).toContain("[PASS] K: ilkerA before/after exact invariant");

    // PERSONEL_FIRST_LOGIN_PREFLIGHT: read-only control-plane report owner, disposable
    // DB uzerinde PASS + ilkera_touched=false + beklenen plan + sifir mutation kaniti.
    expect(result.stdout).toContain("[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: result PASS");
    expect(result.stdout).toContain("[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: production_mutation_count = 0");
    expect(result.stdout).toContain("[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: decision_apply = false");
    expect(result.stdout).toContain("[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: ilkera_touched = false");
    expect(result.stdout).toContain("[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: cohort reconcile dogrulandi");
    expect(result.stdout).toContain("[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: beklenen username plan");
    expect(result.stdout).toContain("[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: plan satiri sinirli alan kumesi");
    expect(result.stdout).toContain("[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: users BEFORE == AFTER (sifir mutation)");
    expect(result.stdout).toContain(
      "[PASS] PERSONEL_FIRST_LOGIN_PREFLIGHT: personeller BEFORE == AFTER (sifir mutation)",
    );
    expect(result.stdout).not.toContain("[FAIL]");
  });
});
