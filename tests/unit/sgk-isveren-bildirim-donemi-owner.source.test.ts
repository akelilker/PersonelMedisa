import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";

const root = process.cwd();
const read = (rel: string) => readFileSync(resolve(root, rel), "utf8");

const migration = read("api/migrations/090_sgk_isveren_bildirim_donemi_owner.sql");
const reconcile = read("api/migrations/091_sgk_isveren_bildirim_donemi_reconcile.sql");
const readService = read("api/src/Services/Payroll/SgkIsverenBildirimDonemiReadService.php");
const runtime = read("api/src/Services/SgkPrimGunuService.php");
const legacyReadService = read("api/src/Services/Payroll/SgkSirketPolitikaReadService.php");
const currentState = read("CURRENT_STATE.md");
const registry = read("docs/guncel/110-master-closure-gap-registry.md");

describe("SGK employer reporting-period canonical owner", () => {
  it("adds additive migration 090 without seeding or destroying legacy owners", () => {
    const migrations = readdirSync(resolve(root, "api/migrations"))
      .filter((name) => /^\d{3}_.+\.sql$/.test(name))
      .sort();
    expect(migrations.at(-1)).toBe("095_personel_cinsiyet.sql");
    expect(migrations.filter((name) => name.startsWith("090_"))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith("091_"))).toHaveLength(1);

    expect(migration).toContain("CREATE TABLE IF NOT EXISTS sgk_isveren_bildirim_donemi_surumleri");
    expect(migration).toContain("sgk_isveren_id INT UNSIGNED NOT NULL");
    expect(migration).toContain("REFERENCES sgk_isverenler (id)");
    expect(migration).toContain("'AY_1_SON_GUN'");
    expect(migration).toContain("'AY_15_SONRAKI_AY_14'");
    expect(migration).toContain("state ENUM('DOGRULANMADI', 'DOGRULANDI', 'IPTAL')");
    expect(migration).toContain("dogrulama_kaynagi VARCHAR(64) NOT NULL");
    expect(migration).toContain("dogrulama_kanit_hash CHAR(64) NOT NULL");
    expect(migration).toMatch(/NO DATA WRITES/i);

    // Fail-closed canonical shape guard: after CREATE TABLE IF NOT EXISTS the
    // canonical required columns, their shapes and the employer FK are re-verified,
    // so a previously half-created/drifted table aborts with PACK090_BLOCKER.
    expect(migration).toContain("SET @p090_required_column_count := 13;");
    expect(migration).toContain("@p090_present_column_count");
    expect(migration).toContain("@p090_bad_columns");
    expect(migration).toContain("@p090_employer_fk");
    expect(migration).toContain("information_schema.KEY_COLUMN_USAGE");
    expect(migration).toContain("REFERENCED_TABLE_NAME = 'sgk_isverenler'");
    expect(migration).toContain("REFERENCED_COLUMN_NAME = 'id'");
    expect(migration).toContain("COLUMN_TYPE LIKE '%AY_15_SONRAKI_AY_14%'");
    expect(migration).toContain("COLUMN_TYPE LIKE '%DOGRULANDI%'");
    expect(migration).toContain("'hazirlayan_id', 'onaylayan_id', 'onay_zamani'");
    expect(migration).toContain("@p090_approval_columns");
    expect(migration).toContain("PACK090_BLOCKER: sgk_isveren_bildirim_donemi_surumleri canonical factual shape/column/FK drift");
    // Factual owner: no approval workflow semantics anywhere in the canonical owner.
    const migrationSqlOnly = migration.replace(/^--.*$/gm, "");
    for (const forbidden of ["TASLAK", "ONAY_BEKLIYOR", "ONAYLANDI", "'hazirlayan_id'", "'onaylayan_id'", "'onay_zamani'"]) {
      expect(migrationSqlOnly.replace(/IN \('hazirlayan_id', 'onaylayan_id', 'onay_zamani'\)/, "")).not.toContain(forbidden);
    }
    const canonicalDdl = migration.slice(
      migration.indexOf("CREATE TABLE IF NOT EXISTS sgk_isveren_bildirim_donemi_surumleri"),
      migration.indexOf(") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"),
    );
    for (const column of ["hazirlayan_id", "onaylayan_id", "onay_zamani"]) {
      expect(canonicalDdl).not.toContain(column);
    }
    expect(canonicalDdl).toContain("dogrulayan_id INT UNSIGNED NULL");
    expect(canonicalDdl).toContain("dogrulama_zamani DATETIME NULL");

    // Additive only: no row writes, no destructive change to legacy owners/snapshots.
    const migrationSql = migration.replace(/^--.*$/gm, "");
    expect(migrationSql).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(migrationSql).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migrationSql).not.toMatch(/INSERT\s+INTO\s+sgk_isveren_bildirim_donemi_surumleri/i);
    expect(migrationSql).not.toMatch(/INSERT\s+INTO\s+sgk_sirket_politika_surumleri/i);
    expect(migrationSql).not.toMatch(/UPDATE\s+sgk_sirket_politika_surumleri/i);
    expect(migrationSql).not.toMatch(/maas_hesaplama_sgk_snapshotlari/i);
    // The drift guard never repairs/guesses the schema shape.
    expect(migrationSql).not.toMatch(/\bALTER\s+TABLE\b/i);
    expect(migrationSql).not.toMatch(/\bDROP\s+(COLUMN|INDEX|KEY|CONSTRAINT)\b/i);
    // No guessed Medisa/Karyapı employer period hardcode.
    expect(migrationSql).not.toMatch(/sgk_isveren_id\s*=\s*1\b/i);
    expect(migrationSql).not.toMatch(/VALUES\s*\(\s*1\s*,/i);
  });

  it("resolves the period from the SGK employer axis and fails closed", () => {
    expect(readService).toContain("final class SgkIsverenBildirimDonemiReadService");
    expect(readService).toContain("public static function resolveForPeriod(PDO $pdo, int $sgkIsverenId, string $from, string $to)");
    expect(readService).toContain("sgk_isveren_id = :sgk_isveren_id");
    expect(readService).toContain("state = 'DOGRULANDI'");
    expect(readService).toContain("public const STATE_DOGRULANDI = 'DOGRULANDI';");
    expect(readService).not.toContain("ONAYLANDI");
    expect(readService).not.toContain("TASLAK");
    expect(readService).not.toContain("ONAY_BEKLIYOR");
    expect(readService).not.toContain("sube");
    expect(readService).toContain("STATE_NO_PERIOD");
    expect(readService).toContain("STATE_CONFLICT");
    expect(readService).toContain("'AY_1_SON_GUN'");
    expect(readService).toContain("'AY_15_SONRAKI_AY_14'");
  });

  it("reconciles legacy approved branch policy into the factual employer owner (091)", () => {
    expect(reconcile).toContain("START TRANSACTION;");
    expect(reconcile).toContain("COMMIT;");
    expect(reconcile).toContain("SIGNAL SQLSTATE ''45000''");
    expect(reconcile).toContain("PACK091_BLOCKER");
    expect(reconcile).toContain("INSERT INTO sgk_isveren_bildirim_donemi_surumleri");

    // Legacy approved branch evidence -> subeler.sgk_isveren_id -> employer owner.
    expect(reconcile).toContain("FROM sgk_sirket_politika_surumleri p");
    expect(reconcile).toContain("LEFT JOIN subeler s ON s.id = p.sube_id");
    expect(reconcile).toContain("WHERE p.state = 'ONAYLANDI'");
    expect(reconcile).toContain("JOIN subeler s ON s.sgk_isveren_id = e.id");

    // Every guard A-I is proven before any insert.
    for (const guard of [
      "@p091_schema_bad",
      "@p091_missing_source",
      "@p091_tip_drift",
      "@p091_bad_tip",
      "@p091_interval_drift",
      "@p091_overlap_conflict",
      "@p091_mapping_bad",
      "@p091_branch_orphan",
      "@p091_existing_incompatible",
      "@p091_partial",
    ]) {
      expect(reconcile).toContain(guard);
    }

    // Factual insert contract: DOGRULANDI + consensus evidence hash, no fabricated actor.
    expect(reconcile).toContain("'DOGRULANDI',");
    expect(reconcile).toContain("'LEGACY_APPROVED_BRANCH_POLICY_CONSENSUS',");
    expect(reconcile).toContain("SHA2(CONCAT_WS('|'");
    expect(reconcile).toContain("UTC_TIMESTAMP()");

    // Nothing employer/branch/period/date specific is hardcoded in 091.
    const reconcileSqlOnly = reconcile.replace(/^--.*$/gm, "");
    expect(reconcileSqlOnly).not.toContain("AY_1_SON_GUN");
    expect(reconcileSqlOnly).not.toContain("AY_15_SONRAKI_AY_14");
    expect(reconcileSqlOnly).not.toMatch(/sgk_isveren_id\s*=\s*\d+/);
    expect(reconcileSqlOnly).not.toMatch(/sube_id\s*=\s*\d+/);
    expect(reconcileSqlOnly).not.toMatch(/\b(19|20)\d{2}-\d{2}-\d{2}\b/);
    expect(reconcileSqlOnly).not.toContain("TASLAK");
    expect(reconcileSqlOnly).not.toContain("ONAY_BEKLIYOR");
    expect(reconcileSqlOnly).toContain("COLUMN_NAME = 'bildirim_donem_tipi'");
  });

  it("resolves the period only from the SGK employer axis and fails closed", () => {
    expect(runtime).toContain("SgkIsverenBildirimDonemiReadService::resolveForPeriod");
    expect(runtime).toContain("BLOCKER_ISVEREN_BILDIRIM_DONEMI_YOK");
    expect(runtime).toContain("BLOCKER_ISVEREN_BILDIRIM_DONEMI_CAKISMA");
    expect(runtime).toContain("'bildirim_donem_tipi' => $periodChoice['bildirim_donem_tipi']");
    expect(runtime).toContain("'bildirim_donem_cozumlemesi' => $bildirimDonemCozumlemesi");
    expect(runtime).toContain("sgk_isveren_id");

    // Canonical only: personeller.sgk_isveren_id -> SgkIsverenBildirimDonemiReadService.
    // The legacy reporting-period fallbacks are fully removed.
    expect(runtime).toContain("SGK_ISVEREN_MISSING");
    expect(runtime).not.toContain("LEGACY_UNMAPPED");
    expect(runtime).not.toContain("'bildirim_donem_tipi' => $status !== null");
    expect(runtime).not.toContain("($companyPolicy['politika']['bildirim_donem_tipi'] ?? null),");
    expect(runtime).not.toContain("$companyPolicy['politika']['bildirim_donem_tipi']");
    expect(runtime).not.toMatch(/\$status\s*!==\s*null\s*\?\s*\$status\['bildirim_donem_tipi'\]/);
    expect(runtime).not.toMatch(/\?\s*\(string\)\s*\$companyPolicy/);

    // Only the employer axis resolves the reporting period inside the service.
    const periodResolutionCalls = runtime.match(/SgkIsverenBildirimDonemiReadService::resolveForPeriod/g) ?? [];
    expect(periodResolutionCalls.length).toBeGreaterThanOrEqual(1);
    expect(runtime).not.toMatch(/SgkSirketPolitikaReadService::.*bildirim_donem_tipi/);

    // Missing employer identity is fail-closed with no period, not a legacy guess.
    expect(runtime).toContain("'kaynak' => 'SGK_ISVEREN_MISSING'");
    expect(runtime).toContain("'bildirim_donem_tipi' => null");

    // Management policy owner is preserved, not replaced.
    expect(runtime).toContain("SgkSirketPolitikaReadService::resolveForPeriod");
    expect(runtime).toContain("SGK_ODENEK_MAHSUP_MODU");
    expect(legacyReadService).toContain("sgk_sirket_politika_surumleri");
  });

  it("documents A1 12/13 as superseded by the employer-period owner correction", () => {
    for (const doc of [currentState, registry]) {
      expect(doc).toContain("SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION");
      expect(doc).toContain("REPORTING_PERIOD_CANONICAL_AXIS: SGK_ISVEREN");
      expect(doc).toContain("BRANCH_SPECIFIC_PERIOD_REQUIRED: NO");
    }
    expect(currentState).toContain("12/13 branch-specific period rows are NOT the target fix.");
    expect(registry).toContain("12/13 branch-specific period rows are NOT the target fix.");
  });
});
