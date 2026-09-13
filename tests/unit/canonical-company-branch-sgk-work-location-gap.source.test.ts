import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const read = (rel: string) => readFileSync(resolve(root, rel), "utf8");

describe("canonical company / branch / SGK / work-location gap closure", () => {
  it("keeps personel axes independent in write owners (no silent cross-axis mutation)", () => {
    const kalici = read("api/src/Services/Personel/PersonelKaliciSubeDegisikligiService.php");
    expect(kalici).toContain("UPDATE personeller SET sube_id = :yeni");
    expect(kalici).toContain("korunan_sgk_isveren_id");
    expect(kalici).toContain("korunan_calisma_lokasyonu_id");
    expect(kalici).toContain("Exactly one column changes here");

    const org = read("api/src/Services/Personel/PersonelOrganizasyonDegisikligiService.php");
    expect(org).toContain("sgk_isveren_id");
    expect(org).toContain("calisma_lokasyonu_id");
    expect(org).toContain("unset($payload['sube_id'])");
    expect(org).toContain("ERROR_GENERIC_PUT = 'PERSONEL_ORGANIZASYON_CANONICAL_OWNER_REQUIRED'");

    const basic = read("api/src/Services/Personel/PersonelBasicUpdateService.php");
    expect(basic).toContain("Kalici sube degisikligi canonical owner gerektirir.");
    expect(basic).toContain("PersonelOrganizasyonDegisikligiService::TRACKED_FIELDS");
  });

  it("exposes SGK catalog with kod + sirket_id and lokasyon catalog with sube_id metadata", () => {
    const referans = read("api/src/Controllers/ReferansController.php");
    expect(referans).toContain("SELECT id, kod, ad, sirket_id FROM sgk_isverenler");
    expect(referans).toContain("SELECT id, kod, ad, sube_id FROM calisma_lokasyonlari");
    expect(referans).toContain("Catalog parentage (sube_id) is metadata only");

    const referansApi = read("src/api/referans.api.ts");
    expect(referansApi).toContain("fetchSgkIsverenCatalog");
    expect(referansApi).toContain('normalizeIdOptions(response.data, "sube_id")');
    expect(referansApi).toContain("item.kisa_kod ?? item.kisaKod ?? item.kod");
  });

  it("loads Yönetim SGK scope options from the full referans catalog, not branch defaults", () => {
    const page = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
    expect(page).toContain("fetchSgkIsverenCatalog");
    expect(page).toContain("Full SGK catalog for user-scope grants");
    expect(page).not.toContain("map.set(sube.sgk_isveren.id, sube.sgk_isveren)");

    const scopeField = read("src/features/yonetim/components/YonetimSubeScopeField.tsx");
    expect(scopeField).toContain("SGK / bordro işveren kapsamı");
    expect(scopeField).toContain("Fiziksel şube yetkisi");
  });

  it("keeps create org-deps as company-consistency UX without writing cross-axis defaults", () => {
    const deps = read("src/features/personeller/personel-create-org-deps.ts");
    expect(deps).toContain("filterSgkIsverenOptionsForSube");
    expect(deps).toContain("fail-closed");
    expect(deps).toContain("filterStatuOptionsForCreate");
    expect(deps).toContain("mapCalismaLokasyonuDisplayOptions");
    expect(deps).not.toContain("calisma_lokasyonu_id");
  });
});
