import { expect, test, type Page, type Response } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

async function openOrganizasyonForAyse(page: Page) {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container").last();
  await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  await kayitModal.getByTestId("kayit-tab-surec").click();
  await kayitModal.getByRole("combobox", { name: "Personel" }).click();
  await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
  await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();
  await kayitModal.getByRole("tab", { name: /Görev \/ Organizasyon|Pozisyon/i }).click();
  await expect(kayitModal.locator("form.surec-position-form")).toBeVisible();
  return kayitModal;
}

function isOrgPost(response: Response) {
  return (
    response.url().includes("/api/personeller/1/organizasyon-degisikligi") &&
    response.request().method() === "POST"
  );
}

function isPersonelPut(response: Response) {
  const url = response.url();
  return (
    /\/api\/personeller\/1$/.test(url.replace(/\?.*$/, "")) &&
    response.request().method() === "PUT"
  );
}

function isPozisyonSurecPost(response: Response) {
  if (!response.url().includes("/api/surecler") || response.request().method() !== "POST") {
    return false;
  }
  return response.request().postDataJSON()?.surec_turu === "POZISYON_DEGISTI";
}

async function assertTimelinePozisyon(page: Page) {
  await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
  const timeline = page
    .locator("#personel-kart-panel-surec-gecmisi")
    .locator("[data-testid='personel-surec-timeline']");
  await expect(timeline).toContainText(/Pozisyon Değişti|Pozisyon Degisti/i);
  await expect(timeline).not.toContainText("Mock otomatik org gecmis kaydi");
}

async function openPersonelCard(page: Page) {
  await page.getByTestId("menu-personel-karti").click();
  await expect(page).toHaveURL(/\/personeller$/);
  await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
  await expect(page).toHaveURL(/\/personeller\/1$/);
}

async function fillOrgReason(kayitModal: ReturnType<Page["locator"]>, date: string, note: string) {
  await kayitModal.getByLabel("Değişiklik Tarihi").fill(date);
  await kayitModal.getByLabel("Açıklama / Değişiklik Nedeni").fill(note);
}

test.describe("Kayit Surec Görev / Organizasyon", () => {
  test("yonetici gorev degisikligi canonical org endpoint ve POZISYON_DEGISTI tetikler", async ({
    page
  }) => {
    const pageErrors: string[] = [];
    const console500: string[] = [];
    page.on("pageerror", (error) => pageErrors.push(error.message));
    page.on("console", (message) => {
      if (message.text().includes("500")) {
        console500.push(message.text());
      }
    });

    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openOrganizasyonForAyse(page);

    await kayitModal.getByRole("combobox", { name: "Görev / Unvan" }).click();
    await kayitModal.locator("#pozisyon-gorev-panel").getByRole("option", { name: "Üretim Müdürü" }).click();
    await fillOrgReason(kayitModal, "2026-08-01", "Gorev unvan degisikligi kaydi");

    const pozisyonKaydet = kayitModal.getByTestId("kayit-modal-footer-primary");
    await expect(pozisyonKaydet).toBeEnabled({ timeout: 5000 });
    await expect(pozisyonKaydet).toHaveAttribute("form", "kayit-surec-pozisyon-form");

    const orgPromise = page.waitForResponse(isOrgPost);
    const postSurecPromise = page.waitForResponse(isPozisyonSurecPost);
    const [orgResp, postResp] = await Promise.all([
      orgPromise,
      postSurecPromise,
      pozisyonKaydet.click()
    ]);

    expect(orgResp.ok()).toBe(true);
    expect(postResp.ok()).toBe(true);

    const orgBody = orgResp.request().postDataJSON() as Record<string, unknown>;
    expect(orgBody.targets).toEqual({ gorev_id: 2 });
    expect(String(orgBody.gerekce)).toMatch(/Gorev unvan/i);

    const postBody = postResp.request().postDataJSON() as Record<string, unknown>;
    expect(postBody.surec_turu).toBe("POZISYON_DEGISTI");
    expect(postBody.personel_id).toBe(1);

    await expect(kayitModal.getByRole("combobox", { name: "Görev / Unvan" })).toContainText(
      "Üretim Müdürü"
    );

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await expect(kayitModal).toHaveCount(0);

    await openPersonelCard(page);
    await expect(page.locator(".personel-dosya-hero")).toContainText(
      /Üretim Müdürü|Uretim Müdürü|Uretim Muduru/i
    );
    await assertTimelinePozisyon(page);
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    expect(pageErrors).toEqual([]);
    expect(console500).toEqual([]);
  });

  test("departman degisikligi canonical org endpoint kullanir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openOrganizasyonForAyse(page);

    await kayitModal.getByRole("combobox", { name: "Departman" }).click();
    await kayitModal.locator("#pozisyon-departman-panel").getByRole("option", { name: "Finans" }).click();
    await fillOrgReason(kayitModal, "2026-08-03", "Departman degisikligi kaydi");

    const orgPromise = page.waitForResponse(isOrgPost);
    const postPromise = page.waitForResponse(isPozisyonSurecPost);
    const [orgResp] = await Promise.all([
      orgPromise,
      postPromise,
      kayitModal.getByTestId("kayit-modal-footer-primary").click()
    ]);

    const orgBody = orgResp.request().postDataJSON() as {
      targets: Record<string, unknown>;
    };
    expect(orgBody.targets.departman_id).toBe(2);
    await assertTimelinePozisyon(page);
  });

  test("bagli amir degisikligi generic PUT kullanir, org endpoint cagirmaz", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openOrganizasyonForAyse(page);

    await kayitModal.getByRole("combobox", { name: "Bağlı Amir" }).click();
    const amirPanel = kayitModal.locator("#pozisyon-bagli-amir-panel");
    await amirPanel.getByRole("option").nth(1).click();

    const putPromise = page.waitForResponse(isPersonelPut);
    const orgHits: string[] = [];
    page.on("request", (req) => {
      if (req.url().includes("organizasyon-degisikligi")) {
        orgHits.push(req.url());
      }
    });
    await Promise.all([putPromise, kayitModal.getByTestId("kayit-modal-footer-primary").click()]);
    expect(orgHits).toEqual([]);
  });

  test("no-op kaydet mutation uretmez", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openOrganizasyonForAyse(page);

    await kayitModal.getByLabel("Değişiklik Tarihi").fill("2026-08-07");
    await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeDisabled();
  });

  test("kalici sube transferi GENEL_YONETICI icin branch endpoint cagirir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openOrganizasyonForAyse(page);

    await expect(kayitModal.getByTestId("kayit-surec-kalici-sube-panel")).toBeVisible();
    await kayitModal.getByLabel("Yeni Şube").selectOption({ index: 1 });
    await kayitModal.getByLabel("Gerekçe").fill("Yeni sube acilisi kapsaminda transfer");

    const transferPromise = page.waitForResponse(
      (response) =>
        response.url().includes("/kalici-sube-degisikligi") && response.request().method() === "POST"
    );
    await Promise.all([
      transferPromise,
      kayitModal.getByTestId("kayit-surec-sube-transfer-submit").click()
    ]);
    await expect(kayitModal.getByText(/Kalıcı şube değişikliği uygulandı/i)).toBeVisible();
  });
});
