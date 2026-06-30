import { expect, test, type Locator } from "@playwright/test";
import { login } from "./helpers/auth";
import { expectThreeButtonMainMenu } from "./helpers/main-menu";
import { mockApi } from "./helpers/mock-api";

async function openKayitModal(page: import("@playwright/test").Page) {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container").last();
  await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  await expect(kayitModal.getByTestId("kayit-tab-yeni-kayit")).toBeVisible();
  return kayitModal;
}

async function selectCreateOption(modal: Locator, label: string, optionLabel: string) {
  await modal.getByRole("combobox", { name: label }).click();
  await modal.getByRole("option", { name: optionLabel }).click();
}

async function fillRequiredPersonelFields(modal: Locator, options?: { includeSube?: boolean }) {
  await modal.locator('[name="create-tc"]').fill("19876543210");
  await modal.locator('[name="create-ad"]').fill("Kayit");
  await modal.locator('[name="create-soyad"]').fill("Deneme");
  await modal.locator('[name="create-dogum"]').fill("1991-04-12");
  await modal.locator('[name="create-telefon"]').fill("05324445566");
  await modal.locator('[name="create-acil-kisi"]').fill("Acil Kisi");
  await modal.locator('[name="create-acil-tel"]').fill("05327778899");
  await modal.locator('[name="create-sicil"]').fill("E2E-KAYIT-01");
  await modal.locator('[name="create-ise-giris"]').fill("2026-06-15");

  if (options?.includeSube !== false) {
    await selectCreateOption(modal, "Şube", "Merkez");
  }

  await selectCreateOption(modal, "Bölüm", "Döşeme");
  await selectCreateOption(modal, "Görev / Unvan", "Genel Müdür");
  await selectCreateOption(modal, "Personel Tipi", "Tam Zamanlı");
}

test.describe("Kayit yeni personel", () => {
  test("Kayit sekmesinde sube zorunlu, maas opsiyonel ve kart uyarısı görünür", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    await expectThreeButtonMainMenu(page, true);

    const kayitModal = await openKayitModal(page);
    await expect(kayitModal.getByRole("combobox", { name: "Şube" })).toBeVisible();

    await kayitModal.getByRole("combobox", { name: "Şube" }).click();
    await expect(kayitModal.getByRole("option", { name: "Merkez" })).toBeVisible();
    await expect(kayitModal.getByRole("option", { name: "Depolama" })).toBeVisible();
    await expect(kayitModal.getByRole("option", { name: "Pasif Şube" })).toHaveCount(0);
    await kayitModal.getByRole("combobox", { name: "Şube" }).click();

    await fillRequiredPersonelFields(kayitModal, { includeSube: false });
    await kayitModal.getByRole("button", { name: "Kaydet" }).click();
    await expect(kayitModal.locator(".personel-create-error")).toContainText("Şube seçilmelidir.");

    await selectCreateOption(kayitModal, "Şube", "Merkez");
    await kayitModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(kayitModal.getByRole("heading", { name: /Kayit DENEME/i })).toBeVisible({
      timeout: 15_000
    });

    await kayitModal.getByRole("button", { name: "Kapat" }).click();

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: /Kayit Deneme.*kişisinin kartını aç/i }).first().click();

    await expect(page.getByTestId("personel-maas-eksik-uyari")).toBeVisible();
    await expect(page.getByTestId("personel-maas-eksik-uyari")).toHaveText("Maaş bilgisi eksik.");
  });

  test("maaşı dolu personelde maaş eksik uyarısı göstermez", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page.getByTestId("personel-maas-eksik-uyari")).toHaveCount(0);
  });

  test("MUHASEBE rolü maaş eksik uyarısını görür", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "demo123" });

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Maas Eksik.*kişisinin kartını aç/i }).first().click();
    await expect(page.getByTestId("personel-maas-eksik-uyari")).toHaveText("Maaş bilgisi eksik.");
  });

  test("Personeller ekranında yeni personel ekle yolu görünmez", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page.getByRole("button", { name: "Yeni personel ekle" })).toHaveCount(0);

    await page.goto("/");
    await expectThreeButtonMainMenu(page, true);
  });

  test("Personel Karti acikken kayit sonrasi liste cache guncellenir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first()).toBeVisible({
      timeout: 15_000
    });

    await page.goto("/");
    await expectThreeButtonMainMenu(page, true);

    const kayitModal = await openKayitModal(page);
    await kayitModal.locator('[name="create-tc"]').fill("17654321987");
    await kayitModal.locator('[name="create-ad"]').fill("CacheSync");
    await kayitModal.locator('[name="create-soyad"]').fill("E2E");
    await kayitModal.locator('[name="create-dogum"]').fill("1992-08-20");
    await kayitModal.locator('[name="create-telefon"]').fill("05321112233");
    await kayitModal.locator('[name="create-acil-kisi"]').fill("Acil Kisi");
    await kayitModal.locator('[name="create-acil-tel"]').fill("05324443322");
    await kayitModal.locator('[name="create-sicil"]').fill("E2E-CACHE-SYNC-01");
    await kayitModal.locator('[name="create-ise-giris"]').fill("2026-06-20");
    await selectCreateOption(kayitModal, "Şube", "Merkez");
    await selectCreateOption(kayitModal, "Bölüm", "Döşeme");
    await selectCreateOption(kayitModal, "Görev / Unvan", "Genel Müdür");
    await selectCreateOption(kayitModal, "Personel Tipi", "Tam Zamanlı");
    await kayitModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(kayitModal.getByRole("heading", { name: /CacheSync E2E/i })).toBeVisible({
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

    await expect(
      page.getByRole("link", { name: /CacheSync E2E.*kişisinin kartını aç/i }).first()
    ).toBeVisible({ timeout: 2_000 });
  });
});
