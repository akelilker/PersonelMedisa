import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const migration = readFileSync("api/migrations/039_ubgt_gun_kapsami_tatil_takvimi.sql", "utf8");
const service = readFileSync("api/src/Services/ResmiTatilTakvimiService.php", "utf8");
const projection = readFileSync("api/src/Services/ResmiTatilTakvimProjectionService.php", "utf8");
const router = readFileSync("api/src/Router.php", "utf8");
const engine = readFileSync("api/src/Services/Payroll/MaasHesaplamaEngine.php", "utf8");
const aday = readFileSync("api/src/Services/MaasHesaplamaAdayService.php", "utf8");

describe("S88 resmi tatil takvimi owner source", () => {
  it("039 additive, seedless, idempotent calendar + snapshot columns", () => {
    expect(migration).toContain("CREATE TABLE IF NOT EXISTS resmi_tatil_takvimi");
    expect(migration).toContain("CREATE TABLE IF NOT EXISTS resmi_tatil_takvim_auditleri");
    expect(migration).toContain("uq_rtt_aktif_ubgt_tarih");
    expect(migration).toContain("chk_rtt_interval_kapsam");
    expect(migration).toContain("ADD COLUMN IF NOT EXISTS tatil_gun_kapsami");
    expect(migration).toContain("puantaj_aylik_muhur_satirlari");
    expect(migration).not.toMatch(/\b(?:DROP TABLE|TRUNCATE|DELETE FROM)\b/i);
    expect(migration).not.toMatch(/INSERT\s+INTO\s+resmi_tatil_takvimi/i);
    expect(migration).not.toMatch(/2026-\d{2}-\d{2}/);
  });

  it("projection and readiness fail-closed codes exist", () => {
    expect(projection).toContain("TATIL_DONEMI_CALISMA_INTERVALI_EKSIK");
    expect(projection).toContain("KAYNAK_EKSIK");
    expect(projection).toContain("CAKISMA");
    expect(projection).toContain("DOGRULANDI");
    expect(aday).toContain("TATIL_TAKVIM_CAKISMASI");
    expect(aday).toContain("TATIL_DONEMI_CALISMA_INTERVALI_EKSIK");
    expect(aday).toMatch(/'BLOCKER',\s*\n\s*ResmiTatilTakvimProjectionService::TATIL_DONEMI_CALISMA_INTERVALI_EKSIK/);
    expect(engine).toContain("resolveUbgtGunKapsami");
    expect(engine).toContain("HALF_DAY_UBGT_POLICY");
    expect(service).toContain("siniflandirmaRaporu");
    expect(service).toContain("policy_activation_blocker");
  });

  it("API routes and service invariants wired", () => {
    expect(router).toContain("/resmi-tatil-takvimi");
    expect(router).toContain("aktiflestir");
    expect(service).toContain("gun_kapsami");
    expect(service).toContain("IPTAL");
    expect(service).toContain("onceki_kayit_id");
    expect(service).not.toMatch(/\bDELETE FROM\s+resmi_tatil_takvimi\b/i);
  });
});
