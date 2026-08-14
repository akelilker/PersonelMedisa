import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

test.describe("S97 personel import dry-run UI", () => {
  test("opens dry-run modal, runs validation, shows masked errors", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");

    await page.goto("/personeller");
    await expect(page.getByTestId("personeller-import-dry-run-open")).toBeVisible();
    await page.getByTestId("personeller-import-dry-run-open").click();

    await expect(page.getByTestId("personel-import-dry-run-info")).toContainText(
      "Bu aşama yalnız doğrulama yapar"
    );
    await expect(page.getByText(/Sisteme aktar/i)).toHaveCount(0);

    const csv = [
      "tc_kimlik_no;sicil_no;ad;soyad;dogum_tarihi;dogum_yeri;telefon;kan_grubu;acil_durum_kisi;acil_durum_telefon;ise_giris_tarihi;sube;departman;gorev;personel_tipi",
      "123;IMP-1;Ayşe;Yılmaz;15/05/1990;Ankara;05321112233;A Rh+;Ali;05324445566;2024-01-10;Merkez;Idari;Asistan;Tam Zamanli"
    ].join("\r\n");

    await page.getByTestId("personel-import-file-input").setInputFiles({
      name: "personel-dry-run.csv",
      mimeType: "text/csv",
      buffer: Buffer.from(csv, "utf8")
    });

    await page.getByTestId("personel-import-dry-run-run").click();
    await expect(page.getByTestId("personel-import-dry-run-summary")).toBeVisible();
    await expect(page.getByTestId("personel-import-dry-run-errors")).toBeVisible();
    await expect(page.getByText("*23")).toBeVisible();
    await expect(page.getByText("T.C. Kimlik No geçersiz.")).toBeVisible();
  });

  test("unauthorized role does not see import action", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/personeller");
    await expect(page.getByTestId("personeller-import-dry-run-open")).toHaveCount(0);
  });

  test("accepts current optional and scope columns in the staging header", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/personeller");
    await page.getByTestId("personeller-import-dry-run-open").click();

    const csv = [
      "tc_kimlik_no;sicil_no;ad;soyad;dogum_tarihi;dogum_yeri;telefon;kan_grubu;acil_durum_kisi;acil_durum_telefon;ise_giris_tarihi;sube;departman;gorev;personel_tipi;sgk_isveren;calisma_lokasyonu;bolum;birim;pozisyon;calisan_kapsami",
      "10000000146;IMP-OPTIONAL;Ayşe;Yılmaz;1990-05-15;Ankara;05321112233;A Rh+;Ali;05324445566;2024-01-10;Merkez;İdari İşler;Asistan;Tam Zamanli;Medisa;Karabük;İdari İşler;Muhasebe;Muhasebe Elemanı;IC_PERSONEL"
    ].join("\r\n");

    await page.getByTestId("personel-import-file-input").setInputFiles({
      name: "personel-dry-run-optional.csv",
      mimeType: "text/csv",
      buffer: Buffer.from(csv, "utf8")
    });
    await page.getByTestId("personel-import-dry-run-run").click();

    await expect(page.getByTestId("personel-import-dry-run-summary")).toContainText("1");
    await expect(page.getByTestId("personel-import-dry-run-errors")).toHaveCount(0);
  });
});
