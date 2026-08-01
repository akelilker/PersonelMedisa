import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const guard = readFileSync(
  "api/src/Services/Payroll/PayrollComplianceGuard.php",
  "utf8"
);
const engine = readFileSync(
  "api/src/Services/Payroll/MaasHesaplamaEngine.php",
  "utf8"
);

describe("payroll-compliance-guard source contracts", () => {
  it("exposes blocker codes for odeme tercihi / SZ / yas / 270 saat", () => {
    expect(guard).toContain("BLOCKER_ODEME_TERCIHI_KARAR_BEKLIYOR");
    expect(guard).toContain("FAZLA_CALISMA_ODEME_TERCIHI_KARAR_BEKLIYOR");
    expect(guard).toContain("BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK");
    expect(guard).toContain("SERBEST_ZAMAN_IMZALI_TALEP_KANIT_EKSIK");
    expect(guard).toContain("BLOCKER_SERBEST_ZAMAN_CIFT_ETKI");
    expect(guard).toContain("BLOCKER_ONSEKIZ_YAS_FAZLA_CALISMA");
    expect(guard).toContain("BLOCKER_ONSEKIZ_YAS_GECE");
    expect(guard).toContain("BLOCKER_DOGUM_TARIHI_REQUIRED");
    expect(guard).toContain("BLOCKER_YILLIK_270_SAAT_ASIMI");
  });

  it("documents SIRKET_KARARI weekly 2700 and no FSC company constants", () => {
    expect(guard).toContain("HAFTALIK_NORMAL_CALISMA_DAKIKA = 2700");
    expect(guard).toContain("SIRKET_KARARI");
    expect(guard).toContain("hesaplaHaftalikBantlarSirketKarari");
    expect(guard).toMatch(/fs_dk['\"]?\s*=>\s*0/);
    expect(guard).toContain("%25 FSC");
    expect(engine).toContain("SIRKET_KARARI");
    expect(engine).toContain("PayrollComplianceGuard::hesaplaHaftalikBantlarSirketKarari");
    expect(engine).toContain("FAZLA_SURELERLE_CALISMA_ODEMESI uretilmez");
  });
});
