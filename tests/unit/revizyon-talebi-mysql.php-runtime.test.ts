import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/RevizyonTalebiMysqlTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/RevizyonController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/030_haftalik_kapanis_revizyon_talepleri.sql"),
  "utf8"
);

describe("RevizyonController MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks router owners, permissions and additive migration", () => {
    expect(routerSource).toContain("RevizyonController::talepleri");
    expect(routerSource).toContain("RevizyonController::createTalep");
    expect(routerSource).toContain("RevizyonController::talepDetail");
    expect(routerSource).toContain("RevizyonController::gonder");
    expect(routerSource).toContain("RevizyonController::onay");
    expect(routerSource).toContain("RevizyonController::red");
    expect(routerSource).toContain("RevizyonController::iptal");

    expect(controllerSource).toContain("revizyon.view");
    expect(controllerSource).toContain("revizyon.create");
    expect(controllerSource).toContain("revizyon.submit");
    expect(controllerSource).toContain("revizyon.approve");
    expect(controllerSource).toContain("revizyon.reject");
    expect(controllerSource).toContain("revizyon.cancel");

    expect(migrationSource).toMatch(/CREATE TABLE\s+haftalik_kapanis_revizyon_talepleri\s*\(/);
    expect(migrationSource).toMatch(/CREATE TABLE\s+haftalik_kapanis_revizyon_talebi_gecmisi\s*\(/);
    expect(migrationSource).not.toContain("CREATE TABLE IF NOT EXISTS");
    expect(migrationSource).not.toMatch(/\bDROP\s+(TABLE|DATABASE|INDEX)\b/i);
    expect(migrationSource).not.toMatch(/(?:^|;)\s*TRUNCATE\b/im);
    expect(migrationSource).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migrationSource).not.toMatch(/(?:^|;)\s*UPDATE\b/im);
    expect(migrationSource).toContain("ON DELETE RESTRICT");
    expect(migrationSource).not.toContain("ON DELETE CASCADE");
    expect(migrationSource).toContain("uq_hkrt_acik_kaynak");
    expect(migrationSource).toContain("acik_talep_slot");

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => name.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("038_personel_belge_yonetimi.sql");
  });

  it("runs HTTP revizyon talebi acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-revizyon-talebi-mysql: OK");
    expect(result.stdout).toContain("[PASS] migration CREATE haftalik_kapanis_revizyon_talepleri");
    expect(result.stdout).toContain("[PASS] migration CREATE haftalik_kapanis_revizyon_talebi_gecmisi");
    expect(result.stdout).toContain("[PASS] migration no IF NOT EXISTS");
    expect(result.stdout).toContain("[PASS] migration uq_hkrt_acik_kaynak");
    expect(result.stdout).toContain("[PASS] acik_talep_slot generated column present");
    expect(result.stdout).toContain("[PASS] uq_hkrt_acik_kaynak present");
    expect(result.stdout).toContain("[PASS] unauthenticated GET → 401");
    expect(result.stdout).toContain("[PASS] PATRON GET talepleri → 403");
    expect(result.stdout).toContain("[PASS] GY GET list → 200 empty");
    expect(result.stdout).toContain("[PASS] GY GET list has items");
    expect(result.stdout).toContain("[PASS] GET detail missing → 404 NOT_FOUND");
    expect(result.stdout).toContain("[PASS] BA POST create → 201");
    expect(result.stdout).toContain("[PASS] create durum TASLAK");
    expect(result.stdout).toContain("[PASS] create correction_event_id null");
    expect(result.stdout).toContain("[PASS] PERIOD_NOT_CLOSED");
    expect(result.stdout).toContain("[PASS] TARGET_NOT_FOUND");
    expect(result.stdout).toContain("[PASS] duplicate TASLAK ALREADY_EXISTS");
    expect(result.stdout).toContain("[PASS] duplicate ONAY_BEKLIYOR ALREADY_EXISTS");
    expect(result.stdout).toContain("[PASS] REVISION_OWNER_DENIED");
    expect(result.stdout).toContain("[PASS] MUHASEBE onay → 403");
    expect(result.stdout).toContain("[PASS] gonder → ONAY_BEKLIYOR");
    expect(result.stdout).toContain("[PASS] red empty VALIDATION_ERROR");
    expect(result.stdout).toContain("[PASS] onay → ONAYLANDI");
    expect(result.stdout).toContain("[PASS] onay correction_event_id still null");
    expect(result.stdout).toContain("[PASS] iptal from TASLAK → 200");
    expect(result.stdout).toContain("[PASS] iptal from ONAY_BEKLIYOR → 200");
    expect(result.stdout).toContain("[PASS] iptal ONAYLANDI STATE_CONFLICT");
    expect(result.stdout).toContain("[PASS] gecmis OLUSTUR on create");
    expect(result.stdout).toContain("[PASS] gecmis GONDER on gonder");
    expect(result.stdout).toContain("[PASS] gecmis ONAY on onay");
    expect(result.stdout).toContain("[PASS] gecmis RED on red");
    expect(result.stdout).toContain("[PASS] server-owned durum in body → 422");
    expect(result.stdout).toContain("[PASS] sube_id in body → 422");
    expect(result.stdout).toContain("[PASS] parallel create → one 201 one 409");
    expect(result.stdout).toContain("[PASS] parallel gonder → one 200 one 409");
    expect(result.stdout).toContain("[PASS] parallel onay/red → one success");
    expect(result.stdout).toContain("[PASS] parallel iptal/gonder → serialized outcomes");
    expect(result.stdout).toMatch(
      /\[PASS\] parallel iptal\/gonder (loser STATE_CONFLICT|both-200 ends IPTAL)/
    );    expect(result.stdout).toContain("[PASS] terminal ONAYLANDI sonrası recreate → 201");
    expect(result.stdout).toContain("[PASS] terminal REDDEDILDI sonrası recreate → 201");
    expect(result.stdout).toContain("[PASS] terminal IPTAL sonrası recreate → 201");
    expect(result.stdout).toContain("[PASS] GY başkasının gonder → 200");
    expect(result.stdout).toContain("[PASS] GY başkasının iptal → 200");
    expect(result.stdout).toContain("[PASS] allowedSubeIds=[] global erişim yok");
    expect(result.stdout).toContain("[PASS] scope dışı create → 403");
    expect(result.stdout).toContain("[PASS] scope dışı detail → 403");
    expect(result.stdout).toContain("[PASS] query sube_id → 422");
    expect(result.stdout).toContain("[PASS] GET list no-write counts");
    expect(result.stdout).toContain("[PASS] detail no acik_talep_slot leak");
    expect(result.stdout).toContain("[PASS] OLUSTUR onceki_durum null");
    expect(result.stdout).toContain("[PASS] JSON object/array create → 201");
    expect(result.stdout).toContain("[PASS] invalid kaynak_tipi → 422");
    expect(result.stdout).toContain("[PASS] HAFTALIK_KAPANIS_SATIR create → 201");
    expect(result.stdout).toContain("[PASS] SUREC create → 201");
    expect(result.stdout).toContain("[PASS] SERBEST_ZAMAN create → 201");
    expect(result.stdout).toContain("[PASS] aylik muhur varken create → 201");
    expect(result.stdout).toContain("[PASS] onayda correction tablo write yok");
    expect(result.stdout).toContain("[PASS] parallel iptal/onay → one success");
    expect(result.stdout).toContain("[PASS] rollback gonder ana state TASLAK");
    expect(result.stdout).toContain("[PASS] rollback create ana kayıt yok");
    expect(result.stdout).toContain("[PASS] driftli tablo varken apply fail-loud");
    expect(result.stdout).toContain("[PASS] acik_talep_slot exact CASE WHEN expression");
    expect(result.stdout).toContain("[PASS] correction_event_id has no FK");
    expect(result.stdout).toContain("[PASS] partial existing haftalik_kapanis_revizyon_talepleri → migration fails");
    expect(result.stdout).toContain("[PASS] partial existing haftalik_kapanis_revizyon_talebi_gecmisi → migration fails");
  });
});
