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

  test("393x852: scanning label stays compact under camera (no modal stretch gap)", async ({
    page
  }) => {
    await page.setViewportSize({ width: 393, height: 852 });
    await openQrScan(page, "GIRIS");
    await page.getByTestId("qr-scan-start").click();

    await expect
      .poll(async () => {
        const scanning = await page.getByTestId("qr-scan-scanning").count();
        const error = await page.getByTestId("qr-scan-error").count();
        return scanning + error;
      })
      .toBeGreaterThan(0);

    if ((await page.getByTestId("qr-scan-scanning").count()) === 0) {
      // Camera permission denied in CI/agent — geometry only applies to scanning phase.
      await expect(page.getByTestId("qr-scan-error")).toBeVisible();
      return;
    }

    const camera = page.getByTestId("qr-scan-video-wrap");
    const scanning = page.getByTestId("qr-scan-scanning");
    await expect(scanning).toHaveText("QR Okutun");

    const cameraBox = await camera.boundingBox();
    const scanningBox = await scanning.boundingBox();
    expect(cameraBox).toBeTruthy();
    expect(scanningBox).toBeTruthy();

    const gap = scanningBox!.y - (cameraBox!.y + cameraBox!.height);
    expect(gap).toBeGreaterThanOrEqual(0);
    expect(gap).toBeLessThanOrEqual(48);
  });
});

test.describe("personel mobile field UX — home attendance single surface", () => {
  test("393x852: actionable GİRİŞ is one card surface; ÇIKIŞ matches outer geometry", async ({
    page
  }) => {
    await page.setViewportSize({ width: 393, height: 852 });
    await mockApi(page, "PERSONEL", {
      personelBinding: { personel_id: 173, personel_tipi_ad: "Mavi Yaka" },
      sessionAdSoyad: "Ayşe Yılmaz"
    });
    await page.route("**/api/me/attendance/today**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          data: {
            business_date: "2026-09-25",
            capabilities: {
              calisan_kapsami: "IC_PERSONEL",
              shell: true,
              qr_scan: true,
              attendance_correct: true,
              puantaj_write: false,
              izin_write: false,
              coming_soon_message: null
            },
            personel: {
              id: 173,
              ad_soyad: "Ayşe Yılmaz",
              sube_ad: "Merkez",
              bolum_ad: "Operasyon",
              birim_ad: "Saha",
              gorev_ad: "Teknisyen"
            },
            giris: {
              id: 101,
              event_type: "GIRIS",
              occurred_at: "2026-09-25T09:01:00+03:00",
              local_time: "09:01",
              display_local_time: "09:01",
              correction_allowed: true,
              status: null
            },
            cikis: {
              id: 102,
              event_type: "CIKIS",
              occurred_at: "2026-09-25T17:02:00+03:00",
              local_time: "17:02",
              display_local_time: "17:02",
              correction_allowed: true,
              status: {
                kind: "EARLY_EXIT_INFO",
                label: "Normal Mesai Bitiminden 38dk Önce Çıkış Yaptınız.",
                delta_dakika: 38
              }
            },
            can_scan_giris: true,
            can_scan_cikis: false,
            next_action: "GIRIS",
            pending_giris_correction: null,
            pending_cikis_correction: null
          },
          meta: {},
          errors: []
        })
      });
    });
    await page.route("**/api/me/inbox-notifications**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data: { items: [], pending_popups: [] }, meta: {}, errors: [] })
      });
    });
    await login(page, MOCK_ROLE_LOGIN.PERSONEL);

    await expect(page.getByTestId("personel-self-service-page")).toBeVisible();
    const girisCard = page.getByTestId("attendance-box-giris");
    const cikisCard = page.getByTestId("attendance-box-cikis");
    const girisBtn = page.getByTestId("giris-scan");
    await expect(girisBtn).toBeVisible();

    const metrics = await page.evaluate(() => {
      const card = document.querySelector('[data-testid="attendance-box-giris"]') as HTMLElement | null;
      const btn = document.querySelector('[data-testid="giris-scan"]') as HTMLElement | null;
      const cikis = document.querySelector('[data-testid="attendance-box-cikis"]') as HTMLElement | null;
      if (!card || !btn || !cikis) return null;
      const cardStyle = getComputedStyle(card);
      const btnStyle = getComputedStyle(btn);
      const cardRect = card.getBoundingClientRect();
      const cikisRect = cikis.getBoundingClientRect();
      return {
        cardBorderTop: cardStyle.borderTopWidth,
        btnBorderTop: btnStyle.borderTopWidth,
        btnBorderRight: btnStyle.borderRightWidth,
        btnBorderBottom: btnStyle.borderBottomWidth,
        btnBorderLeft: btnStyle.borderLeftWidth,
        // Button fills the card content box (border stays on the outer surface).
        widthDelta: Math.abs(card.clientWidth - btn.offsetWidth),
        heightDelta: Math.abs(card.clientHeight - btn.offsetHeight),
        cardWidth: cardRect.width,
        cikisWidth: cikisRect.width,
        cardHeight: cardRect.height,
        cikisHeight: cikisRect.height
      };
    });

    expect(metrics).toBeTruthy();
    expect(Number.parseFloat(metrics!.cardBorderTop)).toBeGreaterThan(0);
    expect(Number.parseFloat(metrics!.btnBorderTop)).toBe(0);
    expect(Number.parseFloat(metrics!.btnBorderRight)).toBe(0);
    expect(Number.parseFloat(metrics!.btnBorderBottom)).toBe(0);
    expect(Number.parseFloat(metrics!.btnBorderLeft)).toBe(0);
    expect(metrics!.widthDelta).toBeLessThanOrEqual(1);
    expect(metrics!.heightDelta).toBeLessThanOrEqual(1);
    expect(Math.abs(metrics!.cardWidth - metrics!.cikisWidth)).toBeLessThanOrEqual(1);
    expect(Math.abs(metrics!.cardHeight - metrics!.cikisHeight)).toBeLessThanOrEqual(2);

    await expect(girisCard).toBeVisible();
    await expect(cikisCard).toBeVisible();
  });
});
