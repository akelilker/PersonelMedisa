import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { openHeaderSettingsMenu } from "./helpers/header-nav";
import { mockApi } from "./helpers/mock-api";

test("Ana akis smoke", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "genel_yonetici", password: "demo123" });

  await openHeaderSettingsMenu(page);
  await page.getByTestId("menu-personeller").click();
  await expect(page).toHaveURL(/\/personeller$/);

  await openHeaderSettingsMenu(page);
  await page.getByTestId("menu-puantaj").click();
  await expect(page).toHaveURL(/\/puantaj$/);

  await openHeaderSettingsMenu(page);
  await page.getByTestId("menu-raporlar").click();
  await expect(page).toHaveURL(/\/raporlar$/);
});
