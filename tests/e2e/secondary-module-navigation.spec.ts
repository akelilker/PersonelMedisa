import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const GY_LINKS = [
  { id: "puantaj", label: "Puantaj", path: /\/puantaj$/ },
  { id: "gunluk-kayit", label: "Günlük Kayıt", path: /\/bildirimler$/ },
  { id: "haftalik-kapanis", label: "Haftalık Kapanış", path: /\/haftalik-kapanis$/ },
  { id: "revizyon-merkezi", label: "Revizyon Merkezi", path: /\/haftalik-kapanis\/revizyonlar$/ },
  { id: "belge-takip", label: "Belge Takip", path: /\/personeller\/belge-takip$/ },
  { id: "finans", label: "Finans", path: /\/finans$/ }
] as const;

async function expectNoHorizontalOverflow(page: import("@playwright/test").Page) {
  const overflow = await page.evaluate(() => {
    const doc = document.documentElement;
    return {
      overflowX: doc.scrollWidth > doc.clientWidth + 1,
      scrollWidth: doc.scrollWidth,
      clientWidth: doc.clientWidth
    };
  });
  expect(overflow.overflowX).toBe(false);
}

test.describe("Secondary module navigation", () => {
  test("GY sees home MODÜLLER card, inline picker and each link opens the correct route", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await expect(page).toHaveURL(/\/$/);

    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);

    const card = page.getByTestId("menu-moduller");
    await expect(card).toBeVisible();
    await expect(card).toHaveAttribute("aria-expanded", "false");
    await expect(page.getByTestId("home-modules-nav")).toHaveCount(0);

    await card.click();
    await expect(card).toHaveAttribute("aria-expanded", "true");

    const nav = page.getByTestId("home-modules-nav");
    await expect(nav).toBeVisible();
    await expect(nav.getByRole("link")).toHaveCount(GY_LINKS.length);

    for (const link of GY_LINKS) {
      await expect(page.getByTestId(`home-module-link-${link.id}`)).toHaveText(link.label);
    }

    for (const link of GY_LINKS) {
      await page.goto("/");
      await page.getByTestId("menu-moduller").click();
      await page.getByTestId(`home-module-link-${link.id}`).click();
      await expect(page).toHaveURL(link.path);
    }
  });

  test("Personel Kartı overlay has no Modules navigation", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("dialog", { name: "Personel Kartı" })).toBeVisible();
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Puantaj" })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Günlük Kayıt" })).toHaveCount(0);
  });

  test("Kayıt modal and overlay routes have no Modules toggle", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    await page.getByTestId("menu-kayit-surec").click();
    await expect(page.getByRole("dialog", { name: "Kayıt ve Süreç İşlemleri" })).toBeVisible();
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
    await page.keyboard.press("Escape");

    await page.goto("/personeller");
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
  });

  test("Yönetim modal has no Modules toggle", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    await page.getByTestId("header-settings-toggle").click();
    await page.getByTestId("settings-yonetim-paneli").click();
    await expect(page).toHaveURL(/\/yonetim-paneli\?tab=kullanicilar$/);
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
    await expect(page.getByText("Modüller", { exact: true })).toHaveCount(0);
  });

  test("BIRIM_AMIRI home picker hides Finans; PERSONEL has disabled MODÜLLER card", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, MOCK_ROLE_LOGIN.BIRIM_AMIRI);
    await page.getByTestId("menu-moduller").click();
    await expect(page.getByTestId("home-module-link-gunluk-kayit")).toBeVisible();
    await expect(page.getByTestId("home-module-link-finans")).toHaveCount(0);

    await mockApi(page, "PERSONEL");
    await login(page, MOCK_ROLE_LOGIN.PERSONEL);
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
    await expect(page.getByTestId("menu-moduller")).toHaveCount(0);
    await page.goto("/raporlar");
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
  });

  test("MODÜLLER card toggles closed on second click", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    const card = page.getByTestId("menu-moduller");
    await card.click();
    await expect(page.getByTestId("home-modules-nav")).toBeVisible();
    await card.click();
    await expect(page.getByTestId("home-modules-nav")).toHaveCount(0);
    await expect(card).toHaveAttribute("aria-expanded", "false");
  });

  test("mobile viewports keep home MODÜLLER picker without horizontal overflow", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    for (const size of [
      { width: 430, height: 844 },
      { width: 390, height: 844 },
      { width: 375, height: 812 },
      { width: 360, height: 740 },
      { width: 320, height: 568 }
    ]) {
      await page.setViewportSize(size);
      await page.goto("/");
      const card = page.getByTestId("menu-moduller");
      await expect(card).toBeVisible();
      await card.click();
      await expect(page.getByTestId("home-modules-nav")).toBeVisible();
      await expectNoHorizontalOverflow(page);
    }
  });
});
