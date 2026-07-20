import { expect, test } from "@playwright/test";
import { login, loginAsMockRole, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi, resetMaasBordroPageState } from "./helpers/mock-api";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

const PANEL_AY = "2026-03";

async function applyBordroFilters(page: import("@playwright/test").Page) {
  await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
  await page.getByLabel("Şube").selectOption("1");
  await page.getByTestId("bordro-hazirlik-submit").click();
}

async function submitBordroFilters(page: import("@playwright/test").Page) {
  await applyBordroFilters(page);
  await expect(page.getByTestId("bordro-hazirlik-merkezi")).toBeVisible();
}

async function openPolitikaTab(page: import("@playwright/test").Page) {
  await page.getByTestId("bordro-hazirlik-tab-politika").click();
  await expect(page.getByTestId("bordro-politika-form")).toBeVisible({ timeout: 15_000 });
}

async function fillPolicyDraft(page: import("@playwright/test").Page) {
  await page.getByLabel("Normal Ay Gün Sayısı").fill("30");
  await page.getByLabel("Günlük Çalışma Saati").fill("7.5");
  await page.getByLabel("Aylık Normal Çalışma Saati").fill("225");
  await page.getByLabel("Haftalık İş Günü Sayısı").fill("6");
  await page.getByLabel("Hafta Tatili Hesap Modu").fill("GUNLUK_ILAVE");
  await page.getByLabel("Hafta Tatili Çarpanı").fill("1");
  await page.getByLabel("Fazla Mesai Çarpanı").fill("1.5");
  await page.getByLabel("Fazla Sürelerle Çalışma Çarpanı").fill("1.25");
  await page.getByLabel("UBGT Çarpanı").fill("2");
  await page.getByLabel("UBGT Hesap Modu").fill("GUNLUK_ILAVE");
}

test.describe("S82 Bordro Hazirlik Merkezi", () => {
  test.setTimeout(120_000);

  test.beforeEach(async ({ page }) => {
    resetMaasBordroPageState(page);
  });

  test("MUHASEBE: preflight blocker, politika, devir, candidate, kontrol; kesinlestirme yok", async ({ page }) => {
    page.on("dialog", (dialog) => void dialog.accept());
    await openRaporlarPanel(page, "MUHASEBE", "bordro-hazirlik");
    await submitBordroFilters(page);

    await expect(page.getByTestId("bordro-hazirlik-merkezi")).toBeVisible();
    await page.getByTestId("bordro-hazirlik-tab-preflight").click();
    await expect(page.getByTestId("bordro-hazirlik-issue-BUSINESS_POLICY_REQUIRED")).toBeVisible();
    await page.getByTestId("bordro-hazirlik-issue-link-BUSINESS_POLICY_REQUIRED").click();
    await expect(page).toHaveURL(/panel=bordro-hazirlik/);

    await openPolitikaTab(page);
    await fillPolicyDraft(page);
    await page.getByTestId("bordro-politika-taslak-olustur").click();
    await expect(page.getByTestId("bordro-politika-row-1")).toBeVisible();
    await page.getByTestId("bordro-politika-submit-1").scrollIntoViewIfNeeded();
    await page.getByTestId("bordro-politika-submit-1").click({ force: true });

    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await submitBordroFilters(page);
    await openPolitikaTab(page);
    await page.getByTestId("bordro-politika-approve-1").click();

    await mockApi(page, "MUHASEBE");
    await login(page, MOCK_ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await submitBordroFilters(page);

    await page.getByTestId("bordro-hazirlik-tab-devir").click();
    await page.getByTestId("bordro-devir-import-csv").fill("P-001;12345.67;987.65");
    await page.getByTestId("bordro-devir-import-commit").click();
    await expect(page.getByTestId("bordro-devir-import-summary")).toBeVisible();

    await page.getByTestId("bordro-hazirlik-tab-hesaplama").click();
    await expect(page.getByTestId("bordro-candidate-uret")).toBeEnabled({ timeout: 15_000 });
    await page.getByTestId("bordro-candidate-uret").click();
    await expect(page.getByTestId("bordro-hazirlik-action-error")).toHaveCount(0);

    await page.getByTestId("bordro-hazirlik-tab-on-izleme").click();
    await expect(page.getByTestId("bordro-on-izleme")).toBeVisible();
    await expect(page.getByTestId("bordro-kesinlestir")).toHaveCount(0);
    await page.getByTestId("bordro-kontrol-gonder").click();
  });

  test("GENEL_YONETICI: politika onaylar, ozet gorur, geri gonderir ve kesinlestirir", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, MOCK_ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await submitBordroFilters(page);
    await openPolitikaTab(page);
    await fillPolicyDraft(page);
    await page.getByTestId("bordro-politika-taslak-olustur").click();
    await page.getByTestId("bordro-politika-submit-1").click();

    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await submitBordroFilters(page);
    await openPolitikaTab(page);
    await page.getByTestId("bordro-politika-approve-1").click();

    await mockApi(page, "MUHASEBE");
    await login(page, MOCK_ROLE_LOGIN.MUHASEBE);
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await submitBordroFilters(page);

    await page.getByTestId("bordro-hazirlik-tab-devir").click();
    await page.getByTestId("bordro-devir-import-csv").fill("P-001;12345.67;987.65");
    await page.getByTestId("bordro-devir-import-commit").click();

    await page.getByTestId("bordro-hazirlik-tab-hesaplama").click();
    await expect(page.getByTestId("bordro-candidate-uret")).toBeEnabled({ timeout: 15_000 });
    await page.getByTestId("bordro-candidate-uret").click();

    await page.getByTestId("bordro-hazirlik-tab-on-izleme").click();
    await page.getByTestId("bordro-kontrol-gonder").click();

    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await submitBordroFilters(page);

    await page.getByTestId("bordro-hazirlik-tab-on-izleme").click();
    await expect(page.getByTestId("bordro-on-izleme-ozet")).toBeVisible();
    await page.getByTestId("bordro-geri-gonder").click();
    await page.getByTestId("bordro-kesinlestir").click();
  });

  test("BIRIM_AMIRI: bordro route erisemez", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await expect(page).toHaveURL(/\/yetkisiz/);
  });

  test("BOLUM_YONETICISI: bordro route erisemez", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI");
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await expect(page).toHaveURL(/\/yetkisiz/);
  });

  test("PATRON: bordro route erisemez", async ({ page }) => {
    await loginAsMockRole(page, "PATRON");
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await expect(page).toHaveURL(/\/yetkisiz/);
  });
});
