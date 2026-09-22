import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

async function openPersonelDetay(page: import("@playwright/test").Page, personelId = 1) {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "yonetici", password: "secret" });
  await page.goto(`/personeller/${personelId}`);
  await expect(page).toHaveURL(new RegExp(`/personeller/${personelId}$`));
  await expect(page.locator(".personel-dosya-hero")).toBeVisible();
}

test("personel kartı sekme şeridi Genel ve Zimmet arasında dikey kaymaz", async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await openPersonelDetay(page);

  const tabList = page.locator(".personel-kart-tablist");
  const hero = page.locator(".personel-dosya-hero-name");
  await expect(tabList).toBeVisible();

  await page.getByRole("tab", { name: /Genel/ }).click();
  const genelTabTop = await tabList.evaluate((el) => el.getBoundingClientRect().top);
  const genelHeroTop = await hero.evaluate((el) => el.getBoundingClientRect().top);

  await page.getByRole("tab", { name: /Zimmet/ }).click();
  const zimmetTabTop = await tabList.evaluate((el) => el.getBoundingClientRect().top);
  const zimmetHeroTop = await hero.evaluate((el) => el.getBoundingClientRect().top);

  expect(Math.abs(genelTabTop - zimmetTabTop)).toBeLessThan(0.5);
  expect(Math.abs(genelHeroTop - zimmetHeroTop)).toBeLessThan(0.5);
});
