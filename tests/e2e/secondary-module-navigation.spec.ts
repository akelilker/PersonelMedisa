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
  test("GY sees exact module list and each link opens the correct route", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await expect(page).toHaveURL(/\/$/);

    const toggle = page.getByTestId("header-modules-toggle");
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute("aria-label", "Modüller");
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(toggle).toHaveAttribute("aria-controls", "shell-header-modules-menu");

    await toggle.click();
    await expect(toggle).toHaveAttribute("aria-expanded", "true");

    const nav = page.getByTestId("shell-header-modules-nav");
    await expect(nav).toBeVisible();
    await expect(nav.getByRole("link")).toHaveCount(GY_LINKS.length);

    for (const link of GY_LINKS) {
      await expect(page.getByTestId(`shell-header-module-link-${link.id}`)).toHaveText(link.label);
    }

    for (const link of GY_LINKS) {
      await page.goto("/");
      await page.getByTestId("header-modules-toggle").click();
      await page.getByTestId(`shell-header-module-link-${link.id}`).click();
      await expect(page).toHaveURL(link.path);
    }
  });

  test("Personel Kartı overlay Modules navigates and Escape closes dropdown before modal", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("dialog", { name: "Personel Kartı" })).toBeVisible();
    await expect(page.getByTestId("personeller-revizyon-merkezi-link")).toHaveCount(0);
    await expect(page.getByTestId("personeller-belge-takip-link")).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Puantaj" })).toHaveCount(0);
    await expect(page.getByRole("link", { name: "Günlük Kayıt" })).toHaveCount(0);
    await expect(page.getByLabel("Modül menü")).toHaveCount(0);
    await expect(page.locator("#personeller-module-menu")).toHaveCount(0);

    const overlayToggle = page.getByTestId("overlay-modules-toggle");
    await expect(overlayToggle).toBeVisible();
    await expect(overlayToggle).toHaveAttribute("aria-controls", "shell-overlay-modules-menu");
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);

    await overlayToggle.click();
    await expect(page.getByTestId("shell-overlay-modules-nav")).toBeVisible();
    await page.getByTestId("shell-overlay-module-link-gunluk-kayit").click();
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.getByRole("dialog", { name: "Günlük Kayıt Merkezi" })).toBeVisible();
    await expect(page.getByTestId("overlay-modules-toggle")).toBeVisible();

    await page.getByTestId("overlay-modules-toggle").click();
    await expect(page.getByTestId("shell-overlay-modules-nav")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveAttribute("aria-expanded", "false");
    await expect(page.getByTestId("overlay-modules-toggle")).toBeFocused();
    await expect(page.getByRole("dialog", { name: "Günlük Kayıt Merkezi" })).toBeVisible();

    await page.keyboard.press("Escape");
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole("dialog", { name: "Günlük Kayıt Merkezi" })).toHaveCount(0);
  });

  test("Kayıt modal does not show overlay Modules; header Modules remains", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    await page.getByTestId("menu-kayit-surec").click();
    await expect(page.getByRole("dialog", { name: "Kayıt ve Süreç İşlemleri" })).toBeVisible();
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
    await expect(page.getByTestId("kayit-surec-ops-links")).toHaveCount(0);
    await expect(page.getByTestId("kayit-surec-puantaj-link")).toHaveCount(0);
    await expect(page.getByTestId("kayit-surec-revizyon-merkezi-link")).toHaveCount(0);
    await expect(page.getByTestId("header-modules-toggle")).toBeVisible();
    await expect(page.locator("#shell-header-modules-menu")).toHaveCount(1);
    await expect(page.locator("#shell-overlay-modules-menu")).toHaveCount(0);
  });

  test("correction route marks only Revizyon Merkezi active in overlay menu", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    await page.goto("/haftalik-kapanis/corrections/1");
    await expect(page.getByTestId("overlay-modules-toggle")).toBeVisible();
    await page.getByTestId("overlay-modules-toggle").click();

    await expect(page.getByTestId("shell-overlay-module-link-revizyon-merkezi")).toHaveAttribute(
      "aria-current",
      "page"
    );
    await expect(page.getByTestId("shell-overlay-module-link-haftalik-kapanis")).not.toHaveAttribute(
      "aria-current",
      "page"
    );
    await expect(
      page.locator('[data-testid^="shell-overlay-module-link-"][aria-current="page"]')
    ).toHaveCount(1);
  });

  test("BIRIM_AMIRI overlay menu hides Finans; PATRON has no toggle", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, MOCK_ROLE_LOGIN.BIRIM_AMIRI);
    await page.goto("/personeller");
    await page.getByTestId("overlay-modules-toggle").click();
    await expect(page.getByTestId("shell-overlay-module-link-gunluk-kayit")).toBeVisible();
    await expect(page.getByTestId("shell-overlay-module-link-finans")).toHaveCount(0);

    await mockApi(page, "PATRON");
    await login(page, MOCK_ROLE_LOGIN.PATRON);
    await expect(page.getByTestId("header-modules-toggle")).toHaveCount(0);
    await page.goto("/raporlar");
    await expect(page.getByTestId("overlay-modules-toggle")).toHaveCount(0);
  });

  test("opening Settings or Notifications closes header Modules", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    const toggle = page.getByTestId("header-modules-toggle");
    await toggle.click();
    await expect(page.getByTestId("shell-header-modules-nav")).toBeVisible();

    await page.getByTestId("header-settings-toggle").click();
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(page.getByTestId("shell-header-modules-nav")).toBeHidden();

    await toggle.click();
    await expect(page.getByTestId("shell-header-modules-nav")).toBeVisible();
    await page.locator("#notifications-toggle-btn").click({ force: true });
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
  });

  test("outside click and Escape close header Modules with focus restore", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    const toggle = page.getByTestId("header-modules-toggle");
    await toggle.click();
    await expect(page.getByTestId("shell-header-modules-nav")).toBeVisible();

    await page.mouse.click(8, 8);
    await expect(toggle).toHaveAttribute("aria-expanded", "false");

    await toggle.click();
    await expect(page.getByTestId("shell-header-modules-nav")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(toggle).toBeFocused();
  });

  test("mobile viewports keep Modules without horizontal overflow", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    for (const size of [
      { width: 390, height: 844 },
      { width: 320, height: 568 }
    ]) {
      await page.setViewportSize(size);
      await page.goto("/");
      const toggle = page.getByTestId("header-modules-toggle");
      await expect(toggle).toBeVisible();
      await toggle.click();
      await expect(page.getByTestId("shell-header-modules-nav")).toBeVisible();
      await expectNoHorizontalOverflow(page);

      await page.goto("/personeller");
      await page.getByTestId("overlay-modules-toggle").click();
      await expect(page.getByTestId("shell-overlay-modules-nav")).toBeVisible();
      await expectNoHorizontalOverflow(page);
    }
  });
});
