import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/PersonelFirstLoginCredentialsMysqlTestRunner.php");

describe("PERSONEL canonical first-login credential disposable MariaDB acceptance", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("proves canonical username, template password, forced change, ilkerA invariant and fail-closed cohorts", () => {
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
    expect(result.stdout).not.toContain("[FAIL]");
  });
});
