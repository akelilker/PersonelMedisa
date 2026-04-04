import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "genel_yonetici", password: "demo123" },
  bolumYonetici: { username: "bolum_yonetici", password: "demo123" },
  muhasebe: { username: "muhasebe", password: "demo123" },
  birimAmiri: { username: "birim_amiri", password: "demo123" }
};

test.describe("Rol bazli smoke", () => {
  test("Genel yonetici ana modullere dogrudan erisebilir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);
    await expect(page).toHaveURL("/");

    await page.goto("/personeller");
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();

    await page.goto("/surecler");
    await expect(page.getByRole("heading", { name: /Surec Takibi|Süreç Takibi/i })).toBeVisible();

    await page.goto("/puantaj");
    await expect(page.getByRole("heading", { name: /Puantaj/i })).toBeVisible();

    await page.goto("/raporlar");
    await expect(page.getByRole("heading", { name: "Raporlar" })).toBeVisible();
  });

  test("Bolum yoneticisi yetkili modullere erisebilir", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, users.bolumYonetici);
    await expect(page).toHaveURL("/");

    await page.goto("/personeller");
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();

    await page.goto("/surecler");
    await expect(page.getByRole("heading", { name: /Surec Takibi|Süreç Takibi/i })).toBeVisible();

    await page.goto("/raporlar");
    await expect(page.getByRole("heading", { name: "Raporlar" })).toBeVisible();
  });

  test("Muhasebe finans ve rapor modullerine erisebilir", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);
    await expect(page).toHaveURL("/");

    await page.goto("/raporlar");
    await expect(page.getByRole("heading", { name: "Raporlar" })).toBeVisible();

    await page.goto("/finans");
    await expect(page.getByRole("heading", { name: "Finans" })).toBeVisible();
  });

  test("Birim amiri bildirim akisini kullanir ama finans ve haftalik kapanisa erisemez", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, users.birimAmiri);
    await expect(page).toHaveURL("/");

    await expect(page.getByTestId("menu-gunluk-durum")).toBeVisible();

    await page.goto("/bildirimler");
    await expect(page.getByRole("heading", { name: "Bildirimler" })).toBeVisible();
    await expect(
      page.locator(".bildirimler-header-row").getByRole("button", { name: /Gunluk Durum Bildir|Yeni Bildirim/i })
    ).toBeVisible();

    await page.goto("/raporlar");
    await expect(page.getByRole("heading", { name: "Raporlar" })).toBeVisible();

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/yetkisiz$/);

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/yetkisiz$/);
  });
});
