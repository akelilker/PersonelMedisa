import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("ISG makine listesi", () => {
  test("Raporlar icinden ISG listesine gidilir ve genel yonetici tum makineleri gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.goto("/raporlar");
    await expect(page.getByRole("link", { name: /SG Makine Listesi/i })).toBeVisible();
    await page.getByRole("link", { name: /SG Makine Listesi/i }).click();

    await expect(page).toHaveURL(/\/isg$/);
    await expect(page.getByRole("heading", { name: /Makine Envanteri/i })).toBeVisible();
    await expect(page.getByTestId("isg-machine-1101")).toBeVisible();
    await expect(page.getByTestId("isg-machine-1102")).toBeVisible();
    await expect(page.getByTestId("isg-machine-1103")).toBeVisible();
  });

  test("Bolum yoneticisi kendi sube kapsamindaki makineleri gorur", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, { username: "bolum_yonetici", password: "demo123" });

    await page.goto("/isg");
    await expect(page.getByRole("heading", { name: /Makine Envanteri/i })).toBeVisible();
    await expect(page.getByTestId("isg-machine-1102")).toBeVisible();
    await expect(page.getByTestId("isg-machine-1101")).toHaveCount(0);
    await expect(page.getByTestId("isg-machine-1103")).toHaveCount(0);
  });

  test("Birim amiri listeyi gorebilir ama muhasebe goremez", async ({ browser }) => {
    const birimPage = await browser.newPage();
    await mockApi(birimPage, "BIRIM_AMIRI");
    await login(birimPage, { username: "birim_amiri", password: "demo123" });
    await birimPage.goto("/isg");
    await expect(birimPage.getByRole("heading", { name: /Makine Envanteri/i })).toBeVisible();
    await expect(birimPage.getByTestId("isg-machine-1101")).toBeVisible();
    await expect(birimPage.getByTestId("isg-machine-1102")).toHaveCount(0);
    await birimPage.close();

    const muhasebePage = await browser.newPage();
    await mockApi(muhasebePage, "MUHASEBE");
    await login(muhasebePage, { username: "muhasebe", password: "demo123" });
    await muhasebePage.goto("/isg");
    await expect(muhasebePage).toHaveURL(/\/yetkisiz$/);
    await muhasebePage.close();
  });

  test("Listeden makine detayina gidilir ve bakim gecmisi en yeni ustte gorunur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.goto("/isg");
    await page.getByTestId("isg-machine-1102").click();

    await expect(page).toHaveURL(/\/isg\/1102$/);
    await expect(page.getByRole("heading", { name: /Makine Detayi/i })).toBeVisible();
    await expect(page.getByTestId("isg-machine-detail")).toContainText("Forklift 02");
    await expect(page.getByTestId("isg-machine-detail-status")).toContainText(/Gecikmis/i);

    const historyRows = page.locator("[data-testid^='isg-maintenance-row-']");
    await expect(historyRows).toHaveCount(2);
    await expect(historyRows.nth(0)).toContainText("10.02.2026");
    await expect(historyRows.nth(1)).toContainText("05.01.2026");
  });

  test("Invalid tarihli bakim kaydi UI'i bozmaz ve eksik veri olarak gorunur", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, { username: "birim_amiri", password: "demo123" });

    await page.goto("/isg/1103");
    await expect(page.getByRole("heading", { name: /Makine Detayi/i })).toBeVisible();
    await expect(page.getByTestId("isg-machine-detail")).toContainText("Pres Hatti");
    await expect(page.getByTestId("isg-machine-detail-status")).toContainText(/Eksik Veri/i);
    await expect(page.getByTestId("isg-maintenance-row-2105")).toContainText("-");
  });

  test("Bolum yoneticisi scope disi makineyi dogrudan URL ile goremez", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, { username: "bolum_yonetici", password: "demo123" });

    await page.goto("/isg/1101");
    await expect(page.getByText(/Makine bulunamadi/i)).toBeVisible();
    await expect(page.getByTestId("isg-machine-detail")).toHaveCount(0);
  });
});
