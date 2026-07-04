import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec Ayrilma sekmesi", () => {
  test("yonetici kayit modalinda Ayrilma ile ISTEN_AYRILMA kaydeder ve personel pasife duser", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    await kayitModal.getByTestId("kayit-tab-surec").click();
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toBeVisible();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "Ayrılma" }).click();

    await expect(kayitModal.locator("[name='surec-create-bas']")).toBeVisible();
    await expect(kayitModal.locator("[name='surec-create-turu']")).toHaveCount(0);
    await expect(kayitModal.locator("[name='surec-create-turu-text']")).toHaveCount(0);
    await expect(kayitModal.locator("[name='surec-create-ucret']")).toHaveCount(0);

    await kayitModal.locator("[name='surec-create-bas']").fill("2026-05-01");
    await kayitModal.locator("[name='surec-create-bitis']").fill("2026-05-01");
    await kayitModal.locator("[name='surec-create-aciklama']").fill("E2E Kayit Ayrilma surec");

    await kayitModal.locator(".workspace-form-actions").getByRole("button", { name: "Kaydet" }).click();

    await expect(kayitModal.locator(".workspace-success--inline")).toContainText(/Süreç kaydı eklendi/i, {
      timeout: 15_000
    });

    await kayitModal.getByRole("button", { name: "Kapat" }).click();

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/İşten Ayrıldı|Pasif/i);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText(/İsten ayrılma|Isten ayrilma|Isten Ayrilma/i);
    await expect(timeline).toContainText("E2E Kayit Ayrilma surec");
  });

  test("Personel Karti acikken isten ayrilma sonrasi liste cache guncellenir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first()).toBeVisible({
      timeout: 15_000
    });

    await page.goto("/");

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "Ayrılma" }).click();
    await kayitModal.locator("[name='surec-create-bas']").fill("2026-05-01");
    await kayitModal.locator("[name='surec-create-bitis']").fill("2026-05-01");
    await kayitModal.locator("[name='surec-create-aciklama']").fill("E2E cache senkron ayrilma");

    await page.route(/\/api\/personeller\/1$/, async (route) => {
      if (route.request().method() !== "GET") {
        await route.continue();
        return;
      }

      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            ana_kart: {
              id: 1,
              tc_kimlik_no: "12345678901",
              ad: "Ayşe",
              soyad: "Yılmaz",
              aktif_durum: "PASIF",
              sube_id: 1,
              telefon: "05550000000",
              dogum_tarihi: "1992-03-14",
              dogum_yeri: "İstanbul",
              kan_grubu: "A Rh+",
              sicil_no: "P-001",
              ise_giris_tarihi: "2023-02-01",
              acil_durum_kisi: "Fatma Yılmaz",
              acil_durum_telefon: "05553334455",
              departman_id: 3,
              gorev_id: 1,
              personel_tipi_id: 1,
              bagli_amir_id: 9
            },
            sistem_ozeti: {
              hizmet_suresi: "3 yil 2 ay",
              toplam_izin_hakki: 14,
              kullanilan_izin: 4,
              kalan_izin: 10
            },
            pasiflik_durumu: {
              aktif_durum: "PASIF",
              etiket: "İşten Ayrıldı"
            },
            referans_adlari: {
              sube: "Merkez",
              departman: "Döşeme",
              gorev: "Genel Müdür",
              personel_tipi: "Tam Zamanlı",
              bagli_amir: "Demo Amir"
            }
          },
          meta: {},
          errors: []
        })
      });
    });

    await kayitModal.locator(".workspace-form-actions").getByRole("button", { name: "Kaydet" }).click();
    await expect(kayitModal.locator(".workspace-success--inline")).toContainText(/Süreç kaydı eklendi/i, {
      timeout: 15_000
    });

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await expect(kayitModal).toHaveCount(0);

    let delayPersonelList = true;
    await page.route("**/api/personeller**", async (route) => {
      const request = route.request();
      if (request.method() === "GET" && delayPersonelList) {
        await new Promise((resolve) => setTimeout(resolve, 4_000));
      }
      await route.continue();
    });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("button", { name: "Liste görünümüne geç" }).click();

    const ayseRow = page.locator(".personeller-table tbody tr").filter({ hasText: "Ayşe Yılmaz" }).first();
    await expect(ayseRow).toBeVisible({ timeout: 2_000 });
    await expect(ayseRow).toContainText("Ayrıldı", { timeout: 2_000 });
  });

  test("pasif personelde Ayrilma sekmesinde form yerine uyari gorunur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByTestId("kayit-tab-surec").click();

    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Pasif");
    await kayitModal.getByRole("option", { name: /Pasif Ornek/i }).click();

    await kayitModal.getByRole("tab", { name: "Ayrılma" }).click();

    await expect(kayitModal.locator(".surec-person-placeholder")).toContainText(
      "Bu personel pasif; ayrılma kaydı eklenmez."
    );
    await expect(kayitModal.locator("[name='surec-create-bas']")).toHaveCount(0);
  });
});
