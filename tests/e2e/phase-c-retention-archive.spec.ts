import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

test.describe("Phase C retention archive UI", () => {
  test("GENEL_YONETICI: Arşiv filter, PASIF badge, no Süreçte İşlem Yap", async ({ page }) => {
    expect(hasRolePermission("GENEL_YONETICI", "arsiv.view")).toBe(true);
    await loginAsMockRole(page, "GENEL_YONETICI");

    await page.goto("/personeller");
    await page.getByRole("button", { name: "Detaylı filtre aç" }).click();
    await expect(page.locator('input[name="personel-filter-pasif"]')).toBeVisible();
    await page.locator('input[name="personel-filter-pasif"]').check();
    await page.getByRole("button", { name: "Filtrele" }).click();

    await expect(page.getByTestId("personeller-arsiv-banner")).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/Pasif\s+Ornek/i).first()).toBeVisible({ timeout: 15_000 });

    await page.goto("/personeller/3");
    await expect(page.getByTestId("personel-arsiv-badge")).toBeVisible({ timeout: 15_000 });
    await expect(page.getByTestId("personel-arsiv-badge")).toContainText(/Arşiv \(salt okunur\)/i);
    await expect(page.getByTestId("personel-arsiv-badge")).toContainText(/Medisa saklama politikası/i);
    await expect(page.locator('[data-testid="personel-dosya-action-surecte-islem-yap"]')).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Süreçte İşlem Yap" })).toHaveCount(0);
  });

  test("BIRIM_AMIRI: no Arşiv option; PASIF detail forbidden via mock", async ({ page }) => {
    expect(hasRolePermission("BIRIM_AMIRI", "arsiv.view")).toBe(false);
    await loginAsMockRole(page, "BIRIM_AMIRI");

    await page.goto("/personeller");
    await page.getByRole("button", { name: "Detaylı filtre aç" }).click();
    await expect(page.locator('input[name="personel-filter-pasif"]')).toHaveCount(0);

    await page.goto("/personeller/3");
    await expect(page.getByTestId("personel-arsiv-badge")).toHaveCount(0);
    await expect(page.locator('[data-testid="personel-dosya-action-surecte-islem-yap"]')).toHaveCount(0);
    await expect(page.getByText(/Pasif\s+Ornek/i)).toHaveCount(0);
  });
});
