import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { openHeaderSettingsMenu } from "./helpers/header-nav";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "genel_yonetici", password: "demo123" },
  bolumYonetici: { username: "bolum_yonetici", password: "demo123" },
  muhasebe: { username: "muhasebe", password: "demo123" },
  birimAmiri: { username: "birim_amiri", password: "demo123" }
};

test.describe("Rol bazli smoke", () => {
  test("Genel yonetici ana modulleri ayar menusunde gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);
    await openHeaderSettingsMenu(page);
    await expect(page.getByTestId("menu-personeller")).toBeVisible();
    await expect(page.getByTestId("menu-surecler")).toBeVisible();
    await expect(page.getByTestId("menu-puantaj")).toBeVisible();
    await expect(page.getByTestId("menu-raporlar")).toBeVisible();
  });

  test("Bolum yoneticisi yetkili modulleri ayar menusunde gorur", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, users.bolumYonetici);
    await openHeaderSettingsMenu(page);
    await expect(page.getByTestId("menu-personeller")).toBeVisible();
    await expect(page.getByTestId("menu-surecler")).toBeVisible();
    await expect(page.getByTestId("menu-raporlar")).toBeVisible();
  });

  test("Muhasebe finans ve rapor modullerine erisebilir", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);
    await openHeaderSettingsMenu(page);
    await expect(page.getByTestId("menu-raporlar")).toBeVisible();
    await expect(page.getByTestId("menu-finans")).toBeVisible();
  });

  test("Birim amiri finans ve haftalik kapanis menusunu gormez", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, users.birimAmiri);
    await openHeaderSettingsMenu(page);
    await expect(page.getByTestId("menu-raporlar")).toBeVisible();
    await expect(page.getByTestId("menu-finans")).toHaveCount(0);
    await expect(page.getByTestId("menu-haftalik-kapanis")).toHaveCount(0);
  });
});
