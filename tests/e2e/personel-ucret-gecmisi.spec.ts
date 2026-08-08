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
  const kayitModal = page.locator(".modal-container--kayit-surec");
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

function maasUyumlulukValue(modal: Locator) {
  return modal
    .locator(".surec-shell-summary-item")
    .filter({ hasText: "Maaş (uyumluluk)" })
    .locator(".surec-shell-summary-value");
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

  async function openSurecMaliForPersonel(page: Page, search: string, optionName: RegExp) {
    const kayitModal = await openKayitModal(page);
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill(search);
    await kayitModal.getByRole("option", { name: optionName }).click();
    await kayitModal.getByRole("tab", { name: "Mali İşlemler" }).click();
    await expect(kayitModal.getByTestId("kayit-surec-ucret-panel")).toBeVisible();
    return kayitModal;
  }

  test("MUHASEBE Surec Mali Islemler wage panel create/overlap/cancel/card parity", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    const postBodies: Array<Record<string, unknown>> = [];
    const cancelPosts: string[] = [];
    let createPostCount = 0;

    page.on("request", (request) => {
      const url = request.url();
      if (!url.includes("/api/personeller/") || !url.includes("/ucretler")) {
        return;
      }
      if (request.method() === "POST" && /\/ucretler$/.test(new URL(url).pathname)) {
        createPostCount += 1;
        try {
          postBodies.push(request.postDataJSON() as Record<string, unknown>);
        } catch {
          /* ignore */
        }
      }
      if (request.method() === "POST" && url.includes("/iptal")) {
        cancelPosts.push(url);
      }
    });

    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "demo123" });
    await createMaassizPersonel(page);

    const kayitModal = await openSurecMaliForPersonel(page, "Ucret", /Ucret Aday/i);
    await expect(kayitModal.getByTestId("personel-ucret-gecmisi-card")).toBeVisible();
    await expect(kayitModal.getByTestId("personel-ucret-yeni-donem")).toBeVisible();

    await kayitModal.getByTestId("personel-ucret-yeni-donem").click();
    await fillUcretModal(page, "42000", "2026-01-10");
    await expect(kayitModal.getByTestId("personel-ucret-guncel")).toContainText(/42/);
    expect(createPostCount).toBe(1);
    expect(postBodies[0]).toMatchObject({
      ucret_tutari: 42000,
      ucret_turu: "NET",
      para_birimi: "TRY",
      gecerlilik_baslangic: "2026-01-10",
      gecerlilik_bitis: null
    });
    expect(postBodies[0]).not.toHaveProperty("user_id");
    expect(postBodies[0]).not.toHaveProperty("actor_id");
    expect(postBodies[0]).not.toHaveProperty("sube_id");

    // TEST A: same modal → Genel shows reconciled Maaş (uyumluluk)
    await kayitModal.getByTestId("kayit-surec-subtab-genel").click();
    await expect(maasUyumlulukValue(kayitModal)).toHaveText("42.000,00");

    await kayitModal.getByTestId("kayit-surec-subtab-mali").click();
    await expect(kayitModal.getByTestId("kayit-surec-ucret-panel")).toBeVisible();

    await kayitModal.getByTestId("personel-ucret-yeni-donem").click();
    const overlapModal = await fillUcretModal(page, "43000", "2026-01-10");
    await expect(overlapModal.getByTestId("personel-ucret-form-hata")).toContainText(
      "Bu personel için seçilen tarih aralığında başka bir ücret kaydı bulunmaktadır."
    );
    expect(createPostCount).toBe(2);
    await overlapModal.getByRole("button", { name: "Vazgeç" }).click();
    await expect(overlapModal).toHaveCount(0);

    const iptalButton = kayitModal.locator('[data-testid^="personel-ucret-iptal-"]').first();
    await expect(iptalButton).toBeVisible();
    await iptalButton.click();
    const cancelDialog = page.getByRole("dialog", { name: /Ücret Kaydını İptal Et/i });
    await expect(cancelDialog).toBeVisible();
    expect(cancelPosts).toEqual([]);
    await page.getByTestId("personel-ucret-action-dialog-cancel").click();
    await expect(cancelDialog).toHaveCount(0);
    expect(cancelPosts).toEqual([]);

    await iptalButton.click();
    await expect(cancelDialog).toBeVisible();
    await page.getByTestId("personel-ucret-action-dialog-confirm").click();
    await expect(cancelDialog).toHaveCount(0);
    expect(cancelPosts).toHaveLength(1);

    // TEST B: cancel reconcile → Genel compatibility salary from server/mock SoT
    await expect(kayitModal.getByTestId("personel-ucret-guncel")).toBeVisible();
    await kayitModal.getByTestId("kayit-surec-subtab-genel").click();
    await expect(maasUyumlulukValue(kayitModal)).toHaveText("-");

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await openPersonelKart(page, /Ucret Aday.*kişisinin kartını aç/i);
    await expect(page.getByTestId("personel-ucret-gecmisi-card")).toBeVisible();
    await expect(page.getByTestId("personel-ucret-list").locator("li")).toHaveCount(1);
    expect(pageErrors).toEqual([]);
  });

  test("Surec wage mutate inflight iken personel picker kilitli kalir", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "demo123" });
    await createMaassizPersonel(page);

    const kayitModal = await openSurecMaliForPersonel(page, "Ucret", /Ucret Aday/i);
    const personelCombo = kayitModal.getByRole("combobox", { name: "Personel" });
    await expect(personelCombo).toBeEnabled();

    await page.route(/\/api\/personeller\/\d+\/ucretler$/, async (route) => {
      if (route.request().method() === "POST") {
        await new Promise((resolve) => setTimeout(resolve, 2000));
      }
      await route.fallback();
    });

    await kayitModal.getByTestId("personel-ucret-yeni-donem").click();
    const modal = page.locator(".modal-container").filter({
      has: page.getByRole("heading", { name: /Yeni Ücret Dönemi Başlat/i })
    }).last();
    await modal.locator('[name="ucret-tutar"]').fill("41000");
    await modal.locator('[name="ucret-baslangic"]').fill("2026-02-01");
    const savePromise = modal.getByTestId("personel-ucret-form-kaydet").click();
    await expect(personelCombo).toBeDisabled({ timeout: 3000 });
    await expect(kayitModal.getByTestId("kayit-surec-ucret-tipi-kaydet")).toBeDisabled();
    await expect(kayitModal.locator('[name="kayit-surec-ucret-tipi"]')).toBeDisabled();
    await savePromise;
    await expect(personelCombo).toBeEnabled({ timeout: 5000 });
    await expect(personelCombo).toContainText(/Ucret Aday/i);
  });

  test("TEST C — ucret tipi PUT delay: salary actions + picker locked", async ({ page }) => {
    let ucretTipiPutCount = 0;
    let salaryCreateCount = 0;
    let salaryCancelCount = 0;

    page.on("request", (request) => {
      const url = request.url();
      const method = request.method();
      if (method === "PUT" && /\/api\/personeller\/\d+$/.test(new URL(url).pathname)) {
        try {
          const body = request.postDataJSON() as Record<string, unknown>;
          if ("ucret_tipi_id" in body) {
            ucretTipiPutCount += 1;
          }
        } catch {
          /* ignore */
        }
      }
      if (method === "POST" && /\/ucretler$/.test(new URL(url).pathname)) {
        salaryCreateCount += 1;
      }
      if (method === "POST" && url.includes("/iptal")) {
        salaryCancelCount += 1;
      }
    });

    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "demo123" });
    await createMaassizPersonel(page);

    const kayitModal = await openSurecMaliForPersonel(page, "Ucret", /Ucret Aday/i);
    const personelCombo = kayitModal.getByRole("combobox", { name: "Personel" });

    await page.route(/\/api\/personeller\/\d+$/, async (route) => {
      if (route.request().method() === "PUT") {
        await new Promise((resolve) => setTimeout(resolve, 2000));
      }
      await route.fallback();
    });

    await kayitModal.locator('[name="kayit-surec-ucret-tipi"]').selectOption({ index: 2 });
    const putPromise = kayitModal.getByTestId("kayit-surec-ucret-tipi-kaydet").click();
    await expect(personelCombo).toBeDisabled({ timeout: 3000 });
    await expect(kayitModal.getByTestId("personel-ucret-yeni-donem")).toBeDisabled();
    await expect(kayitModal.locator('[name="kayit-surec-ucret-tipi"]')).toBeDisabled();
    expect(salaryCreateCount).toBe(0);
    expect(salaryCancelCount).toBe(0);
    await putPromise;
    await expect(personelCombo).toBeEnabled({ timeout: 5000 });
    expect(ucretTipiPutCount).toBe(1);
    expect(salaryCreateCount).toBe(0);
    expect(salaryCancelCount).toBe(0);
  });

  test("TEST D/E/F — salary create/cancel delay locks picker + tab unmount safe", async ({ page }) => {
    let createSalaryCount = 0;
    let cancelSalaryCount = 0;

    page.on("request", (request) => {
      const url = request.url();
      if (request.method() === "POST" && /\/ucretler$/.test(new URL(url).pathname)) {
        createSalaryCount += 1;
      }
      if (request.method() === "POST" && url.includes("/iptal")) {
        cancelSalaryCount += 1;
      }
    });

    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "demo123" });
    await createMaassizPersonel(page);

    const kayitModal = await openSurecMaliForPersonel(page, "Ucret", /Ucret Aday/i);
    const personelCombo = kayitModal.getByRole("combobox", { name: "Personel" });

    await page.route(/\/api\/personeller\/\d+\/ucretler$/, async (route) => {
      if (route.request().method() === "POST") {
        await new Promise((resolve) => setTimeout(resolve, 2000));
      }
      await route.fallback();
    });

    // TEST D + F: create delay, switch Genel while in-flight — picker stays locked
    await kayitModal.getByTestId("personel-ucret-yeni-donem").click();
    const createModal = page.locator(".modal-container").filter({
      has: page.getByRole("heading", { name: /Yeni Ücret Dönemi Başlat/i })
    }).last();
    await createModal.locator('[name="ucret-tutar"]').fill("41000");
    await createModal.locator('[name="ucret-baslangic"]').fill("2026-03-01");
    await createModal.getByTestId("personel-ucret-form-kaydet").click();
    await expect(personelCombo).toBeDisabled({ timeout: 3000 });
    await expect(kayitModal.locator('[name="kayit-surec-ucret-tipi"]')).toBeDisabled();
    await expect(kayitModal.getByTestId("kayit-surec-ucret-tipi-kaydet")).toBeDisabled();

    // TEST F: sub-tab switch guarded while wage mutation in-flight
    await expect(kayitModal.getByTestId("kayit-surec-subtab-genel")).toBeDisabled();
    await kayitModal.getByTestId("kayit-surec-subtab-genel").click({ force: true }).catch(() => undefined);
    await expect(kayitModal.getByTestId("kayit-surec-ucret-panel")).toBeVisible();
    await expect(personelCombo).toBeDisabled();
    await personelCombo.click({ force: true }).catch(() => undefined);
    await expect(personelCombo).toContainText(/Ucret Aday/i);
    expect(createSalaryCount).toBe(1);

    await expect(personelCombo).toBeEnabled({ timeout: 8000 });
    expect(createSalaryCount).toBe(1);

    // After settle, Genel is reachable and shows reconciled salary
    await kayitModal.getByTestId("kayit-surec-subtab-genel").click();
    await expect(maasUyumlulukValue(kayitModal)).toHaveText("41.000,00");

    // TEST E: cancel delay
    await kayitModal.getByTestId("kayit-surec-subtab-mali").click();
    await expect(kayitModal.getByTestId("personel-ucret-gecmisi-card")).toBeVisible();

    await page.unroute(/\/api\/personeller\/\d+\/ucretler$/);
    await page.route(/\/api\/personeller\/\d+\/ucretler\/\d+\/iptal$/, async (route) => {
      if (route.request().method() === "POST") {
        await new Promise((resolve) => setTimeout(resolve, 2000));
      }
      await route.fallback();
    });

    const iptalButton = kayitModal.locator('[data-testid^="personel-ucret-iptal-"]').first();
    await iptalButton.click();
    await page.getByTestId("personel-ucret-action-dialog-confirm").click();
    await expect(personelCombo).toBeDisabled({ timeout: 3000 });
    await expect(kayitModal.locator('[name="kayit-surec-ucret-tipi"]')).toBeDisabled();
    await expect(kayitModal.getByTestId("kayit-surec-ucret-tipi-kaydet")).toBeDisabled();
    await expect(kayitModal.getByTestId("kayit-surec-subtab-genel")).toBeDisabled();
    await expect(personelCombo).toBeEnabled({ timeout: 8000 });
    expect(cancelSalaryCount).toBe(1);
  });

  test("BIRIM_AMIRI Surec Mali wage panel ve fetch yok", async ({ page }) => {
    const ucretRequests: string[] = [];
    page.on("request", (request) => {
      if (/\/api\/personeller\/\d+\/ucretler/.test(request.url())) {
        ucretRequests.push(request.url());
      }
    });

    await loginAsMockRole(page, "BIRIM_AMIRI");
    const kayitMenu = page.getByTestId("menu-kayit-surec");
    if (await kayitMenu.isDisabled()) {
      expect(ucretRequests).toEqual([]);
      return;
    }

    const kayitModal = await openKayitModal(page);
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();
    await kayitModal.getByRole("tab", { name: "Mali İşlemler" }).click();
    await expect(kayitModal.getByTestId("kayit-surec-ucret-panel")).toHaveCount(0);
    await expect(kayitModal.getByTestId("personel-ucret-gecmisi-card")).toHaveCount(0);
    expect(ucretRequests).toEqual([]);
  });

  test("GENEL_YONETICI mevzuat paneline erişir ve çakışmayı engeller", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/yonetim-paneli?tab=mevzuat");
    await expect(page.locator(".modal-header h2").first()).toContainText("MEVZUAT PARAMETRELERİ");
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
