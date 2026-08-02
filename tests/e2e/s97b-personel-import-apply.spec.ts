import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

test.describe("S97-B personel import apply UI", () => {
  test("dry-run ready state opens apply confirm and completes synthetic apply", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/personeller");
    await page.getByTestId("personeller-import-dry-run-open").click();

    const csv = [
      "tc_kimlik_no;sicil_no;ad;soyad;dogum_tarihi;dogum_yeri;telefon;kan_grubu;acil_durum_kisi;acil_durum_telefon;ise_giris_tarihi;sube;departman;gorev;personel_tipi",
      "10000000146;IMP-A1;Ayşe;Yılmaz;1990-05-15;Ankara;05321112233;A Rh+;Ali;05324445566;2024-01-10;Merkez;Idari Isler;Asistan;Tam Zamanli",
      "10000000154;IMP-A2;Mehmet;Demir;1991-06-16;Izmir;05321112234;B Rh+;Veli;05324445567;2024-02-10;Merkez;Idari Isler;Asistan;Tam Zamanli"
    ].join("\r\n");

    await page.getByTestId("personel-import-file-input").setInputFiles({
      name: "personel-apply.csv",
      mimeType: "text/csv",
      buffer: Buffer.from(csv, "utf8")
    });
    await page.getByTestId("personel-import-dry-run-run").click();
    await expect(page.getByTestId("personel-import-ready-banner")).toBeVisible();
    await expect(page.getByTestId("personel-import-apply-open")).toBeVisible();

    await page.getByTestId("personel-import-apply-open").click();
    await expect(page.getByTestId("personel-import-apply-dialog-title")).toContainText(
      "Personelleri Sisteme Aktar"
    );
    await expect(page.getByText(/Ücret, bordro kapsamı ve SGK statüsü oluşturmaz/i)).toBeVisible();
    await page
      .getByTestId("personel-import-apply-confirmation")
      .getByLabel("Onay metni")
      .fill("PERSONEL_IMPORT_ONAYLIYORUM");
    await page.getByTestId("personel-import-apply-dialog-confirm").click();
    await expect(page.getByTestId("personel-import-apply-success")).toBeVisible();
    await expect(page.getByTestId("personel-import-apply-open")).toHaveCount(0);
  });

  test("birim amiri cannot open import apply entry", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/personeller");
    await expect(page.getByTestId("personeller-import-dry-run-open")).toHaveCount(0);
  });
});
