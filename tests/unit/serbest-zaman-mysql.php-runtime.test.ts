import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/SerbestZamanMysqlTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/SerbestZamanController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/029_serbest_zaman_events.sql"),
  "utf8"
);

describe("SerbestZamanController MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks router owners, permissions and additive migration", () => {
    expect(routerSource).toContain("SerbestZamanController::listEvents");
    expect(routerSource).toContain("SerbestZamanController::bakiye");
    expect(routerSource).toContain("SerbestZamanController::olusum");
    expect(routerSource).toContain("SerbestZamanController::kullanim");
    expect(routerSource).toContain("SerbestZamanController::iptal");
    expect(routerSource).toContain("SerbestZamanController::duzeltme");

    expect(controllerSource).toContain("puantaj.view");
    expect(controllerSource).toContain("puantaj.muhurle");
    expect(controllerSource).not.toContain("PERIOD_LOCKED");
    expect(controllerSource).not.toContain("PERIOD_STATE_UNKNOWN");

    expect(migrationSource).toMatch(/CREATE TABLE\s+serbest_zaman_events\s*\(/);
    expect(migrationSource).toMatch(/CREATE TABLE\s+serbest_zaman_aktif_olusumlar\s*\(/);
    expect(migrationSource).not.toContain("CREATE TABLE IF NOT EXISTS");
    expect(migrationSource).not.toMatch(/\bDROP\b/);
    expect(migrationSource).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migrationSource).not.toMatch(/(?:^|;)\s*UPDATE\b/im);
    expect(migrationSource).toContain("ON DELETE RESTRICT");
    expect(migrationSource).not.toContain("ON DELETE CASCADE");
    expect(migrationSource).toContain("uq_sz_personel_islem_anahtari");
    expect(migrationSource).toContain("uq_sz_iptal_hedef");

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => name.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("029_serbest_zaman_events.sql");
  });

  it("runs HTTP serbest zaman acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-serbest-zaman-mysql: OK");
    expect(result.stdout).toContain("[PASS] SHOW CREATE TABLE serbest_zaman_events");
    expect(result.stdout).toContain("[PASS] SHOW CREATE TABLE serbest_zaman_aktif_olusumlar");
    expect(result.stdout).toContain("[PASS] uq_sz_personel_islem_anahtari present");
    expect(result.stdout).toContain("[PASS] uq_sz_iptal_hedef present");
    expect(result.stdout).toContain("[PASS] controller NO PERIOD_LOCKED");
    expect(result.stdout).toContain("[PASS] controller NO PERIOD_STATE_UNKNOWN");
    expect(result.stdout).toContain("[PASS] unauthenticated → 401");
    expect(result.stdout).toContain("[PASS] PATRON GET events → 403");
    expect(result.stdout).toContain("[PASS] GY GET events personel 10 → 200 empty items");
    expect(result.stdout).toContain("[PASS] MUHASEBE GET → 200");
    expect(result.stdout).toContain("[PASS] MUHASEBE POST olusum → 403");
    expect(result.stdout).toContain("[PASS] BA scope dışı personel 20 → 403");
    expect(result.stdout).toContain("[PASS] BA empty allowedSubeIds → 403");
    expect(result.stdout).toContain("[PASS] GY POST olusum → 200");
    expect(result.stdout).toContain("[PASS] olusum dakika=90 (60*1.5)");
    expect(result.stdout).toContain("[PASS] guard row exists");
    expect(result.stdout).toContain("[PASS] olusum again ALREADY_EXISTS");
    expect(result.stdout).toContain("[PASS] sealed period POST kullanim → 200");
    expect(result.stdout).toContain("[PASS] kullanim donem_kilitli_miydi true");
    expect(result.stdout).toContain("[PASS] bakiye kalan 60");
    expect(result.stdout).toContain("[PASS] same islem_anahtari retry → 200");
    expect(result.stdout).toContain("[PASS] same islem_anahtari same id");
    expect(result.stdout).toContain("[PASS] IDEMPOTENCY_CONFLICT");
    expect(result.stdout).toContain("[PASS] INSUFFICIENT_BALANCE");
    expect(result.stdout).toContain("[PASS] POST iptal OLUSUM → 200");
    expect(result.stdout).toContain("[PASS] guard deleted after iptal");
    expect(result.stdout).toContain("[PASS] ALREADY_CANCELLED");
    expect(result.stdout).toContain("[PASS] POST olusum again after iptal → 200");
    expect(result.stdout).toContain("[PASS] duzeltme missing aciklama → 422");
    expect(result.stdout).toContain("[PASS] server-owned sube_id in body → 422");
    expect(result.stdout).toContain("[PASS] GET events sort order event_tarihi ASC, id ASC");
    expect(result.stdout).toContain("[PASS] GET bakiye event_sayisi = active olusum count");
  });
});
