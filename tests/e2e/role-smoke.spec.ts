import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "genel_yonetici", password: "demo123" },
  bolumYonetici: { username: "bolum_yonetici", password: "demo123" },
  muhasebe: { username: "muhasebe", password: "demo123" },
  birimAmiri: { username: "birim_amiri", password: "demo123" }
};

async function expectThreeButtonHome(page: Page, canWrite: boolean) {
  await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
  await expect(page.getByTestId("menu-kayit-surec")).toBeVisible();
  if (canWrite) {
    await expect(page.getByTestId("menu-kayit-surec")).toBeEnabled();
  } else {
    await expect(page.getByTestId("menu-kayit-surec")).toBeDisabled();
  }
  await expect(page.getByTestId("menu-personel-karti")).toBeVisible();
  await expect(page.getByTestId("menu-raporlar")).toBeVisible();
  await expect(page.getByTestId("menu-gunluk-durum")).toHaveCount(0);
  await expect(page.getByTestId("menu-puantaj")).toHaveCount(0);
  await expect(page.getByTestId("menu-finans")).toHaveCount(0);
}

test.describe("Rol bazli smoke", () => {
  test("Genel yonetici home'da 3 ana omurga butonunu gorur ve ana modullere erisebilir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);
    await expect(page).toHaveURL("/");

    await expectThreeButtonHome(page, true);

    await page.goto("/personeller");
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();

    await page.goto("/surecler");
    await expect(page.locator(".modal-header h2").first()).toContainText("Süreç Takibi");

    await page.goto("/puantaj");
    await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Puantaj");

    await page.goto("/raporlar");
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");
  });

  test("Bolum yoneticisi home'da 3 ana omurga butonunu gorur ve yetkili modullere erisebilir", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, users.bolumYonetici);
    await expect(page).toHaveURL("/");

    await expectThreeButtonHome(page, true);

    await page.goto("/personeller");
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();

    await page.goto("/surecler");
    await expect(page.locator(".modal-header h2").first()).toContainText("Süreç Takibi");

    await page.goto("/raporlar");
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");
  });

  test("Muhasebe home'da 3 ana omurga butonunu gorur; finans ve raporlara ikincil akistan erisebilir", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);
    await expect(page).toHaveURL("/");

    await expectThreeButtonHome(page, true);

    await page.goto("/raporlar");
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");

    await page.goto("/finans");
    await expect(page.locator(".modal-header h2").first()).toContainText("Finans");
  });

  test("Birim amiri 3 ana omurga butonunu gorur, yazma owner'ina giremez; ikincil akislarla gunluk kayda iner", async ({
    page
  }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, users.birimAmiri);
    await expect(page).toHaveURL("/");

    await expectThreeButtonHome(page, false);

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: "Günlük Kayıt" }).click();
    await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Kayıt Merkezi");
    await expect(
      page.locator(".bildirimler-header-row").getByRole("button", { name: /Günlük Kayıt Gir|Yeni Günlük Kayıt/i })
    ).toBeVisible();

    await page.goto("/raporlar");
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/yetkisiz$/);

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/$/);
  });
});
