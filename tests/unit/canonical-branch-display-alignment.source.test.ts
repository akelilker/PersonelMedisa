import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const read = (rel: string) => readFileSync(resolve(root, rel), "utf8");

describe("canonical branch display alignment", () => {
  it("keeps SubeReadModel as the only display-name owner", () => {
    const model = read("api/src/Services/Organizasyon/SubeReadModel.php");
    expect(model).toContain("function tamAd");
    expect(model).toContain("function findById");
    expect(model).toContain("'tam_ad' => self::tamAd");
    expect(model).not.toMatch(/ALTER\s+TABLE.*tam_ad/i);
  });

  it("snapshot fetchSube stores SubeReadModel.tam_ad into display ad", () => {
    const snap = read("api/src/Services/MaasHesaplamaSnapshotService.php");
    expect(snap).toContain("use Medisa\\Api\\Services\\Organizasyon\\SubeReadModel");
    expect(snap).toMatch(/function fetchSube[\s\S]*SubeReadModel::findById/);
    expect(snap).toMatch(/'ad'\s*=>\s*\(string\)\s*\$mapped\['tam_ad'\]/);
  });

  it("personel API keeps sube_adi=global and sube_kisa_adi=short", () => {
    const ctrl = read("api/src/Controllers/PersonellerController.php");
    expect(ctrl).toContain("'sube_adi' => self::subeGosterimAdi($row)");
    expect(ctrl).toContain("'sube_kisa_adi' => $row['sube_adi']");
    expect(ctrl).toContain("SubeReadModel::tamAd");
  });

  it("login session payload exposes tam_ad on ad for global header consumers", () => {
    const login = read("api/src/Auth/LoginController.php");
    expect(login).toContain("'ad' => $mapped['tam_ad']");
    expect(login).toContain("'kisa_ad' => $mapped['ad']");
    expect(login).toContain("'tam_ad' => $mapped['tam_ad']");
  });

  it("yonetim panel keeps local short labels when company is selected", () => {
    const page = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
    expect(page).toContain(
      "const subeListLabel = (item: YonetimSube) => (selectedSirketId != null ? item.ad : item.tam_ad)"
    );
    expect(page).toContain("label: sube.tam_ad");
  });

  it("auth.api prefers tam_ad for session branch labels", () => {
    const auth = read("src/api/auth.api.ts");
    expect(auth).toContain("readString(row.tam_ad)");
  });

  it("does not invent a frontend company+branch concat product owner", () => {
    const helper = read("src/lib/organizasyon/sube-display-name.ts");
    expect(helper).toContain("derives `tam_ad`");
    expect(helper).toContain("Product code consumes the `tam_ad` field");
    const snapFe = read("src/features/raporlar/pages/MaasHesaplamaMerkeziPage.tsx");
    expect(snapFe).toContain("label: sube.tam_ad");
    expect(snapFe).not.toMatch(/deriveSubeTamAd\(/);
  });

  it("aligns residual global report/payroll surfaces via SubeReadModel.tamAd", () => {
    const serbest = read("api/src/Controllers/SerbestZamanController.php");
    expect(serbest).toContain("SubeReadModel::tamAd");
    expect(serbest).toContain("sube_sirket_adi");

    const bordro = read("api/src/Services/BordroOnIzlemeService.php");
    expect(bordro).toContain("SubeReadModel::tamAd");
    expect(bordro).toContain("sube_sirket_adi");

    const arsiv = read("api/src/Controllers/ArsivController.php");
    expect(arsiv).toContain("SubeReadModel::tamAd");
    expect(arsiv).not.toMatch(/findById\(/);
  });

  it("keeps snapshot historical payload free of live branch-name re-resolve", () => {
    const snap = read("api/src/Services/MaasHesaplamaSnapshotService.php");
    expect(snap).toMatch(/function fetchSube[\s\S]*?'ad'\s*=>\s*\(string\)\s*\$mapped\['tam_ad'\]/);
    const detailStart = snap.indexOf("function getSnapshotDetail");
    const detailEnd = snap.indexOf("function verifySnapshotHash", detailStart);
    expect(detailStart).toBeGreaterThan(-1);
    expect(detailEnd).toBeGreaterThan(detailStart);
    const detailBody = snap.slice(detailStart, detailEnd);
    expect(detailBody).toContain("mapSnapshotRow");
    expect(detailBody).not.toContain("fetchSube");
    const mapStart = snap.indexOf("function mapSnapshotRow");
    const mapEnd = snap.indexOf("// ------------------------------------------------------------------", mapStart);
    const mapBody = snap.slice(mapStart, mapEnd > mapStart ? mapEnd : mapStart + 800);
    expect(mapBody).toContain("'sube_id'");
    expect(mapBody).not.toContain("'ad'");
  });
});
