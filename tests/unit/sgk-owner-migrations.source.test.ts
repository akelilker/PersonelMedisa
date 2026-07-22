import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const ownerMigration = readFileSync("api/migrations/036_sgk_prim_gunu_owner.sql", "utf8");
const manifestMigration = readFileSync("api/migrations/037_sgk_resmi_kaynak_manifesti_v1.sql", "utf8");

describe("S85-B SGK owner migrations", () => {
  it("additive, idempotent ve immutable snapshot owner seması kurar", () => {
    expect(ownerMigration).toContain("CREATE TABLE IF NOT EXISTS sgk_kaynak_manifestleri");
    expect(ownerMigration).toContain("CREATE TABLE IF NOT EXISTS sgk_eksik_gun_kodlari");
    expect(ownerMigration).toContain("CREATE TABLE IF NOT EXISTS maas_hesaplama_sgk_snapshotlari");
    expect(ownerMigration).toContain("CREATE TABLE IF NOT EXISTS sgk_is_goremezlik_finans_kayitlari");
    expect(ownerMigration).toContain("sgk_fiili_odenen_tutar");
    expect(ownerMigration).toContain("isveren_tamamlayici_odeme_tutari");
    expect(ownerMigration).toContain("PAYROLL_SGK_SNAPSHOT_IMMUTABLE");
    expect(ownerMigration).not.toMatch(/\b(?:DROP TABLE|TRUNCATE|DELETE FROM|UPDATE personeller)\b/i);
  });

  it("tri-state politikayı tahmini default ile seed etmez", () => {
    expect(ownerMigration).not.toMatch(/INSERT\s+INTO\s+sgk_sirket_politika_/i);
    expect(manifestMigration).not.toMatch(/INSERT\s+INTO\s+sgk_(?:eksik_gun_kodlari|sirket_politika_)/i);
  });

  it("manifestte yalnız doğrulanmış resmî kaynak ve SHA-256 değerleri bulunur", () => {
    expect(manifestMigration).toContain("https://www.sgk.gov.tr/");
    expect(manifestMigration).toContain("https://www.csgb.gov.tr/");
    expect(manifestMigration).toContain("E-Bildirge V2 sayfasi");
    expect(manifestMigration).toContain("ebedi resmi belge kimligi degildir");
    expect(manifestMigration).toContain("observed_at");
    expect(manifestMigration).toContain("arsiv_kopyasi_repoda_mi");
    expect(manifestMigration).toContain("indirilen_dosya_byte");
    const hashes = manifestMigration.match(/'[0-9a-f]{64}'/g) ?? [];
    expect(hashes.length).toBeGreaterThanOrEqual(16);
    expect(manifestMigration).toContain("ON DUPLICATE KEY UPDATE kaynak_id = VALUES(kaynak_id)");
  });

  it("036 manifest kolonlari OBSERVED_AT ve byte boyutunu tasir", () => {
    expect(ownerMigration).toContain("indirilen_dosya_byte");
    expect(ownerMigration).toContain("observed_at");
    expect(ownerMigration).toContain("arsiv_kopyasi_repoda_mi");
    expect(ownerMigration).toContain("ADD COLUMN IF NOT EXISTS observed_at");
  });
});
