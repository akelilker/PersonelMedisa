import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test("Ana akis smoke", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "genel_yonetici", password: "demo123" });

  await expect(page).toHaveURL("/");
  await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
  await expect(page.getByTestId("menu-kayit-surec")).toBeVisible();
  await expect(page.getByTestId("menu-personel-karti")).toBeVisible();
  await expect(page.getByTestId("menu-raporlar")).toBeVisible();
  await expect(page.getByTestId("menu-gunluk-durum")).toHaveCount(0);

  await page.getByTestId("menu-kayit-surec").click();
  const homeFlowModal = page.locator(".modal-container").last();
  await expect(
    homeFlowModal.getByRole("heading", { name: /Personel Giris ve Surec Takibi/i })
  ).toBeVisible();
  await expect(homeFlowModal.getByRole("button", { name: "Yeni Kayit" })).toBeVisible();
  await expect(homeFlowModal.getByRole("button", { name: "Surec" })).toBeVisible();
  await homeFlowModal.getByRole("button", { name: "Surec" }).click();
  await homeFlowModal.getByRole("button", { name: /Surec Ekranina Git/i }).click();

  await expect(page).toHaveURL(/\/surecler$/);
  await expect(page.locator(".modal-header h2").first()).toContainText("Surec Takibi");
  await page.locator(".modal-container").first().getByRole("button", { name: "Kapat" }).click();
  await expect(page).toHaveURL("/");
  await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);

  await page.getByTestId("menu-personel-karti").click();
  await expect(page).toHaveURL(/\/personeller$/);
  await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();
  await page.locator(".modal-container").first().getByRole("button", { name: "Kapat" }).click();
  await expect(page).toHaveURL("/");

  await page.getByTestId("menu-raporlar").click();
  await expect(page).toHaveURL(/\/raporlar$/);
  await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");
  await page.locator(".modal-container").first().getByRole("button", { name: "Kapat" }).click();
  await expect(page).toHaveURL("/");
});
