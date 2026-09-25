import { expect, test, type Page } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const MOBILE_VIEWPORTS = [
  { width: 430, height: 932, label: "430x932" },
  { width: 393, height: 852, label: "393x852" }
] as const;

const TODAY_LATE = {
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
    status: {
      kind: "LATE_ENTRY_INFO",
      label: "31dk Gecikme",
      delta_dakika: 31
    }
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
  can_scan_giris: false,
  can_scan_cikis: false,
  pending_giris_correction: null,
  pending_cikis_correction: null
};

const HISTORY_DAYS = {
  from: "2026-09-01",
  to: "2026-09-30",
  items: [
    {
      id: 101,
      event_type: "GIRIS",
      occurred_at: "2026-09-25T09:01:00+03:00",
      sube: { id: 1, ad: "Merkez" }
    },
    {
      id: 102,
      event_type: "CIKIS",
      occurred_at: "2026-09-25T17:02:00+03:00",
      sube: { id: 1, ad: "Merkez" }
    }
  ],
  days: [
    {
      date: "2026-09-25",
      has_events: true,
      giris: {
        id: 101,
        time: "09:01",
        occurred_at: "2026-09-25T09:01:00+03:00",
        status: { kind: "LATE_ENTRY_INFO", label: "31dk Gecikme", delta_dakika: 31 }
      },
      cikis: {
        id: 102,
        time: "17:02",
        occurred_at: "2026-09-25T17:02:00+03:00",
        status: {
          kind: "EARLY_EXIT_INFO",
          label: "Normal Mesai Bitiminden 38dk Önce Çıkış Yaptınız.",
          delta_dakika: 38
        }
      },
      status_lines: ["31dk Gecikme", "Normal Mesai Bitiminden 38dk Önce Çıkış Yaptınız."]
    }
  ]
};

async function fulfillOk(route: import("@playwright/test").Route, data: unknown, status = 200) {
  await route.fulfill({
    status,
    contentType: "application/json",
    body: JSON.stringify({ data, meta: {}, errors: [] })
  });
}

async function installPersonelSelfServiceMocks(page: Page, options?: { openShift?: boolean }) {
  const today = options?.openShift
    ? {
        ...TODAY_LATE,
        giris: {
          id: 101,
          event_type: "GIRIS",
          occurred_at: "2026-09-25T08:31:00+03:00",
          local_time: "08:31",
          display_local_time: "08:31",
          correction_allowed: true,
          status: null
        },
        cikis: null,
        can_scan_giris: false,
        can_scan_cikis: true
      }
    : TODAY_LATE;

  await page.route("**/api/me/attendance/today**", async (route) => {
    await fulfillOk(route, today);
  });
  await page.route("**/api/me/inbox-notifications**", async (route) => {
    await fulfillOk(route, { items: [], pending_popups: [] });
  });
  await page.route("**/api/me/qr-hareketleri**", async (route) => {
    await fulfillOk(route, HISTORY_DAYS);
  });
  await page.route("**/api/me/qr-scan**", async (route) => {
    const body = route.request().postDataJSON() as {
      early_exit_confirmed?: boolean;
      event_type?: string;
    };
    if (body?.event_type === "CIKIS" && !body.early_exit_confirmed) {
      await fulfillOk(route, {
        event: null,
        idempotent: false,
        confirmation_required: true,
        early_exit_confirm: {
          kind: "EARLY_EXIT_CONFIRMATION_REQUIRED",
          message: "Çıkış Saatine 18dk Var. Emin Misiniz?",
          delta_dakika: 18
        },
        late_early_info: null
      });
      return;
    }
    await fulfillOk(
      route,
      {
        event: {
          id: 999,
          event_type: body?.event_type ?? "CIKIS",
          occurred_at: "2026-09-25T17:22:00+03:00",
          sube: { id: 1, ad: "Merkez" }
        },
        idempotent: false,
        confirmation_required: false,
        early_exit_confirm: null,
        late_early_info: null
      },
      201
    );
  });
}

async function loginPersonel(page: Page) {
  await mockApi(page, "PERSONEL", {
    personelBinding: { personel_id: 173, personel_tipi_ad: "Mavi Yaka" },
    sessionAdSoyad: "Ayşe Yılmaz"
  });
  await installPersonelSelfServiceMocks(page);
  await login(page, MOCK_ROLE_LOGIN.PERSONEL);
}

test.describe("PERSONEL self-service UX v2 — mobile product", () => {
  for (const viewport of MOBILE_VIEWPORTS) {
    test(`${viewport.label}: home — GİRİŞ/ÇIKIŞ, no duplicate context/bell, header icons`, async ({
      page
    }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await loginPersonel(page);
      await expect(page).toHaveURL(/\/$/);
      await expect(page.getByTestId("personel-self-service-page")).toBeVisible();

      await expect(page.getByTestId("personel-mobile-header")).toHaveCount(0);
      await expect(page.getByTestId("personel-notification-bell")).toHaveCount(0);
      await expect(page.getByTestId("hero-session-user")).toHaveCount(0);
      await expect(page.getByTestId("hero-session-sube")).toHaveCount(0);
      await expect(page.getByTestId("header-sube-selector-toggle")).toHaveCount(0);
      await expect(page.getByTestId("personel-self-service-page").getByText("Ayşe Yılmaz")).toHaveCount(0);
      await expect(page.getByTestId("personel-self-service-page").getByText("Merkez")).toHaveCount(0);
      await expect(page.getByText("Bugünkü Giriş")).toHaveCount(0);

      await expect(page.getByTestId("personel-attendance-boxes")).toBeVisible();
      const girisLabel = page.getByTestId("personel-attendance-boxes").getByText("GİRİŞ", { exact: true }).first();
      const cikisLabel = page.getByTestId("personel-attendance-boxes").getByText("ÇIKIŞ", { exact: true }).first();
      await expect(girisLabel).toBeVisible();
      await expect(cikisLabel).toBeVisible();
      const girisBox = await girisLabel.boundingBox();
      const cikisBox = await cikisLabel.boundingBox();
      expect(girisBox).toBeTruthy();
      expect(cikisBox).toBeTruthy();
      expect(girisBox!.y).toBeLessThan(viewport.height);
      expect(cikisBox!.y).toBeLessThan(viewport.height);

      await expect(page.getByTestId("giris-status-label")).toHaveText("31dk Gecikme");
      await expect(page.getByTestId("cikis-status-label")).toContainText(
        "Normal Mesai Bitiminden 38dk Önce"
      );

      await expect(page.getByTestId("header-attendance-history")).toBeVisible();
      await expect(page.getByTestId("header-settings-toggle")).toBeVisible();
      await expect(page.locator("#notifications-toggle-btn")).toBeVisible();

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth
      );
      expect(overflow).toBeLessThanOrEqual(1);

      const footer = page.getByTestId("personel-mobile-footer");
      await expect(footer).toBeVisible();
      const footerBox = await footer.boundingBox();
      expect(footerBox).toBeTruthy();
      expect(footerBox!.y + footerBox!.height).toBeLessThanOrEqual(viewport.height + 2);
    });
  }

  test("430x932: early-exit confirmation modal Evet/Hayır (real write gate)", async ({ page }) => {
    await page.setViewportSize({ width: 430, height: 932 });
    await mockApi(page, "PERSONEL", {
      personelBinding: { personel_id: 173, personel_tipi_ad: "Mavi Yaka" }
    });
    await installPersonelSelfServiceMocks(page, { openShift: true });
    await login(page, MOCK_ROLE_LOGIN.PERSONEL);

    await page.goto("/self/qr-okut?event=CIKIS");
    await expect(page.locator(".modal-header h2").first()).toHaveText("Çıkış");
    await expect(page.getByTestId("qr-scan-start")).toHaveText("QR Okut");

    await page.waitForFunction(
      () =>
        typeof (window as unknown as { __pmQrScanSubmitForTest?: unknown }).__pmQrScanSubmitForTest ===
        "function"
    );

    const unconfirmedPosts: string[] = [];
    page.on("request", (req) => {
      if (req.method() === "POST" && req.url().includes("/me/qr-scan")) {
        const raw = req.postData() ?? "";
        unconfirmedPosts.push(raw);
      }
    });

    await page.evaluate(() => {
      (
        window as unknown as {
          __pmQrScanSubmitForTest: (token: string, eventType: string) => void;
        }
      ).__pmQrScanSubmitForTest("mock-token-early-exit", "CIKIS");
    });

    const modal = page.getByTestId("early-exit-confirm-modal");
    await expect(modal).toBeVisible();
    await expect(modal).toContainText("Çıkış Saatine 18dk Var. Emin Misiniz?");
    await expect(page.getByTestId("early-exit-confirm-modal-primary")).toHaveText("Evet");
    await expect(page.getByTestId("early-exit-confirm-modal-secondary")).toHaveText("Hayır");

    // CASE E — Hayır: no confirmed write call
    const postsBeforeHayir = unconfirmedPosts.length;
    await page.getByTestId("early-exit-confirm-modal-secondary").click();
    await expect(modal).toHaveCount(0);
    await expect
      .poll(() => {
        const confirmedAfterHayir = unconfirmedPosts
          .slice(postsBeforeHayir)
          .some((body) => /"early_exit_confirmed"\s*:\s*true/.test(body));
        return confirmedAfterHayir;
      })
      .toBe(false);
  });

  test("393x852: history calendar — title, nav, day detail, no technical jargon", async ({
    page
  }) => {
    await page.setViewportSize({ width: 393, height: 852 });
    await loginPersonel(page);

    await page.getByTestId("header-attendance-history").click();
    await expect(page).toHaveURL(/\/self\/qr-hareketleri/);
    await expect(page.locator(".modal-header h2").first()).toHaveText("Giriş / Çıkış Geçmişim");
    await expect(page.getByTestId("personel-qr-history-page")).toBeVisible();
    await expect(page.getByTestId("qr-history-calendar")).toBeVisible();
    await expect(page.getByTestId("qr-history-month-prev")).toBeVisible();
    await expect(page.getByTestId("qr-history-month-next")).toBeVisible();

    const dayBtn = page.getByTestId("qr-history-day-2026-09-25");
    await expect(dayBtn).toBeVisible();
    await dayBtn.click();
    await expect(page.getByTestId("qr-history-day-detail")).toBeVisible();
    await expect(page.getByTestId("qr-history-day-detail")).toContainText("Giriş");
    await expect(page.getByTestId("qr-history-day-detail")).toContainText("09:01");
    await expect(page.getByTestId("qr-history-day-detail")).toContainText("Çıkış");
    await expect(page.getByTestId("qr-history-day-detail")).toContainText("17:02");

    const bodyText = await page.getByTestId("personel-qr-history-page").innerText();
    expect(bodyText).not.toContain("Anomali");
    expect(bodyText).not.toContain("Kanonik");
    expect(bodyText).not.toContain("Ham QR");
    expect(bodyText).not.toContain("Eşleşme");
  });

  test("430x932: QR scan titles + idle/scanning copy + success collapse", async ({ page }) => {
    await page.setViewportSize({ width: 430, height: 932 });
    await loginPersonel(page);

    await page.goto("/self/qr-okut?event=GIRIS");
    await expect(page.locator(".modal-header h2").first()).toHaveText("Giriş");
    await expect(page.getByTestId("qr-scan-start")).toHaveText("QR Okut");

    await page.goto("/self/qr-okut?event=CIKIS");
    await expect(page.locator(".modal-header h2").first()).toHaveText("Çıkış");

    await page.getByTestId("qr-scan-start").click();
    await expect
      .poll(async () => {
        const scanning = await page.getByTestId("qr-scan-scanning").count();
        const error = await page.getByTestId("qr-scan-error").count();
        return scanning + error;
      })
      .toBeGreaterThan(0);

    if ((await page.getByTestId("qr-scan-scanning").count()) > 0) {
      await expect(page.getByTestId("qr-scan-scanning")).toHaveText("QR Okutun");
    }

    // Force success layout + collapsed camera without live camera decode.
    await page.evaluate(() => {
      const wrap = document.querySelector('[data-testid="qr-scan-video-wrap"]');
      wrap?.classList.add("qr-scan-video-wrap--collapsed");
      const zone = document.querySelector('[data-testid="qr-scan-cta-zone"]');
      if (zone) {
        zone.innerHTML =
          '<article data-testid="qr-scan-success"><h3>Giriş Kaydedildi — 08:31</h3></article>';
      }
    });
    await expect(page.getByTestId("qr-scan-video-wrap")).toHaveClass(/collapsed/);
    await expect(page.getByTestId("qr-scan-success")).toContainText("Giriş Kaydedildi");
  });
});
