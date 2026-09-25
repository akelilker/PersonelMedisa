import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const MOBILE_VIEWPORTS = [
  { width: 430, height: 932, label: "430x932" },
  { width: 393, height: 852, label: "393x852" }
] as const;

async function openQrScan(page: import("@playwright/test").Page, event?: "GIRIS" | "CIKIS") {
  const path = event ? `/self/qr-okut?event=${event}` : "/self/qr-okut";
  await page.goto(path);
  await expect(page).toHaveURL(new RegExp(`/self/qr-okut`));
  await expect(page.getByTestId("personel-qr-scan-page")).toBeVisible();
}

test.describe("personel mobile field UX — QR scan modal", () => {
  test.beforeEach(async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI", {
      personelBinding: { personel_id: 173, personel_tipi_ad: "Mavi Yaka" }
    });
    await login(page, MOCK_ROLE_LOGIN.BOLUM_YONETICISI);
  });

  for (const viewport of MOBILE_VIEWPORTS) {
    test(`${viewport.label}: meaningful title + Kamerayı aç visible in first frame`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await openQrScan(page, "GIRIS");

      const modalTitle = page.locator(".modal-header h2").first();
      await expect(modalTitle).toBeVisible();
      await expect(modalTitle).toHaveText("Giriş");
      await expect(modalTitle).not.toHaveText("Modül");

      const cta = page.getByTestId("qr-scan-start");
      await expect(cta).toBeVisible();
      await expect(cta).toHaveText("QR Okut");
      const ctaBox = await cta.boundingBox();
      expect(ctaBox).toBeTruthy();
      expect(ctaBox!.y + ctaBox!.height).toBeLessThanOrEqual(viewport.height + 1);
      expect(ctaBox!.y).toBeGreaterThanOrEqual(0);

      const preview = page.getByTestId("qr-scan-video-wrap");
      const previewBox = await preview.boundingBox();
      expect(previewBox).toBeTruthy();
      expect(previewBox!.height).toBeLessThanOrEqual(Math.round(viewport.height * 0.42) + 8);

      const docOverflow = await page.evaluate(() => {
        return document.documentElement.scrollWidth - window.innerWidth;
      });
      expect(docOverflow).toBeLessThanOrEqual(1);
    });
  }

  test("idle CTA click enters scanning or controlled camera error with reachable retry", async ({ page }) => {
    await page.setViewportSize({ width: 430, height: 932 });
    await openQrScan(page);

    await page.getByTestId("qr-scan-start").click();
    await expect
      .poll(async () => {
        const scanning = await page.getByTestId("qr-scan-scanning").count();
        const error = await page.getByTestId("qr-scan-error").count();
        return scanning + error;
      })
      .toBeGreaterThan(0);

    if ((await page.getByTestId("qr-scan-scanning").count()) > 0) {
      await expect(page.getByTestId("qr-scan-video-wrap")).toContainText("Kodu çerçeveye hizalayın");
    } else {
      await expect(page.getByTestId("qr-scan-error")).toBeVisible();
      await expect(page.getByRole("button", { name: "Tekrar dene" })).toBeVisible();
    }
  });

  test("CIKIS preset title is Çıkış; no-preset title is QR Okut", async ({ page }) => {
    await page.setViewportSize({ width: 393, height: 852 });
    await openQrScan(page, "CIKIS");
    await expect(page.locator(".modal-header h2").first()).toHaveText("Çıkış");

    await openQrScan(page);
    await expect(page.locator(".modal-header h2").first()).toHaveText("QR Okut");
  });
});
