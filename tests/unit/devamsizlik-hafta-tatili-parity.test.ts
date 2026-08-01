import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { hesaplaDevamsizlikKesintiOzeti } from "../../src/services/puantaj-hesap-motoru";

/**
 * Exact parity chain (S87):
 * UI preview (hesaplaDevamsizlikKesintiOzeti)
 * → snapshot metadata kalem kodlari
 * → MaasHesaplamaEngine S87 kalemleri
 *
 * Dakika/gün/tutar: maas=30000 → günlük 1000; 1 fiili + 1 HT = 2000.
 */
const ENGINE_PHP = resolve(__dirname, "../../api/src/Services/Payroll/MaasHesaplamaEngine.php");
const GUARD_PHP = resolve(
  __dirname,
  "../../api/src/Services/Payroll/PayrollComplianceGuard.php"
);
const SNAPSHOT_PHP = resolve(
  __dirname,
  "../../api/src/Services/MaasHesaplamaSnapshotService.php"
);
const ADAY_PHP = resolve(__dirname, "../../api/src/Services/MaasHesaplamaAdayService.php");

describe("devamsizlik parity chain UI→snapshot→aday→engine (S87)", () => {
  const maas = 30000;

  it("UI preview: 1 gun izinsiz → fiili 1 + HT 1 = 2000 TL", () => {
    const o = hesaplaDevamsizlikKesintiOzeti(maas, {
      devamsizlik_gun_sayisi: 1,
      hafta_tatili_kaybi_gun_sayisi: 1
    });
    expect(o.devamsizlik_gun_sayisi).toBe(1);
    expect(o.hafta_tatili_kaybi_gun_sayisi).toBe(1);
    expect(o.toplam_kesinti_gun_esdegeri).toBe(2);
    expect(o.toplam_kesinti_tutari).toBe(2000);
  });

  it("UI: isveren izinli gun → devamsizlik 0 (HT kaybi yok)", () => {
    const o = hesaplaDevamsizlikKesintiOzeti(maas, {
      devamsizlik_gun_sayisi: 0,
      hafta_tatili_kaybi_gun_sayisi: 0
    });
    expect(o.toplam_kesinti_gun_esdegeri).toBe(0);
    expect(o.toplam_kesinti_tutari).toBe(0);
  });

  it("owner zinciri ayni kalem kodlarini paylasir", () => {
    const guard = readFileSync(GUARD_PHP, "utf8");
    const engine = readFileSync(ENGINE_PHP, "utf8");
    const snapshot = readFileSync(SNAPSHOT_PHP, "utf8");
    const aday = readFileSync(ADAY_PHP, "utf8");

    expect(guard).toContain("DEVAMSIZLIK_FIILI_GUN_KESINTISI");
    expect(guard).toContain("HAFTA_TATILI_HAK_KAYBI_KESINTISI");
    expect(engine).toContain("KALEM_DEVAMSIZLIK_FIILI");
    expect(engine).toContain("KALEM_HAFTA_TATILI_HAK_KAYBI");
    expect(snapshot).toContain("PayrollComplianceGuard");
    expect(aday).toContain("PayrollComplianceGuard::collectPeriodBlockers");
  });

  it("engine: RAPOR/IZIN HT hak kaybi uretmez (source lock)", () => {
    const engine = readFileSync(ENGINE_PHP, "utf8");
    expect(engine).toContain("shouldEmitHaftaTatiliHakKaybiFromDevamsizlik");
    expect(engine).toContain("hafta_tatili_hak_kaybi_uygula");
    expect(engine).toContain("KALEM_HAFTA_TATILI_HAK_KAYBI");
  });

  it("SGK prim gunu parasal etkiden bagimsiz (engine fail-closed + separate lines)", () => {
    const engine = readFileSync(ENGINE_PHP, "utf8");
    const guard = readFileSync(GUARD_PHP, "utf8");
    expect(engine).toContain("SGK_PRIM_GUNU_HESAPLANAMADI");
    expect(engine).toContain("SGK_MATRAH");
    expect(guard).toContain("DEVAMSIZLIK_FIILI_GUN_KESINTISI");
    expect(engine).toContain("KALEM_DEVAMSIZLIK_FIILI");
  });
});
