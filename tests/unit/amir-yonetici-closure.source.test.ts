import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = resolve(process.cwd());

function read(path: string): string {
  return readFileSync(resolve(root, path), "utf8");
}

describe("amir yonetici closure owners", () => {
  it("keeps the live team summary on the existing birim amiri home", () => {
    const page = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    expect(page).toContain("Toplam Personel");
    expect(page).toContain("Geldi");
    expect(page).toContain("Gelmedi");
    expect(page).toContain("Geç Geldi");
    expect(page).toContain("İzinli / Raporlu");
    expect(page).toContain("Erken Çıktı");
    expect(page).toContain("Görevde");
    expect(page).toContain("Henüz Değerlendirilmedi");
    expect(page).toContain("fetchBirimGunlukDurum");
    expect(page).not.toContain("MOLADA");
    expect(page).not.toContain("Molada");
  });

  it("reads yearly overtime from the scoped canonical endpoint", () => {
    const page = read("src/features/self-service/pages/BirimAmiriOperationalHomePage.tsx");
    const controller = read("api/src/Controllers/HaftalikKapanisController.php");
    const router = read("api/src/Router.php");

    expect(page).toContain("fetchYillikFazlaCalismaKapsami");
    expect(page).toContain("fazlaMesaiUyariSeviyesi");
    expect(page).not.toContain("aggregateYillikFazlaCalisma");
    expect(router).toContain("HaftalikKapanisController::yillikFazlaCalismaKapsam");
    expect(controller).toContain("function yillikFazlaCalismaKapsam");
    expect(controller).toContain("OrgScope::appendPersonelOrgFilter");
    expect(controller).toContain("summarizeYillikRows");
    expect(controller).not.toContain("MOLADA");
  });

  it("adds puantaj as a report type on the existing raporlar owner", () => {
    const controller = read("api/src/Controllers/RaporlarController.php");
    const page = read("src/features/raporlar/pages/RaporlarPage.tsx");
    const columns = read("src/features/raporlar/rapor-column-contract.ts");

    expect(controller).toContain("'puantaj' => 'puantaj'");
    expect(controller).toContain("OrgScope::appendPersonelOrgFilter");
    expect(controller).toContain("function showPuantaj");
    expect(controller).not.toContain("fazla_calisma_dakika");
    expect(page).toContain('value: "puantaj", label: "Puantaj Raporu"');
    expect(page).toContain("Excel/CSV İndir");
    expect(page).toContain('data-testid="puantaj-raporu-excel"');
    expect(page).toContain('data-testid="puantaj-raporu-csv"');
    expect(page).toContain("Yazdır / PDF");
    expect(page).not.toContain("PDF İndir");
    expect(page).toContain("downloadReportCsv");
    expect(columns).toContain('key: "giris_saati"');
    expect(columns).toContain('key: "dayanak"');
    expect(columns).not.toContain("fazla_calisma");
  });
});
