import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/FazlaCalismaOdemeTercihiMysqlTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/FazlaCalismaOdemeTercihiController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");
const permissionsSource = readFileSync(
  resolve(process.cwd(), "api/src/Auth/RolePermissions.php"),
  "utf8"
);
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/028_fazla_calisma_odeme_tercihleri.sql"),
  "utf8"
);

describe("FazlaCalismaOdemeTercihiController MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks router owners, permissions and additive migration", () => {
    expect(routerSource).toContain("FazlaCalismaOdemeTercihiController::get");
    expect(routerSource).toContain("FazlaCalismaOdemeTercihiController::put");

    expect(controllerSource).toContain("puantaj.view");
    expect(controllerSource).toContain("puantaj.muhurle");
    expect(controllerSource).toContain("PERIOD_LOCKED");
    expect(controllerSource).toContain("PERIOD_STATE_UNKNOWN");
    expect(controllerSource).toContain("STATE_CONFLICT");
    expect(permissionsSource).toContain("'puantaj.muhurle'");
    expect(permissionsSource).toContain("'puantaj.view'");

    expect(migrationSource).toMatch(/CREATE TABLE\s+fazla_calisma_odeme_tercihleri\s*\(/);
    expect(migrationSource).toMatch(/CREATE TABLE\s+fazla_calisma_odeme_tercihi_audit\s*\(/);
    expect(migrationSource).not.toContain("CREATE TABLE IF NOT EXISTS");
    expect(migrationSource).not.toMatch(/\bDROP\b/);
    expect(migrationSource).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migrationSource).not.toMatch(/(?:^|;)\s*UPDATE\b/im);
    expect(migrationSource).toContain("ON DELETE RESTRICT");
    expect(migrationSource).not.toContain("ON DELETE CASCADE");
    expect(migrationSource).toContain("uq_fcot_snapshot");

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => name.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("028_fazla_calisma_odeme_tercihleri.sql");
  });

  it("runs HTTP fazla calisma odeme tercihi acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-fazla-calisma-odeme-tercihi-mysql: OK");
    expect(result.stdout).toContain("[PASS] GET default no-write");
    expect(result.stdout).toContain("[PASS] GET synthetic id=null");
    expect(result.stdout).toContain("[PASS] GET persisted");
    expect(result.stdout).toContain("[PASS] snapshot 404");
    expect(result.stdout).toContain("[PASS] scope dışı 403");
    expect(result.stdout).toContain("[PASS] BA empty allowedSubeIds global GET → 403");
    expect(result.stdout).toContain("[PASS] PUT insert");
    expect(result.stdout).toContain("[PASS] PUT gerçek update");
    expect(result.stdout).toContain("[PASS] aynı payload idempotent");
    expect(result.stdout).toContain("[PASS] gerekce-only idempotent → 200");
    expect(result.stdout).toContain("[PASS] gerekce-only audit +0");
    expect(result.stdout).toContain("[PASS] audit append on insert");
    expect(result.stdout).toContain("[PASS] audit no-op üretmiyor");
    expect(result.stdout).toContain("[PASS] server-owned override 422");
    expect(result.stdout).toContain("[PASS] period locked 409");
    expect(result.stdout).toContain("[PASS] period unknown 409");
    expect(result.stdout).toContain("[PASS] period before SZ → PERIOD_LOCKED");
    expect(result.stdout).toContain(
      "[PASS] cross-month first open second locked → PERIOD_LOCKED"
    );
    expect(result.stdout).toContain(
      "[PASS] cross-month first locked second open → PERIOD_LOCKED"
    );
    expect(result.stdout).toContain("[PASS] cross-month both open → PUT 200");
    expect(result.stdout).toContain("[PASS] aktif SZ oluşum guard 409");
    expect(result.stdout).toContain("[PASS] UCRET → SERBEST_ZAMAN allowed");
    expect(result.stdout).toContain("[PASS] transaction rollback");
    expect(result.stdout).toContain("[PASS] transaction rollback audit-fail main");
    expect(result.stdout).toContain("[PASS] parallel PUT tek ana kayıt");
    expect(result.stdout).toContain("[PASS] parallel same payload both 200");
    expect(result.stdout).toContain("[PASS] parallel same payload audit=1");
    expect(result.stdout).toContain("[PASS] tek ana kayıt");
    expect(result.stdout).toContain("[PASS] audit zinciri tutarlı (ilk onceki=KARAR_BEKLIYOR)");
    expect(result.stdout).toContain("[PASS] SHOW CREATE TABLE fazla_calisma_odeme_tercihleri");
    expect(result.stdout).toContain("[PASS] unique(snapshot_id) present");
  });
});
