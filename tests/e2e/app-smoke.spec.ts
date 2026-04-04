import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test("Ana akis smoke", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "genel_yonetici", password: "demo123" });

  await expect(page).toHaveURL("/");

  await page.getByTestId("menu-personel-karti").click();
  await expect(page).toHaveURL(/\/personeller$/);
  await expect(page.getByTestId("menu-surec-takibi")).toBeVisible();
  await expect(page.locator(".modal-overlay.open")).toHaveCount(0);

  await page.getByRole("link", { name: "Detay" }).first().click();
  await expect(page).toHaveURL(/\/personeller\/1$/);
  await expect(page.getByTestId("menu-puantaj")).toBeVisible();
  await expect(page.locator(".modal-overlay.open")).toHaveCount(0);

  await page.getByRole("link", { name: "Yeni Surec" }).click();
  await expect(page).toHaveURL(/\/surecler$/);
  await page.locator(".modal-container").waitFor({ state: "visible" });
  await expect(page.locator("#surec-create-form").getByLabel("Personel ID")).toHaveValue("1");
  await page.locator(".modal-container").getByRole("button", { name: /Vazge/ }).click();
  await expect(page.locator(".modal-overlay.open")).toHaveCount(0);

  await page.goto("/personeller/1");
  await page.getByRole("link", { name: "Bildirim Olustur" }).click();
  await expect(page).toHaveURL(/\/bildirimler$/);
  await page.locator(".modal-container").waitFor({ state: "visible" });
  await expect(page.locator("#bildirim-create-form").getByLabel("Personel")).toHaveValue("1");
  await expect(page.locator("#bildirim-create-form")).toContainText("Ayse Yilmaz");
  await page.locator(".modal-container").getByRole("button", { name: /Vazge/ }).click();
  await expect(page.locator(".modal-overlay.open")).toHaveCount(0);

  await page.goto("/personeller/1");
  await page.getByRole("link", { name: "Puantaji Ac" }).click();
  await expect(page).toHaveURL(/\/puantaj$/);
  await expect(page.getByLabel("Personel ID")).toHaveValue("1");

  await page.goto("/puantaj");
  await expect(page).toHaveURL(/\/puantaj$/);

  await page.goto("/raporlar");
  await expect(page).toHaveURL(/\/raporlar$/);
});
