import { expect, test, type Locator, type Page } from "@playwright/test";
import { login, loginAsMockRole } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

function trackPageErrors(page: Page) {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => {
    pageErrors.push(error.message);
  });
  return pageErrors;
}

async function openKayitModal(page: Page) {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container").last();
  await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  return kayitModal;
}

async function selectCreateOption(modal: Locator, label: string, optionLabel: string) {
  await modal.getByRole("combobox", { name: label }).click();
  await modal.getByRole("option", { name: optionLabel }).click();
}

async function createMaassizPersonel(page: Page) {
  const kayitModal = await openKayitModal(page);
  await kayitModal.locator('[name="create-tc"]').fill("19876543219");
  await kayitModal.locator('[name="create-ad"]').fill("Ucret");
  await kayitModal.locator('[name="create-soyad"]').fill("Aday");
  await kayitModal.locator('[name="create-dogum"]').fill("1991-04-12");
  await kayitModal.locator('[name="create-telefon"]').fill("05324445566");
  await kayitModal.locator('[name="create-acil-kisi"]').fill("Acil Kisi");
  await kayitModal.locator('[name="create-acil-tel"]').fill("05327778899");
  await kayitModal.locator('[name="create-sicil"]').fill("E2E-UCRET-01");
  await kayitModal.locator('[name="create-ise-giris"]').fill("2026-01-10");
  await selectCreateOption(kayitModal, "Şube", "Merkez");
  await selectCreateOption(kayitModal, "Bölüm", "Döşeme");
  await selectCreateOption(kayitModal, "Görev / Unvan", "Genel Müdür");
  await selectCreateOption(kayitModal, "Personel Tipi", "Tam Zamanlı");
  await kayitModal.getByRole("button", { name: "Kaydet" }).click();
  await expect(kayitModal.getByRole("heading", { name: /Ucret ADAY/i })).toBeVisible({
    timeout: 15_000
  });
  await kayitModal.getByRole("button", { name: "Kapat" }).click();
}

async function openPersonelKart(page: Page, namePattern: RegExp) {
  await page.getByTestId("menu-personel-karti").click();
  await expect(page).toHaveURL(/\/personeller$/);
  await page.getByRole("link", { name: namePattern }).first().click();
  await expect(page).toHaveURL(/\/personeller\/\d+$/);
  await page.getByRole("tab", { name: "Genel" }).click();
}

async function fillUcretModal(page: Page, tutar: string, baslangic: string) {
  const modal = page.locator(".modal-container").filter({
    has: page.getByRole("heading", { name: /Yeni Ücret Dönemi Başlat/i })
  }).last();
  await expect(modal).toBeVisible();
  await modal.locator('[name="ucret-tutar"]').fill(tutar);
  await modal.locator('[name="ucret-baslangic"]').fill(baslangic);
  await modal.getByTestId("personel-ucret-form-kaydet").click();
  return modal;
}

test.describe("S77-B personel ücret geçmişi", () => {
  test("yetkili kullanıcı ücret geçmişini görür, ekler, overlap engeller", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "demo123" });

    await createMaassizPersonel(page);
    await openPersonelKart(page, /Ucret Aday.*kişisinin kartını aç/i);

    await expect(page.getByTestId("personel-ucret-gecmisi-card")).toBeVisible();
    await expect(page.getByTestId("personel-ucret-bos")).toBeVisible();

    await page.getByTestId("personel-ucret-yeni-donem").click();
    await fillUcretModal(page, "42000", "2026-01-10");
    await expect(page.getByTestId("personel-ucret-guncel")).toContainText(/42/);
    await expect(page.getByTestId("personel-ucret-list").locator("li")).toHaveCount(1);

    await page.getByTestId("personel-ucret-yeni-donem").click();
    await fillUcretModal(page, "45000", "2026-08-01");
    await expect(page.getByTestId("personel-ucret-list").locator("li")).toHaveCount(2);

    await page.getByTestId("personel-ucret-yeni-donem").click();
    const overlapModal = await fillUcretModal(page, "46000", "2026-08-01");
    await expect(overlapModal.getByTestId("personel-ucret-form-hata")).toContainText(
      "Bu personel için seçilen tarih aralığında başka bir ücret kaydı bulunmaktadır."
    );

    await page.reload();
    await page.getByRole("tab", { name: "Genel" }).click();
    await expect(page.getByTestId("personel-ucret-list").locator("li")).toHaveCount(2);
    expect(pageErrors).toEqual([]);
  });

  test("BIRIM_AMIRI ücret bölümünü görmez ve fetch etmez", async ({ page }) => {
    const ucretRequests: string[] = [];
    page.on("request", (request) => {
      if (/\/api\/personeller\/\d+\/ucretler/.test(request.url())) {
        ucretRequests.push(request.url());
      }
    });

    await loginAsMockRole(page, "BIRIM_AMIRI");
    await openPersonelKart(page, /Ayşe Yılmaz.*kişisinin kartını aç/i);
    await expect(page.getByTestId("personel-ucret-gecmisi-card")).toHaveCount(0);
    await expect(page.getByTestId("personel-maas-eksik-uyari")).toHaveCount(0);
    expect(ucretRequests).toEqual([]);
  });

  test("GENEL_YONETICI mevzuat paneline erişir ve çakışmayı engeller", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/yonetim-paneli?tab=mevzuat");
    await expect(page.getByTestId("yonetim-section-mevzuat")).toBeVisible();

    await page.getByTestId("yonetim-mevzuat-yeni").click();
    let modal = page.locator(".modal-container").filter({
      has: page.getByRole("heading", { name: /Yeni Mevzuat Parametresi/i })
    }).last();
    await modal.locator('[name="mevzuat-kod"]').fill("S77_TEST_ORAN");
    await modal.locator('[name="mevzuat-deger-tipi"]').selectOption("SAYISAL");
    await modal.locator('[name="mevzuat-sayisal-deger"]').fill("0.01");
    await modal.locator('[name="mevzuat-baslangic"]').fill("2026-01-01");
    await modal.getByRole("button", { name: "Kaydet" }).click();
    await expect(page.locator('[data-testid^="yonetim-mevzuat-satir-"]')).toHaveCount(1);

    await page.getByTestId("yonetim-mevzuat-yeni").click();
    modal = page.locator(".modal-container").filter({
      has: page.getByRole("heading", { name: /Yeni Mevzuat Parametresi/i })
    }).last();
    await modal.locator('[name="mevzuat-kod"]').fill("S77_TEST_ORAN");
    await modal.locator('[name="mevzuat-deger-tipi"]').selectOption("SAYISAL");
    await modal.locator('[name="mevzuat-sayisal-deger"]').fill("0.02");
    await modal.locator('[name="mevzuat-baslangic"]').fill("2026-01-01");
    await modal.getByRole("button", { name: "Kaydet" }).click();
    await expect(modal.locator(".personel-create-error")).toBeVisible();
  });
});
