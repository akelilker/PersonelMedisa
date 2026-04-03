import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "genel_yonetici", password: "demo123" },
  bolumYonetici: { username: "bolum_yonetici", password: "demo123" },
  muhasebe: { username: "muhasebe", password: "demo123" },
  birimAmiri: { username: "birim_amiri", password: "demo123" }
};

function modalRouteHeading(page: Page, name: string | RegExp) {
  return page.locator(".modal-header").first().getByRole("heading", { name });
}

test.describe("Rol bazli smoke", () => {
  test("Genel yonetici ana modullere dogrudan erisebilir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);
    await expect(page).toHaveURL("/");

    await page.goto("/personeller");
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();

    await page.goto("/surecler");
    await expect(modalRouteHeading(page, /Surec Takibi|Süreç Takibi/i)).toBeVisible();

    await page.goto("/puantaj");
    await expect(modalRouteHeading(page, /Gunluk Puantaj|Günlük Puantaj/i)).toBeVisible();

    await page.goto("/raporlar");
    await expect(modalRouteHeading(page, "Raporlar")).toBeVisible();
  });

  test("Bolum yoneticisi yetkili modullere erisebilir", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, users.bolumYonetici);
    await expect(page).toHaveURL("/");

    await page.goto("/personeller");
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();

    await page.goto("/surecler");
    await expect(modalRouteHeading(page, /Surec Takibi|Süreç Takibi/i)).toBeVisible();

    await page.goto("/raporlar");
    await expect(modalRouteHeading(page, "Raporlar")).toBeVisible();
  });

  test("Muhasebe finans ve rapor modullerine erisebilir", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);
    await expect(page).toHaveURL("/");

    await page.goto("/raporlar");
    await expect(modalRouteHeading(page, "Raporlar")).toBeVisible();

    await page.goto("/finans");
    await expect(modalRouteHeading(page, "Finans")).toBeVisible();
  });

  test("Birim amiri bildirim akisini kullanir ama finans ve haftalik kapanisa erisemez", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, users.birimAmiri);
    await expect(page).toHaveURL("/");

    await expect(page.getByTestId("menu-gunluk-durum")).toBeVisible();

    await page.goto("/bildirimler");
    await expect(modalRouteHeading(page, "Bildirimler")).toBeVisible();
    await expect(page.getByRole("button", { name: /Gunluk Durum Bildir|Yeni Bildirim/i })).toBeVisible();

    await page.goto("/raporlar");
    await expect(modalRouteHeading(page, "Raporlar")).toBeVisible();

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/yetkisiz$/);

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/yetkisiz$/);
  });
});
