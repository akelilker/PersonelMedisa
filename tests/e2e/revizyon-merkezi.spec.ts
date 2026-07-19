import { expect, test } from "@playwright/test";
import { login, mockApi } from "./helpers/mock-api";

test.describe("S80 Revizyon Merkezi rol matrisi", () => {
  test("GENEL_YONETICI Revizyon Merkezi ve onay sekmesini gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "admin", password: "secret" });

    await page.goto("/haftalik-kapanis");
    await expect(page.getByTestId("haftalik-kapanis-page")).toBeVisible();
    await page.getByTestId("hk-revizyon-merkezi-link").click();
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-tab-onay")).toBeVisible();
    await expect(page.getByTestId("revizyon-yeni-talep")).toBeVisible();
  });

  test("BIRIM_AMIRI olusturur ama onay sekmesini gormez", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, { username: "birim", password: "secret" });

    await page.goto("/haftalik-kapanis/revizyonlar");
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-tab-onay")).toHaveCount(0);
    await expect(page.getByTestId("revizyon-yeni-talep")).toBeVisible();
  });

  test("MUHASEBE Revizyon Merkezi gorur ama onay sekmesi yok", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "secret" });

    await page.goto("/haftalik-kapanis/revizyonlar");
    await expect(page.getByTestId("revizyon-merkezi-page")).toBeVisible();
    await expect(page.getByTestId("revizyon-tab-onay")).toHaveCount(0);
  });
});
