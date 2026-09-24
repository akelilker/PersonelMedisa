import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test("Genel kayıt: sticky kimlik, mobil tek kolon, sekme kromu opak", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "yonetici", password: "secret" });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/personeller/1");

  await expect(page.getByTestId("personel-dosya-sticky-head")).toBeVisible();
  await expect(page.getByTestId("personel-dosya-hero")).toBeVisible();
  await expect(page.getByTestId("personel-dosya-hero-sicil")).toBeVisible();
  await expect(page.getByTestId("personel-dosya-hero-kapsam")).toBeVisible();
  await expect(page.getByTestId("personel-dosya-hero-status")).toBeVisible();
  await expect(page.getByTestId("personel-dosya-actions-row")).toBeVisible();
  await expect(page.getByTestId("personel-dosya-kayit-mirror")).toBeVisible();
  await expect(page.getByTestId("personel-dosya-zone-operasyon")).toBeVisible();
  await expect(
    page.getByText("Temel kimlik, iletişim ve lokasyon verileri bu dosyada salt okunur izlenir.")
  ).toHaveCount(0);

  const actionBox = await page.getByTestId("personel-dosya-actions-row").boundingBox();
  const mirrorBox = await page.getByTestId("personel-dosya-kayit-mirror").boundingBox();
  expect(actionBox).not.toBeNull();
  expect(mirrorBox).not.toBeNull();
  if (actionBox && mirrorBox) {
    expect(actionBox.y).toBeLessThan(mirrorBox.y);
  }

  const columnsTemplate = await page
    .getByTestId("personel-dosya-kayit-mirror")
    .locator(".personel-form-columns")
    .evaluate((el) => window.getComputedStyle(el).gridTemplateColumns);
  expect(columnsTemplate.split(" ").filter(Boolean).length).toBe(1);

  const mirror = page.getByTestId("personel-dosya-kayit-mirror");
  const columns = mirror.locator(".personel-form-column");
  await expect(columns).toHaveCount(2);
  await expect(columns.nth(0).getByText("T.C. Kimlik No", { exact: true })).toBeVisible();
  await expect(columns.nth(1).getByText("İşe Giriş Tarihi", { exact: true })).toBeVisible();

  await page.setViewportSize({ width: 1100, height: 844 });
  const desktopColumnsTemplate = await page
    .getByTestId("personel-dosya-kayit-mirror")
    .locator(".personel-form-columns")
    .evaluate((el) => window.getComputedStyle(el).gridTemplateColumns);
  expect(desktopColumnsTemplate.split(" ").filter(Boolean).length).toBe(2);

  const artifactPath = process.env.PERSONEL_KARTI_GENEL_ARTIFACT;
  if (artifactPath) {
    await mirror.screenshot({ path: artifactPath });
  }

  const tabList = page.locator(".personel-kart-tablist");
  const tabTopBeforeScroll = await tabList.evaluate((el) => el.getBoundingClientRect().top);
  await page.getByTestId("personel-dosya-tab-scroll").evaluate((el) => {
    el.scrollTop = el.scrollHeight;
  });
  const tabTopAfterScroll = await tabList.evaluate((el) => el.getBoundingClientRect().top);
  expect(Math.abs(tabTopBeforeScroll - tabTopAfterScroll)).toBeLessThan(1);
});
