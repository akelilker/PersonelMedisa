import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test("Genel kayıt iki kolon, alt yazı yok, sekme kromu opak", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "yonetici", password: "secret" });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/personeller/1");

  await expect(page.getByTestId("personel-dosya-sticky-head")).toBeVisible();
  await expect(page.getByTestId("personel-dosya-kayit-mirror")).toBeVisible();
  await expect(
    page.getByText("Temel kimlik, iletişim ve lokasyon verileri bu dosyada salt okunur izlenir.")
  ).toHaveCount(0);

  const columnsTemplate = await page
    .getByTestId("personel-dosya-kayit-mirror")
    .locator(".personel-form-columns")
    .evaluate((el) => window.getComputedStyle(el).gridTemplateColumns);
  expect(columnsTemplate.split(" ").filter(Boolean).length).toBe(2);

  const mirror = page.getByTestId("personel-dosya-kayit-mirror");
  const columns = mirror.locator(".personel-form-column");
  await expect(columns).toHaveCount(2);

  const leftTc = columns.nth(0).getByText("T.C. Kimlik No", { exact: true });
  const rightSube = columns.nth(1).getByText("Şube", { exact: true });
  const tcBox = await leftTc.boundingBox();
  const subeBox = await rightSube.boundingBox();
  expect(tcBox).not.toBeNull();
  expect(subeBox).not.toBeNull();
  if (tcBox && subeBox) {
    expect(subeBox.x).toBeGreaterThan(tcBox.x + 40);
  }

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
