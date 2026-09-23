import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

function read(rel: string): string {
  return readFileSync(resolve(process.cwd(), rel), "utf8");
}

describe("personel kapsam display labels (user-facing only)", () => {
  it("keeps technical enum wire values while using Dahili/Harici user labels", () => {
    const enumDisplay = read("src/lib/display/enum-display.ts");
    expect(enumDisplay).toContain('IC_PERSONEL: "Dahili Personel"');
    expect(enumDisplay).toContain('DIS_KAYNAK: "Harici Personel"');
    expect(enumDisplay).toMatch(/value:\s*"IC_PERSONEL"/);
    expect(enumDisplay).toMatch(/value:\s*"DIS_KAYNAK"/);

    const types = read("src/types/personel.ts");
    expect(types).toContain('export type PersonelCalisanKapsami = "IC_PERSONEL" | "DIS_KAYNAK"');

    const migration = read("api/migrations/066_personel_calisan_kapsami.sql");
    expect(migration).toContain("ENUM(''IC_PERSONEL'', ''DIS_KAYNAK'')");
  });

  it("uses Dahili/Harici in import errors, ücret envanteri, yönetim tipi and import catalog", () => {
    const importErrors = read("src/features/personeller/personel-import-error-messages.ts");
    expect(importErrors).toContain("Dahili Personelin telefonu daha sonra Kayıt ve Süreç üzerinden tamamlanabilir.");
    expect(importErrors).not.toContain("İç personel");
    expect(importErrors).toContain("Harici Personel kaydına PersonelMedisa SGK işvereni atanamaz.");

    const envanter = read("src/features/yonetim/components/UcretTipiEnvanteriPanel.tsx");
    expect(envanter).toContain("Aktif Dahili Personel");
    expect(envanter).toContain("Aktif Dahili Personel bulunamadı.");
    expect(envanter).toContain("Harici Personel ücret tipi kapsamı dışındadır");
    expect(envanter).not.toContain("Aktif iç personel");
    expect(envanter).not.toContain("Dış kaynak");
    expect(envanter).not.toContain("(DIS_KAYNAK)");

    const yonetim = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
    expect(yonetim).toContain('IC_PERSONEL: "Dahili Personel"');
    expect(yonetim).toContain('HARICI: "Harici Personel"');
    expect(yonetim).toContain("Dahili Personel kullanıcıları için personel seçimi zorunludur.");
    expect(yonetim).not.toContain('"İç Personel"');
    expect(yonetim).not.toMatch(/HARICI:\s*"Harici"/);
    expect(yonetim).toContain('kullaniciTipi: "IC_PERSONEL"');
    expect(yonetim).toContain('value: "HARICI"');

    const catalog = read("api/src/Services/Personel/PersonelImportReferenceCatalogService.php");
    expect(catalog).toContain("'aciklama' => 'Dahili Personel (varsayılan).'");
    expect(catalog).toContain("'aciklama' => 'Harici Personel.'");
    expect(catalog).toContain("PersonelCalisanKapsamService::IC_PERSONEL");
    expect(catalog).toContain("PersonelCalisanKapsamService::DIS_KAYNAK");
  });

  it("keeps user-facing backend messages free of old names and raw enum codes", () => {
    const kapsam = read("api/src/Services/Personel/PersonelCalisanKapsamService.php");
    expect(kapsam).toContain("Dahili Personel icin gecerli T.C. Kimlik No zorunludur.");
    expect(kapsam).toContain("Harici Personel icin kalici org baglantisi veya aktif gecici gorevlendirme gerekir.");
    expect(kapsam).toContain("Bu personel Harici Personel kapsamindadir;");
    expect(kapsam).not.toContain("Ic personel");
    expect(kapsam).not.toContain("DIS_KAYNAK personeli");
    expect(kapsam).not.toContain("DIS_KAYNAK kapsamindadir");
    expect(kapsam).toContain("public const IC_PERSONEL = 'IC_PERSONEL'");
    expect(kapsam).toContain("public const DIS_KAYNAK = 'DIS_KAYNAK'");

    const controller = read("api/src/Controllers/PersonellerController.php");
    expect(controller).toContain("Harici Personel kaydina maas/bordro kaydi olusturulamaz.");
    expect(controller).not.toContain("DIS_KAYNAK personeline");

    const gecici = read("api/src/Services/Personel/PersonelGeciciGorevlendirmeService.php");
    expect(gecici).toContain("Harici Personel havuzu icin yetki yok.");
    expect(gecici).toContain("Gecici gorevlendirme yalniz Harici Personel icin kullanilir.");
    expect(gecici).not.toContain("Dis kaynak");
    expect(gecici).not.toContain("DIS_KAYNAK personel");

    const org = read("api/src/Services/Organizasyon/OrganizasyonService.php");
    expect(org).toContain("bağlı Dahili Personel kayıtları");
    expect(org).not.toContain("iç personel");

    const override = read("api/src/Services/Payroll/SgkManuelKodOverrideService.php");
    expect(override).toContain("Bu personel Harici Personel dizin kaydidir;");
    expect(override).not.toContain("(DIS_KAYNAK)");
  });
});
