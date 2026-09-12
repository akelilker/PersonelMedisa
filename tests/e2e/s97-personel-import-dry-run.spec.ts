import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

/** Kanonik giris: Kayit ve Surec > Kayit footer linki ("Excel'den Toplu Kayit Aktarmak Icin Tiklayiniz."). */
async function openBulkImport(page: Page): Promise<void> {
  await page.goto("/");
  await page.getByTestId("menu-kayit-surec").click();
  await page.getByTestId("kayit-bulk-import-link").click();
  await expect(page.getByTestId("personel-import-dry-run-title")).toContainText("Toplu Kayıt Aktarma");
}

test.describe("S97 personel import dry-run UI", () => {
  test("opens dry-run modal, runs validation, shows masked errors", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await openBulkImport(page);

    // Dry-run oncesi gercek apply/write aksiyonu YOK (fail-closed).
    await expect(page.getByTestId("personel-import-apply-open")).toHaveCount(0);
    // Native file input gizli: tarayici "No file chosen" metni gorunmez.
    await expect(page.getByText(/No file chosen/i)).toHaveCount(0);
    await expect(page.getByTestId("personel-import-file-input")).toHaveAttribute("aria-hidden", "true");

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
    await expect(page.getByText("*23").first()).toBeVisible();
    await expect(page.getByText("T.C. Kimlik No geçersiz.")).toBeVisible();

    // HARD ERROR: apply kapali, hata dosyasi indirilebilir.
    await expect(page.getByTestId("personel-import-dry-run-blocking")).toContainText("hatalı kayıt bulundu");
    await expect(page.getByTestId("personel-import-apply-open")).toHaveCount(0);
    await expect(page.getByTestId("personel-import-errors-download")).toContainText("Hata Dosyasını İndir");
  });

  test("unauthorized role does not see import action", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/");
    // Fail-closed: yetkisiz rolde toplu kayit girisleri hicbir yerde olusmaz.
    await expect(page.getByTestId("kayit-bulk-import-link")).toHaveCount(0);
    await expect(page.getByTestId("personel-import-dry-run-title")).toHaveCount(0);
  });

  test("accepts current optional and scope columns in the staging header", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await openBulkImport(page);

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
