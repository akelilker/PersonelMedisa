import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test("Ana akis smoke", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "genel_yonetici", password: "demo123" });

  await expect(page).toHaveURL("/");

  await page.getByTestId("menu-personel-karti").click();
  await expect(page).toHaveURL(/\/personeller$/);

  await page.goto("/puantaj");
  await expect(page).toHaveURL(/\/puantaj$/);

  await page.goto("/raporlar");
  await expect(page).toHaveURL(/\/raporlar$/);
});
