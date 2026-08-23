import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const DIRECT_MODULE_ROUTES = [
  { path: "/puantaj", dialog: "Günlük Puantaj" },
  { path: "/bildirimler", dialog: "Günlük Kayıt Merkezi" },
  { path: "/haftalik-kapanis", dialog: "Haftalık Kapanış / Revizyon" },
  { path: "/haftalik-kapanis/revizyonlar", pageTestId: "revizyon-merkezi-page" },
  { path: "/personeller/belge-takip", dialog: "Belge Takip" },
  { path: "/finans", dialog: "Finans" }
] as const;

test.describe("Global module navigation absence", () => {
  test("home has three canonical cards and no global modules surfaces", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await expect(page).toHaveURL(/\/$/);

    await expect(page.getByTestId("menu-kayit-surec")).toBeVisible();
    await expect(page.getByTestId("menu-personel-karti")).toBeVisible();
    await expect(page.getByTestId("menu-raporlar")).toBeVisible();
    await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);

    await expect(page.getByTestId("menu-moduller")).toHaveCount(0);
    await expect(page.getByTestId("home-modules-nav")).toHaveCount(0);
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
  });

  test("module overlay routes have no global modules navigation", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    for (const route of ["/personeller", "/bildirimler", "/yonetim-paneli?tab=kullanicilar"]) {
      await page.goto(route);
      await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
      await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
      await expect(page.getByTestId("menu-moduller")).toHaveCount(0);
    }
  });

  test("secondary module routes remain reachable without global picker", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    for (const route of DIRECT_MODULE_ROUTES) {
      await page.goto(route.path);
      await expect(page).toHaveURL(new RegExp(`${route.path.replace(/\//g, "\\/")}$`));
      if ("dialog" in route) {
        await expect(page.getByRole("dialog", { name: route.dialog })).toBeVisible();
      } else {
        await expect(page.getByTestId(route.pageTestId)).toBeVisible();
      }
    }
  });

  test("BIRIM_AMIRI has no global modules surfaces; PERSONEL home has no main menu", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, MOCK_ROLE_LOGIN.BIRIM_AMIRI);
    await expect(page.getByTestId("menu-moduller")).toHaveCount(0);
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);

    await mockApi(page, "PERSONEL");
    await login(page, MOCK_ROLE_LOGIN.PERSONEL);
    await expect(page.getByTestId("menu-moduller")).toHaveCount(0);
    await expect(page.locator("#main-menu")).toHaveCount(0);
  });
});
