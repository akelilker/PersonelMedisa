import { beforeAll, describe, expect, it } from "vitest";
import { resolve } from "node:path";
import { readFileSync, readdirSync } from "node:fs";
import {
  ensureDisposableMariaDbEnv,
  runPhpMysqlRunner
} from "../scripts/disposable-mariadb.mjs";

const runnerPath = resolve(process.cwd(), "tests/php/RevizyonCorrectionMysqlTestRunner.php");
const controllerSource = readFileSync(
  resolve(process.cwd(), "api/src/Controllers/RevizyonController.php"),
  "utf8"
);
const routerSource = readFileSync(resolve(process.cwd(), "api/src/Router.php"), "utf8");
const migrationSource = readFileSync(
  resolve(process.cwd(), "api/migrations/031_haftalik_kapanis_revizyon_corrections.sql"),
  "utf8"
);

describe("RevizyonController correction MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("locks router owners, permissions and additive migration 031", () => {
    expect(routerSource).toContain("RevizyonController::corrections");
    expect(routerSource).toContain("RevizyonController::correctionDetail");
    expect(routerSource).toContain("RevizyonController::correctionUret");
    expect(routerSource).toContain("RevizyonController::correctionIptal");

    expect(controllerSource).toContain("revizyon.view");
    expect(controllerSource).toContain("revizyon.approve");
    expect(controllerSource).toContain("revizyon.view_finance_effect");
    expect(controllerSource).toContain("CORRECTION_ALREADY_EXISTS");
    expect(controllerSource).toContain("CORRECTION_NOT_ALLOWED_FOR_STATE");

    expect(migrationSource).toMatch(/CREATE TABLE\s+haftalik_kapanis_revizyon_corrections\s*\(/);
    expect(migrationSource).toContain("uq_hkrc_revizyon_talebi");
    expect(migrationSource).toContain("uq_hkrc_audit_ref");
    expect(migrationSource).toContain("uq_hkrt_correction_event");
    expect(migrationSource).toContain("fk_hkrt_correction_event");
    expect(migrationSource).not.toContain("CREATE TABLE IF NOT EXISTS");
    expect(migrationSource).not.toMatch(/^\s*[^-\s].*\bIF NOT EXISTS\b/im);
    expect(migrationSource).not.toMatch(/\bDROP\s+(TABLE|DATABASE|INDEX)\b/i);
    expect(migrationSource).not.toMatch(/(?:^|;)\s*TRUNCATE\b/im);
    expect(migrationSource).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migrationSource).not.toMatch(/(?:^|;)\s*UPDATE\b/im);
    expect(migrationSource).toContain("ON DELETE RESTRICT");
    expect(migrationSource).not.toContain("ON DELETE CASCADE");

    const migrations = readdirSync(resolve(process.cwd(), "api/migrations"))
      .filter((name) => name.endsWith(".sql"))
      .sort();
    expect(migrations.at(-1)).toBe("031_haftalik_kapanis_revizyon_corrections.sql");
  });

  it("runs HTTP revizyon correction acceptance on MariaDB", () => {
    const result = runPhpMysqlRunner(runnerPath);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-revizyon-correction-mysql: OK");
    expect(result.stdout).toContain("[PASS] migration CREATE haftalik_kapanis_revizyon_corrections");
    expect(result.stdout).toContain("[PASS] migration no IF NOT EXISTS");
    expect(result.stdout).toContain("[PASS] migration FK RESTRICT");
    expect(result.stdout).toContain("[PASS] migration uq_hkrc_revizyon_talebi");
    expect(result.stdout).toContain("[PASS] migration uq_hkrc_audit_ref");
    expect(result.stdout).toContain("[PASS] migration fk_hkrt_correction_event present");
    expect(result.stdout).toContain("[PASS] schema postcondition corrections table");
    expect(result.stdout).toContain("[PASS] UNIQUE(revizyon_talebi_id)");
    expect(result.stdout).toContain("[PASS] UNIQUE(audit_ref)");
    expect(result.stdout).toContain("[PASS] UNIQUE(correction_event_id) postcondition");
    expect(result.stdout).toContain("[PASS] UNIQUE(correction_event_id)");
    expect(result.stdout).toContain("[PASS] audit_ref NOT NULL");
    expect(result.stdout).toContain("[PASS] unauthenticated GET corrections → 401");
    expect(result.stdout).toContain("[PASS] GY GET list → 200");
    expect(result.stdout).toContain("[PASS] BA GET list scope OK");
    expect(result.stdout).toContain("[PASS] BA produce → 403");
    expect(result.stdout).toContain("[PASS] MUHASEBE produce → 403");
    expect(result.stdout).toContain("[PASS] BOLUM produce → 403");
    expect(result.stdout).toContain("[PASS] GY produce non-ONAYLANDI → 409 CORRECTION_NOT_ALLOWED_FOR_STATE");
    expect(result.stdout).toContain("[PASS] GY produce ONAYLANDI → 200");
    expect(result.stdout).toContain("[PASS] produce correction_event_id linked");
    expect(result.stdout).toContain("[PASS] produce audit_ref REV-CORR-");
    expect(result.stdout).toContain("[PASS] produce snapshot_ref snapshot:");
    expect(result.stdout).toContain("[PASS] duplicate produce → 409 CORRECTION_ALREADY_EXISTS");
    expect(result.stdout).toContain("[PASS] produce after cancel → 409 CORRECTION_ALREADY_EXISTS");
    expect(result.stdout).toContain("[PASS] SUREC_GEC_GIRIS → 404 CORRECTION_TARGET_NOT_FOUND");
    expect(result.stdout).toContain("[PASS] server-owned onceki → non-numeric delta 0");
    expect(result.stdout).toContain("[PASS] server-owned onceki → delta 0");
    expect(result.stdout).toContain("[PASS] equal delta 0");
    expect(result.stdout).toContain("[PASS] non-numeric delta 0");
    expect(result.stdout).toContain("[PASS] object onceki → string");
    expect(result.stdout).toContain("[PASS] server-owned onceki on sz");
    expect(result.stdout).toContain("[PASS] map PUANTAJ→GIRIS_CIKIS");
    expect(result.stdout).toContain("[PASS] map MOLA_DUZELTME");
    expect(result.stdout).toContain("[PASS] map DEVAMSIZLIK");
    expect(result.stdout).toContain("[PASS] map SERBEST_ZAMAN");
    expect(result.stdout).toContain("[PASS] map KAPANIS_HESAP");
    expect(result.stdout).toContain("[PASS] map BORDRO_ETKI_NOTU");
    expect(result.stdout).toContain("[PASS] finance mask BA: bordro_etki_tipi null");
    expect(result.stdout).toContain("[PASS] finance mask on cancelled correction");
    expect(result.stdout).toContain("[PASS] GET detail → 200");
    expect(result.stdout).toContain("[PASS] S80 enrichment sube fields");
    expect(result.stdout).toContain("[PASS] GET detail missing → 404 CORRECTION_NOT_FOUND");
    expect(result.stdout).toContain("[PASS] scope dışı detail → 403 CORRECTION_SCOPE_DENIED");
    expect(result.stdout).toContain("[PASS] allowedSubeIds=[] list empty");
    expect(result.stdout).toContain("[PASS] query sube_id → 400 INVALID_CORRECTION_PAYLOAD");
    expect(result.stdout).toContain("[PASS] list filters");
    expect(result.stdout).toContain("[PASS] list ordering olusturma_zamani DESC");
    expect(result.stdout).toContain("[PASS] cancel aciklama number → 400");
    expect(result.stdout).toContain("[PASS] cancel → 200 iptal_edildi_mi true");
    expect(result.stdout).toContain("[PASS] second cancel → 404 CORRECTION_NOT_FOUND");
    expect(result.stdout).toContain("[PASS] second cancel iptal_zamani immutable");
    expect(result.stdout).toContain("[PASS] cancel keeps talep.correction_event_id");
    expect(result.stdout).toContain("[PASS] cancel does not overwrite aciklama");
    expect(result.stdout).toContain("[PASS] snapshot unchanged after produce/cancel");
    expect(result.stdout).toContain("[PASS] period seal does not block produce");
    expect(result.stdout).toContain("[PASS] unknown query → 400 INVALID_CORRECTION_PAYLOAD");
    expect(result.stdout).toContain("[PASS] produce body with field → 400 INVALID_CORRECTION_PAYLOAD");
    expect(result.stdout).toContain("[PASS] GET no-write");
    expect(result.stdout).toContain("[PASS] parallel produce → one 200 one 409 CORRECTION_ALREADY_EXISTS");
    expect(result.stdout).toContain("[PASS] parallel cancel → one 200 one 404");
    expect(result.stdout).toContain("[PASS] UNIQUE(correction_event_id) blocks dual talep link");
    expect(result.stdout).toContain("[PASS] orphan correction check");
    expect(result.stdout).toContain("[PASS] produce rollback orphan correction yok");
    expect(result.stdout).toContain("[PASS] cancel rollback stays active");
  }, 180_000);
});
