import { expect, test, type Locator, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { expectThreeButtonMainMenu } from "./helpers/main-menu";
import { mockApi } from "./helpers/mock-api";

const DUPLICATE_TC_MESSAGE = "Bu T.C. Kimlik No ile kayıt açılamaz.";
const SUBE_MISMATCH_MESSAGE = "Seçilen şube aktif şube filtresiyle uyuşmuyor.";

async function openKayitModal(page: Page) {
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

async function fillRequiredPersonelFields(modal: Locator, options?: { tcKimlikNo?: string; sube?: string }) {
  await modal.locator('[name="create-tc"]').fill(options?.tcKimlikNo ?? "19876543210");
  await modal.locator('[name="create-ad"]').fill("Kayit");
  await modal.locator('[name="create-soyad"]').fill("Hata");
  await modal.locator('[name="create-dogum"]').fill("1991-04-12");
  await modal.locator('[name="create-telefon"]').fill("05324445566");
  await modal.locator('[name="create-acil-kisi"]').fill("Acil Kisi");
  await modal.locator('[name="create-acil-tel"]').fill("05327778899");
  await modal.locator('[name="create-sicil"]').fill("E2E-HATA-01");
  await modal.locator('[name="create-ise-giris"]').fill("2026-06-15");

  await selectCreateOption(modal, "Şube", options?.sube ?? "Merkez");
  await selectCreateOption(modal, "Bölüm", "Döşeme");
  await selectCreateOption(modal, "Görev / Unvan", "Genel Müdür");
  await selectCreateOption(modal, "Personel Tipi", "Tam Zamanlı");
}

function trackRuntimeSignals(page: Page) {
  const pageErrors: string[] = [];
  const console500: string[] = [];

  page.on("pageerror", (error) => {
    pageErrors.push(error.message);
  });
  page.on("console", (message) => {
    const text = message.text();
    if (text.includes("500")) {
      console500.push(text);
    }
  });

  return { pageErrors, console500 };
}

async function expectStillOnCreateTab(modal: Locator) {
  await expect(modal).toBeVisible();
  await expect(modal.locator('[name="create-tc"]')).toBeVisible();
}

test.describe("Kayit personel hata senaryolari", () => {
  test("duplicate TC hatasinda modal acik kalir, TC alanina inline hata ve focus verir", async ({ page }) => {
    const runtimeSignals = trackRuntimeSignals(page);
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    await expectThreeButtonMainMenu(page, true);

    const kayitModal = await openKayitModal(page);
    await fillRequiredPersonelFields(kayitModal, { tcKimlikNo: "12345678901" });

    const createResponse = page.waitForResponse(
      (response) => new URL(response.url()).pathname === "/api/personeller" && response.request().method() === "POST"
    );
    await kayitModal.getByRole("button", { name: "Kaydet" }).click();
    await expect((await createResponse).status()).toBe(409);

    await expectStillOnCreateTab(kayitModal);
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    await expect(kayitModal.locator(".personel-create-error").filter({ hasText: DUPLICATE_TC_MESSAGE })).toHaveCount(2);
    await expect(kayitModal.locator('[name="create-tc"]')).toBeFocused();

    await kayitModal.locator('[name="create-tc"]').fill("19876543211");
    await expect(kayitModal.locator(".personel-create-error").filter({ hasText: DUPLICATE_TC_MESSAGE })).toHaveCount(1);

    expect(runtimeSignals.pageErrors).toEqual([]);
    expect(runtimeSignals.console500).toEqual([]);
  });

  test("sube mismatch 403 hatasinda modal acik kalir, sube alanina inline hata ve focus verir", async ({ page }) => {
    const runtimeSignals = trackRuntimeSignals(page);
    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "demo123" });
    await expectThreeButtonMainMenu(page, true);

    const kayitModal = await openKayitModal(page);
    await fillRequiredPersonelFields(kayitModal, { tcKimlikNo: "19876543212", sube: "Depolama" });

    const createResponse = page.waitForResponse(
      (response) => new URL(response.url()).pathname === "/api/personeller" && response.request().method() === "POST"
    );
    await kayitModal.getByRole("button", { name: "Kaydet" }).click();
    await expect((await createResponse).status()).toBe(403);

    await expectStillOnCreateTab(kayitModal);
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    await expect(kayitModal.locator(".personel-create-error").filter({ hasText: SUBE_MISMATCH_MESSAGE })).toHaveCount(2);
    await expect(kayitModal.locator("#create-sube")).toBeFocused();

    await selectCreateOption(kayitModal, "Şube", "Merkez");
    await expect(kayitModal.locator(".personel-create-error").filter({ hasText: SUBE_MISMATCH_MESSAGE })).toHaveCount(1);

    expect(runtimeSignals.pageErrors).toEqual([]);
    expect(runtimeSignals.console500).toEqual([]);
  });
});
