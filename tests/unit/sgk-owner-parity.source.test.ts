import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

function source(path: string): string {
  return readFileSync(path, "utf8");
}

describe("S85-B single authoritative SGK owner parity", () => {
  it("rapor ve personel kartı bağımsız SGK hesabı üretmez", () => {
    const report = source("api/src/Controllers/RaporlarController.php");
    const hook = source("src/hooks/usePuantajEksikGunOzeti.ts");
    expect(report).toContain("SgkPrimGunuService::listCanonicalResults");
    expect(report).not.toContain("LEAST(30, COUNT(DISTINCT");
    expect(hook).toContain("fetchSgkPrimGunuSonuclari");
    expect(hook).not.toContain("hesaplaAylikPuantajEksikGunOzeti");
    expect(hook).not.toContain("fetchGunlukPuantaj");
  });

  it("Engine V2 PEK sınırını immutable SGK prim gününden alır", () => {
    const engine = source("api/src/Services/Payroll/MaasHesaplamaEngine.php");
    expect(engine).toContain("$primDay = $sgkHesabi['hesaplanan_prim_gunu']");
    expect(engine).toContain("$snapshotPeriodLower");
    expect(engine).toContain("$dailyLower->mulDiv($primDay, 1)");
  });

  it("read-only API ve CSV aynı canonical service sonucunu kullanır", () => {
    const router = source("api/src/Router.php");
    const controller = source("api/src/Controllers/MaasHesaplamaController.php");
    const api = source("src/api/maas-hesaplama.api.ts");
    const page = source("src/features/raporlar/pages/MaasHesaplamaMerkeziPage.tsx");
    expect(router).toContain("/maas-hesaplama/sgk-sonuclari");
    expect(router).toContain("/maas-hesaplama/sgk-sonuclari/export.csv");
    expect(controller.match(/SgkPrimGunuService::listCanonicalResults/g)?.length).toBe(2);
    expect(controller).toContain("sgk_hesap_hash");
    expect(controller).toContain("snapshot_revision_no");
    expect(api).toContain("downloadSgkPrimGunuSonuclariCsv");
    expect(page).toContain("maas-hesaplama-sgk-export-csv");
    expect(page).toContain("downloadSgkPrimGunuSonuclariCsv");
  });

  it("hastalık ilk iki gün değeri eksikse null kalır", () => {
    const controller = source("api/src/Controllers/SureclerController.php");
    const owner = controller.slice(controller.indexOf("private static function resolveIlkIkiGunFirmaOderMi"));
    expect(owner).toContain("return null;");
    expect(owner).not.toContain("$body['ilk_iki_gun_firma_oder_mi'] ?? false");
  });
});
