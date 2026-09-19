import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";

const root = process.cwd();
const read = (rel: string) => readFileSync(resolve(root, rel), "utf8");

const migration = read("api/migrations/090_sgk_isveren_bildirim_donemi_owner.sql");
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
    expect(migrations.at(-1)).toBe("090_sgk_isveren_bildirim_donemi_owner.sql");
    expect(migrations.filter((name) => name.startsWith("090_"))).toHaveLength(1);

    expect(migration).toContain("CREATE TABLE IF NOT EXISTS sgk_isveren_bildirim_donemi_surumleri");
    expect(migration).toContain("sgk_isveren_id INT UNSIGNED NOT NULL");
    expect(migration).toContain("REFERENCES sgk_isverenler (id)");
    expect(migration).toContain("'AY_1_SON_GUN'");
    expect(migration).toContain("'AY_15_SONRAKI_AY_14'");
    expect(migration).toContain("state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL')");
    expect(migration).toMatch(/NO DATA WRITES/i);

    // Additive only: no row writes, no destructive change to legacy owners/snapshots.
    const migrationSql = migration.replace(/^--.*$/gm, "");
    expect(migrationSql).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(migrationSql).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migrationSql).not.toMatch(/INSERT\s+INTO\s+sgk_isveren_bildirim_donemi_surumleri/i);
    expect(migrationSql).not.toMatch(/INSERT\s+INTO\s+sgk_sirket_politika_surumleri/i);
    expect(migrationSql).not.toMatch(/UPDATE\s+sgk_sirket_politika_surumleri/i);
    expect(migrationSql).not.toMatch(/maas_hesaplama_sgk_snapshotlari/i);
    // No guessed Medisa/Karyapı employer period hardcode.
    expect(migrationSql).not.toMatch(/sgk_isveren_id\s*=\s*1\b/i);
    expect(migrationSql).not.toMatch(/VALUES\s*\(\s*1\s*,/i);
  });

  it("resolves the period from the SGK employer axis and fails closed", () => {
    expect(readService).toContain("final class SgkIsverenBildirimDonemiReadService");
    expect(readService).toContain("public static function resolveForPeriod(PDO $pdo, int $sgkIsverenId, string $from, string $to)");
    expect(readService).toContain("sgk_isveren_id = :sgk_isveren_id");
    expect(readService).toContain("state = 'ONAYLANDI'");
    expect(readService).not.toContain("state = 'TASLAK'");
    expect(readService).not.toContain("sube");
    expect(readService).toContain("STATE_NO_PERIOD");
    expect(readService).toContain("STATE_CONFLICT");
    expect(readService).toContain("'AY_1_SON_GUN'");
    expect(readService).toContain("'AY_15_SONRAKI_AY_14'");
  });

  it("makes payroll runtime use personeller.sgk_isveren_id instead of the branch", () => {
    expect(runtime).toContain("SgkIsverenBildirimDonemiReadService::resolveForPeriod");
    expect(runtime).toContain("BLOCKER_ISVEREN_BILDIRIM_DONEMI_YOK");
    expect(runtime).toContain("BLOCKER_ISVEREN_BILDIRIM_DONEMI_CAKISMA");
    expect(runtime).toContain("'bildirim_donem_tipi' => $periodChoice['bildirim_donem_tipi']");
    expect(runtime).toContain("'bildirim_donem_cozumlemesi' => $bildirimDonemCozumlemesi");
    expect(runtime).toContain("sgk_isveren_id");
    // The reporting period no longer comes from the branch-scoped personnel status
    // or branch company policy; those remain the legacy unmapped fallback only.
    expect(runtime).not.toContain("'bildirim_donem_tipi' => $status !== null");
    expect(runtime).not.toContain("($companyPolicy['politika']['bildirim_donem_tipi'] ?? null),");
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
