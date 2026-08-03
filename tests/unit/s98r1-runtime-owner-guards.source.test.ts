import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

describe("S98-R1 runtime owner guards", () => {
  it("migration 047 has seal column + tam_gun_mu + override idempotency", () => {
    const migration = read("api/migrations/047_sgk_real_decision_contract.sql");
    expect(migration).toContain("puantaj_aylik_muhur_satirlari");
    expect(migration).toContain("sgk_eksik_gun_neden_tipi");
    expect(migration).toContain("surecler");
    expect(migration).toContain("tam_gun_mu");
    expect(migration).toContain("idempotency_key");
    expect(migration).toContain("aktif_hedef_anahtari");
  });

  it("PuantajController wires sgk_eksik_gun_neden_tipi in seal hash path", () => {
    const puantaj = read("api/src/Controllers/PuantajController.php");
    expect(puantaj).toContain("insertSealRows");
    expect(puantaj).toContain("computeSealSourceHash");
    expect(puantaj).toContain("sgk_eksik_gun_neden_tipi");
  });

  it("SgkPrimGunuService does not use isFullDayProcess heuristic", () => {
    const service = read("api/src/Services/SgkPrimGunuService.php");
    expect(service).not.toContain("isFullDayProcess");
    expect(service).toContain("kismi_aylik_calisma_saati");
  });

  it("SgkEslemeKararContract rejects YETKILI_MANUEL for OLAY and no ?? 06 fallback", () => {
    const karar = read("api/src/Services/Payroll/SgkEslemeKararContract.php");
    expect(karar).not.toContain("?? '06'");
    expect(karar).toContain("OLAY_NEDENI_YETKILI_MANUEL_YASAK");
    expect(karar).toContain("MAZERET_TAM_GUN_KARARI_EKSIK");
    expect(karar).toContain("requiredCatalogCodes");
  });

  it("SgkManuelKodOverrideService exists with INSERT path", () => {
    const svc = read("api/src/Services/Payroll/SgkManuelKodOverrideService.php");
    expect(svc).toContain("INSERT INTO sgk_manuel_kod_override_auditleri");
    expect(svc).toContain("SUPERSEDED");
  });

  it("attendancePayload and leavePayload carry runtime decision fields", () => {
    const snap = read("api/src/Services/MaasHesaplamaSnapshotService.php");
    expect(snap).toMatch(/function attendancePayload[\s\S]*sgk_eksik_gun_neden_tipi/);
    expect(snap).toMatch(/function leavePayload[\s\S]*tam_gun_mu/);
    expect(snap).toContain("ucretli_mi, tam_gun_mu, ilk_iki_gun_firma_oder_mi");
  });

  it("PuantajController seal INSERT and source hash include neden tipi", () => {
    const puantaj = read("api/src/Controllers/PuantajController.php");
    expect(puantaj).toMatch(/insertSealRows[\s\S]*sgk_eksik_gun_neden_tipi/);
    expect(puantaj).toMatch(/computeSealSourceHash[\s\S]*sgk_eksik_gun_neden_tipi/);
  });

  it("SgkPrimGunuService synthesizes PUANTAJ_EKSIK_GUN and wires partial hours", () => {
    const service = read("api/src/Services/SgkPrimGunuService.php");
    expect(service).toContain("PUANTAJ_EKSIK_GUN");
    expect(service).toContain("kismi_aylik_calisma_saati");
    expect(service).toContain("UCRET_MODELINE_GORE");
    expect(service).toContain("SgkManuelKodOverrideService");
  });
});
