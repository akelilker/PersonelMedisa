import { existsSync, readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const MIGRATION = "api/migrations/043_payroll_compliance_critical_gaps.sql";

describe("S87 migration 043 source contracts", () => {
  it("exists and remains in chain before tip", () => {
    expect(existsSync(resolve(MIGRATION))).toBe(true);
    const files = readdirSync(resolve("api/migrations"))
      .filter((n) => /^\d{3}_.+\.sql$/.test(n))
      .sort();
    expect(files).toContain("043_payroll_compliance_critical_gaps.sql");
    expect(files.at(-1)).toBe("048_sgk_dual_control_actor_roles.sql");
  });

  it("is additive: alters tercih + audit, creates yillik kilit, references surecler belge id", () => {
    const sql = readFileSync(resolve(MIGRATION), "utf8");
    expect(sql).toMatch(/fazla_calisma_odeme_tercihleri/);
    expect(sql).toMatch(/imzali_talep_belge_id/);
    expect(sql).toMatch(/talep_tarihi/);
    expect(sql).toMatch(/sisteme_giren_kullanici_id/);
    expect(sql).toMatch(/sisteme_giris_zamani/);
    expect(sql).toMatch(/fazla_calisma_odeme_tercihi_audit/);
    expect(sql).toMatch(/yillik_fazla_calisma_kilitleri/);
    expect(sql).toMatch(/REFERENCES surecler/);
    expect(sql).not.toMatch(/DROP TABLE/i);
    expect(sql).not.toMatch(/\bDELETE FROM\b/i);
  });

  it("PayrollComplianceGuard + engine owners contain required blocker codes", () => {
    const guard = readFileSync(
      resolve("api/src/Services/Payroll/PayrollComplianceGuard.php"),
      "utf8"
    );
    const engine = readFileSync(
      resolve("api/src/Services/Payroll/MaasHesaplamaEngine.php"),
      "utf8"
    );
    for (const code of [
      "FAZLA_CALISMA_ODEME_TERCIHI_KARAR_BEKLIYOR",
      "SERBEST_ZAMAN_IMZALI_TALEP_KANIT_EKSIK",
      "SERBEST_ZAMAN_UCRET_CIFT_ETKI",
      "ONSEKIZ_YAS_ALTI_FAZLA_CALISMA",
      "DOGUM_TARIHI_REQUIRED",
      "YILLIK_FAZLA_CALISMA_270_SAAT_ASIMI",
      "NORMAL_HASTALIK_POLITIKASI_COZULEMEDI",
      "NORMAL_HASTALIK_ILK_2_GUN_ODENMEDI",
      "HAFTA_TATILI_HAK_KAYBI_KESINTISI",
      "DEVAMSIZLIK_FIILI_GUN_KESINTISI"
    ]) {
      expect(guard.includes(code) || engine.includes(code)).toBe(true);
    }
    expect(guard).toContain("HAFTALIK_NORMAL_CALISMA_DAKIKA = 2700");
    expect(guard).toContain("YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA = 16200");
    expect(guard).toContain("SERBEST_ZAMAN_DONUSUM_KATSAYISI = 1.5");
  });
});
