import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { mockApi, resetMaasBordroPageState } from "./helpers/mock-api";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

const PANEL_AY = "2026-03";

async function submitBordroFilters(page: import("@playwright/test").Page) {
  await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
  await page.getByLabel("Şube").selectOption("1");
  await page.getByTestId("bordro-hazirlik-submit").click();
  await expect(page.getByTestId("bordro-hazirlik-merkezi")).toBeVisible();
}

test.describe("S83 Business Data Readiness", () => {
  test.setTimeout(120_000);

  test.beforeEach(async ({ page }) => {
    resetMaasBordroPageState(page);
  });

  test("MUHASEBE: Veri Hazırlık, blockers, net maaş, şablon, dry-run, candidate disabled", async ({ page }) => {
    await openRaporlarPanel(page, "MUHASEBE", "bordro-hazirlik");
    await submitBordroFilters(page);

    await expect(page.getByTestId("bordro-hazirlik-tab-veri-hazirlik")).toBeVisible();
    await page.getByTestId("bordro-hazirlik-tab-veri-hazirlik").click();
    await expect(page.getByTestId("bordro-veri-hazirlik")).toBeVisible();
    await expect(page.getByTestId("bordro-readiness-csv-indir")).toBeVisible();
    await expect(page.getByTestId("bordro-readiness-domain-s81_final_onay")).toBeVisible();
    await expect(page.getByTestId("bordro-readiness-domain-net_maas")).toBeVisible();
    await expect(page.getByTestId("bordro-readiness-blockers-s81_final_onay")).toContainText(
      "S81_GENEL_YONETICI_FINAL_ONAY_EKSIK"
    );
    await expect(page.getByTestId("bordro-readiness-eksik-kodlar-sirket_calisma_politikasi")).toContainText(
      "NORMAL_AY_GUN_SAYISI"
    );
    await expect(page.getByTestId("bordro-candidate-gate-aktif")).toHaveText("Kapalı");
    await expect(page.getByTestId("bordro-candidate-gate-nedenleri")).toBeVisible();
    await expect(page.getByTestId("bordro-net-maas-row-0")).toBeVisible();
    await expect(page.getByTestId("bordro-mevzuat-link")).toBeVisible();

    await page.getByTestId("bordro-hazirlik-tab-devir").click();
    await expect(page.getByTestId("bordro-devir-sablon-indir")).toBeVisible();
    await page.getByTestId("bordro-devir-import-csv").fill("P-001;12345.67;987.65\nUNKNOWN;1;1");
    await page.getByTestId("bordro-devir-import-dry-run").click();
    await expect(page.getByTestId("bordro-devir-import-summary")).toBeVisible();
    await expect(page.getByTestId("bordro-devir-import-counts")).toContainText("Eşleşmeyen");

    await page.getByTestId("bordro-hazirlik-tab-hesaplama").click();
    await expect(page.getByTestId("bordro-candidate-uret")).toBeDisabled();
    await expect(page.getByTestId("bordro-candidate-disabled-nedenleri")).toBeVisible();
  });

  test("MUHASEBE: ön izleme finans tutarları görünür", async ({ page }) => {
    await openRaporlarPanel(page, "MUHASEBE", "bordro-hazirlik");
    await submitBordroFilters(page);
    await page.getByTestId("bordro-hazirlik-tab-on-izleme").click();
    await expect(page.getByTestId("bordro-on-izleme")).toBeVisible();
    await expect(page.getByTestId("bordro-on-izleme-toplam-net")).toBeVisible();
    await expect(page.getByTestId("bordro-on-izleme-toplam-brut")).toBeVisible();
    await expect(page.getByTestId("bordro-on-izleme-finance-masked")).toHaveCount(0);
  });

  test("GENEL_YONETICI: readiness + politika karar özeti + S81 deep-link", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await openRaporlarPanel(page, "MUHASEBE", "bordro-hazirlik");
    await submitBordroFilters(page);
    await page.getByTestId("bordro-hazirlik-tab-politika").click();
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
    await page.getByTestId("bordro-politika-taslak-olustur").click();
    await page.getByTestId("bordro-politika-submit-1").click();

    await mockApi(page, "GENEL_YONETICI");
    await openRaporlarPanel(page, "GENEL_YONETICI", "bordro-hazirlik");
    await submitBordroFilters(page);

    await page.getByTestId("bordro-hazirlik-tab-veri-hazirlik").click();
    await expect(page.getByTestId("bordro-readiness-domain-s81_final_onay")).toBeVisible();
    await expect(page.getByTestId("bordro-readiness-link-s81_final_onay")).toHaveAttribute("href", /bildirimler/);

    await page.getByTestId("bordro-hazirlik-tab-politika").click();
    await expect(page.getByTestId("bordro-politika-karar-ozeti")).toBeVisible();
    await expect(page.getByTestId("bordro-politika-approve-1")).toBeVisible();
  });

  test("BIRIM_AMIRI: bordro yetkisiz", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await expect(page).toHaveURL(/\/yetkisiz/);
  });

  test("BOLUM_YONETICISI: bordro yetkisiz", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI");
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await expect(page).toHaveURL(/\/yetkisiz/);
  });

  test("PATRON: bordro yetkisiz", async ({ page }) => {
    await loginAsMockRole(page, "PATRON");
    await page.goto("/raporlar?panel=bordro-hazirlik");
    await expect(page).toHaveURL(/\/yetkisiz/);
  });
});
