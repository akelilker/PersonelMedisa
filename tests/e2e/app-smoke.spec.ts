import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test("Ana akış smoke", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "genel_yonetici", password: "demo123" });

  await expect(page).toHaveURL("/");
  await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
  await expect(page.getByTestId("menu-kayit-surec")).toBeVisible();
  await expect(page.getByTestId("menu-kayit-surec")).toBeEnabled();
  await expect(page.getByTestId("menu-personel-karti")).toBeVisible();
  await expect(page.getByTestId("menu-raporlar")).toBeVisible();
  await expect(page.getByTestId("menu-gunluk-durum")).toHaveCount(0);
  await expect(page.getByTestId("menu-puantaj")).toHaveCount(0);
  await expect(page.getByTestId("menu-finans")).toHaveCount(0);

  await page.getByTestId("menu-kayit-surec").click();
  const homeFlowModal = page.locator(".modal-container").last();
  await expect(homeFlowModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  await expect(homeFlowModal.getByRole("button", { name: "Kayıt" })).toBeVisible();
  await expect(homeFlowModal.getByRole("button", { name: "Süreç" })).toBeVisible();
  await expect(homeFlowModal.getByLabel("T.C. Kimlik No")).toBeVisible();

  await homeFlowModal.getByRole("button", { name: "Süreç" }).click();
  await expect(homeFlowModal.getByLabel("Personel")).toBeVisible();
  await expect(homeFlowModal.getByLabel("Süreç Türü")).toBeVisible();
  await homeFlowModal.locator(".universal-btn-cancel").click();
  await expect(page).toHaveURL("/");

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
