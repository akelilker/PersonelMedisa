import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

/**
 * Serbest zaman bordro butunlugu — owner source + API contract locks (S87).
 */
const ENGINE = resolve(__dirname, "../../api/src/Services/Payroll/MaasHesaplamaEngine.php");
const GUARD = resolve(__dirname, "../../api/src/Services/Payroll/PayrollComplianceGuard.php");
const FCOT = resolve(
  __dirname,
  "../../api/src/Controllers/FazlaCalismaOdemeTercihiController.php"
);
const SZ = resolve(__dirname, "../../api/src/Controllers/SerbestZamanController.php");
const PANEL = resolve(
  __dirname,
  "../../src/features/raporlar/components/FazlaCalismaOdemeTercihiPanel.tsx"
);

describe("serbest zaman bordro butunlugu (S87)", () => {
  const engine = readFileSync(ENGINE, "utf8");
  const guard = readFileSync(GUARD, "utf8");
  const fcot = readFileSync(FCOT, "utf8");
  const sz = readFileSync(SZ, "utf8");
  const panel = readFileSync(PANEL, "utf8");

  it("UCRET path: FM ucret; SZ suppress yok", () => {
    expect(engine).toContain("FAZLA_MESAI_ODEMESI");
    expect(engine).toContain("SERBEST_ZAMAN_FM_UCRET_SUPPRESSED");
    expect(engine).toContain("'odeme_tipi' => 'SERBEST_ZAMAN'");
  });

  it("SERBEST_ZAMAN: 1.5x donusum + kanit zorunlu", () => {
    expect(guard).toContain("SERBEST_ZAMAN_DONUSUM_KATSAYISI = 1.5");
    expect(guard).toContain("BLOCKER_SERBEST_ZAMAN_KANIT_EKSIK");
    expect(fcot).toContain("validateSerbestZamanKanit");
    expect(fcot).toContain("sisteme_giren_kullanici_id");
    expect(fcot).toContain("sisteme_giris_zamani");
  });

  it("cift etki + KARAR_BEKLIYOR blockers", () => {
    expect(guard).toContain("SERBEST_ZAMAN_UCRET_CIFT_ETKI");
    expect(guard).toContain("FAZLA_CALISMA_ODEME_TERCIHI_KARAR_BEKLIYOR");
    expect(engine).toContain("force_ucret_with_sz");
  });

  it("olusum route revalidates kanit + age + 270", () => {
    expect(sz).toContain("validateSerbestZamanKanit");
    expect(sz).toContain("resolveUnder18");
    expect(sz).toContain("evaluateYillikLimit");
    expect(sz).toContain("ALREADY_EXISTS");
  });

  it("aktif SZ olusumu varken state transition korunur", () => {
    expect(fcot).toContain("hasActiveSerbestZamanOlusum");
    expect(fcot).toContain("STATE_CONFLICT");
  });

  it("UI: KARAR_BEKLIYOR / UCRET / SERBEST_ZAMAN + belge zorunlu", () => {
    expect(panel).toContain("KARAR_BEKLIYOR");
    expect(panel).toContain("UCRET");
    expect(panel).toContain("SERBEST_ZAMAN");
    expect(panel).toContain("talep_tarihi");
    expect(panel).toContain("fm-belge-id");
    expect(panel).toContain("required");
    expect(panel).toMatch(/odemeTipi === ["']SERBEST_ZAMAN["']/);
  });
});
