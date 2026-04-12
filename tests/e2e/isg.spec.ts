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
});
