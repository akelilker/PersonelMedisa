import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/HaftalikKapanisMysqlTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/HaftalikKapanisController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");
const permissionsSource = readFileSync(
  resolve(process.cwd(), "api/src/Auth/RolePermissions.php"),
  "utf8"
);
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/027_haftalik_kapanis.sql"),
  "utf8"
);

describe("HaftalikKapanisController MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks router owners, permissions and additive migration", () => {
    expect(routerSource).toContain("HaftalikKapanisController::create");
    expect(routerSource).toContain("HaftalikKapanisController::detail");
    expect(routerSource).toContain("HaftalikKapanisController::yillikFazlaCalisma");

    const yillikPos = routerSource.indexOf("/haftalik-kapanis/yillik-fazla-calisma");
    const idRegexPos = routerSource.indexOf("#^/haftalik-kapanis/(\\d+)$#");
    expect(yillikPos).toBeGreaterThan(-1);
    expect(idRegexPos).toBeGreaterThan(-1);
    expect(yillikPos).toBeLessThan(idRegexPos);

    expect(controllerSource).toContain("puantaj.muhurle");
    expect(controllerSource).toContain("puantaj.view");
    expect(permissionsSource).toContain("'puantaj.muhurle'");
    expect(permissionsSource).toContain("'puantaj.view'");

    expect(migrationSource).toMatch(/CREATE TABLE\s+haftalik_kapanislar\s*\(/);
    expect(migrationSource).toMatch(/CREATE TABLE\s+haftalik_kapanis_satirlari\s*\(/);
    expect(migrationSource).not.toContain("CREATE TABLE IF NOT EXISTS");
    expect(migrationSource).not.toMatch(/\bDROP\b/);
    expect(migrationSource).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migrationSource).not.toMatch(/(?:^|;)\s*UPDATE\b/im);
    expect(migrationSource).toContain("ON DELETE RESTRICT");
    expect(migrationSource).not.toContain("ON DELETE CASCADE");
    expect(migrationSource).toContain("departman_scope_key");
    expect(migrationSource).toContain("uq_haftalik_kapanis_scope");

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => name.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("035_personel_bordro_kapsamlari.sql");
  });

  it("runs HTTP haftalik kapanis acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-haftalik-kapanis-mysql: OK");
    expect(result.stdout).toContain("[PASS] partial existing haftalik_kapanislar → migration fails");
    expect(result.stdout).toContain("[PASS] unauthenticated POST → 401");
    expect(result.stdout).toContain("[PASS] PATRON (no puantaj.muhurle) POST → 403");
    expect(result.stdout).toContain("[PASS] MUHASEBE (has view, no muhurle) POST → 403");
    expect(result.stdout).toContain("[PASS] GY without active sube header → 422");
    expect(result.stdout).toContain("[PASS] GY POST without mutabakat → 409 STATE_CONFLICT");
    expect(result.stdout).toContain("[PASS] GY POST with mutabakat week → 201");
    expect(result.stdout).toContain("[PASS] detail GET → 200");
    expect(result.stdout).toContain("[PASS] duplicate POST same scope → 409 STATE_CONFLICT");
    expect(result.stdout).toContain("[PASS] different departman_id same week → 201");
    expect(result.stdout).toContain("[PASS] null departman vs departman 3 are different (two creates)");
    expect(result.stdout).toContain("[PASS] GET missing id → 404");
    expect(result.stdout).toContain("[PASS] GET scope dışı (BA sube1 viewing sube2) → 403");
    expect(result.stdout).toContain("[PASS] non-Monday hafta_baslangic → 422");
    expect(result.stdout).toContain("[PASS] wrong hafta_bitis → 422");
    expect(result.stdout).toContain("[PASS] server-owned id/state in body → 422");
    expect(result.stdout).toContain("[PASS] yillik aggregate 0 boundary");
    expect(result.stdout).toContain("[PASS] yillik aggregate 16199 boundary");
    expect(result.stdout).toContain("[PASS] yillik aggregate 16200 boundary");
    expect(result.stdout).toContain("[PASS] yillik aggregate 16201 boundary");
    expect(result.stdout).toContain("[PASS] tam_hafta_verisi=false excluded from aggregate");
    expect(result.stdout).toContain("[PASS] ISO year boundary week counted in 2026");
    expect(result.stdout).toContain(
      "[PASS] concurrency: two parallel POST same identity → one 201 one 409"
    );
    expect(result.stdout).toContain("[PASS] concurrency DB count=1");
    expect(result.stdout).toContain("[PASS] FK personel DELETE RESTRICT");
    expect(result.stdout).toContain("[PASS] FK sube DELETE RESTRICT");
    expect(result.stdout).toContain("[PASS] FK DELETE_RULE RESTRICT/NO ACTION");
    expect(result.stdout).toContain("[PASS] transaction: no partial kapanis row");
    expect(result.stdout).toContain("[PASS] BOLUM_YONETICISI POST scope içi → 201");
    expect(result.stdout).toContain("[PASS] BOLUM_YONETICISI detail → 200");
    expect(result.stdout).toContain("[PASS] BOLUM_YONETICISI YFC → 200");
    expect(result.stdout).toContain("[PASS] MUHASEBE detail scope içi → 200");
    expect(result.stdout).toContain("[PASS] MUHASEBE YFC scope içi → 200");
    expect(result.stdout).toContain("[PASS] BIRIM_AMIRI detail scope içi → 200");
    expect(result.stdout).toContain("[PASS] BIRIM_AMIRI YFC scope içi → 200");
    expect(result.stdout).toContain("[PASS] PATRON detail → 403");
    expect(result.stdout).toContain("[PASS] PATRON YFC → 403");
    expect(result.stdout).toContain("[PASS] BA empty allowedSubeIds global YFC → 403");
    expect(result.stdout).toContain("[PASS] open GONDERILDI blocks genel kapanis → 409");
    expect(result.stdout).toContain(
      "[PASS] open other departman does not block dept 3 close → 201"
    );
    expect(result.stdout).toContain(
      "[PASS] snapshot immutability: live puantaj change does not alter GET"
    );
    expect(result.stdout).toContain(
      "[PASS] aggregate double-count: max kapanis_id wins once"
    );
    expect(result.stdout).toContain("[PASS] concurrency different departman → both 201");
    expect(result.stdout).toContain("[PASS] server-owned departman_scope_key → 422");
    expect(result.stdout).toContain(
      "[PASS] partial existing haftalik_kapanis_satirlari → migration fails"
    );
  });
});
