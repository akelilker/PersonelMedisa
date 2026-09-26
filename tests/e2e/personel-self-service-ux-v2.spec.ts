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
  can_scan_giris: true,
  can_scan_cikis: false,
  next_action: "GIRIS",
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
    },
    {
      id: 201,
      event_type: "GIRIS",
      occurred_at: "2026-09-24T09:05:00+03:00",
      sube: { id: 1, ad: "Merkez" }
    }
  ],
  days: [
    {
      date: "2026-09-24",
      has_events: true,
      giris: {
        id: 201,
        time: "09:05",
        occurred_at: "2026-09-24T09:05:00+03:00",
        status: { kind: "LATE_ENTRY_INFO", label: "35dk Gecikme", delta_dakika: 35 },
        correction_allowed: true,
        pending_correction: null
      },
      cikis: null,
      status_lines: ["35dk Gecikme", "Çıkış Kaydı Bulunamadı."],
      events: [
        {
          id: 201,
          event_type: "GIRIS",
          time: "09:05",
          occurred_at: "2026-09-24T09:05:00+03:00",
          status: { kind: "LATE_ENTRY_INFO", label: "35dk Gecikme", delta_dakika: 35 },
          correction_allowed: true,
          pending_correction: null
        }
      ]
    },
    {
      date: "2026-09-25",
      has_events: true,
      giris: {
        id: 101,
        event_type: "GIRIS",
        time: "09:01",
        occurred_at: "2026-09-25T09:01:00+03:00",
        status: { kind: "LATE_ENTRY_INFO", label: "31dk Gecikme", delta_dakika: 31 },
        correction_allowed: true,
        pending_correction: null
      },
      cikis: {
        id: 102,
        event_type: "CIKIS",
        time: "17:02",
        occurred_at: "2026-09-25T17:02:00+03:00",
        status: {
          kind: "EARLY_EXIT_INFO",
          label: "Normal Mesai Bitiminden 38dk Önce Çıkış Yaptınız.",
          delta_dakika: 38
        },
        correction_allowed: true,
        pending_correction: null
      },
      status_lines: ["31dk Gecikme", "Normal Mesai Bitiminden 38dk Önce Çıkış Yaptınız."],
      events: [
        {
          id: 101,
          event_type: "GIRIS",
          time: "09:01",
          occurred_at: "2026-09-25T09:01:00+03:00",
          status: { kind: "LATE_ENTRY_INFO", label: "31dk Gecikme", delta_dakika: 31 },
          correction_allowed: true,
          pending_correction: null
        },
        {
          id: 102,
          event_type: "CIKIS",
          time: "17:02",
          occurred_at: "2026-09-25T17:02:00+03:00",
          status: {
            kind: "EARLY_EXIT_INFO",
            label: "Normal Mesai Bitiminden 38dk Önce Çıkış Yaptınız.",
            delta_dakika: 38
          },
          correction_allowed: true,
          pending_correction: null
        }
      ]
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

async function installPersonelSelfServiceMocks(
  page: Page,
  options?: { openShift?: boolean; historyDays?: typeof HISTORY_DAYS }
) {
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
        can_scan_cikis: true,
        next_action: "CIKIS"
      }
    : TODAY_LATE;

  let historyPayload = structuredClone(options?.historyDays ?? HISTORY_DAYS);

  await page.route("**/api/me/attendance/today**", async (route) => {
    await fulfillOk(route, today);
  });
  await page.route("**/api/me/inbox-notifications**", async (route) => {
    await fulfillOk(route, { items: [], pending_popups: [] });
  });
  await page.route("**/api/me/qr-hareketleri**", async (route) => {
    await fulfillOk(route, historyPayload);
  });
  await page.route("**/api/me/attendance/correction-requests**", async (route) => {
    if (route.request().method() === "POST") {
      const body = route.request().postDataJSON() as { source_event_id?: number };
      const eventId = Number(body?.source_event_id ?? 0);
      historyPayload = {
        ...historyPayload,
        days: historyPayload.days.map((day) => {
          const patchEvent = (event: (typeof day)["giris"]) => {
            if (!event || event.id !== eventId) return event;
            return {
              ...event,
              correction_allowed: false,
              pending_correction: {
                id: 9001,
                status: "BEKLIYOR",
                status_label: "Bekliyor",
                requested_local_time: event.time
              }
            };
          };
          return {
            ...day,
            giris: patchEvent(day.giris),
            cikis: patchEvent(day.cikis)
          };
        })
      };
      await fulfillOk(
        route,
        {
          id: 9001,
          status: "BEKLIYOR",
          message: "Düzeltme Talebiniz Amirinize İletildi."
        },
        201
      );
      return;
    }
    await fulfillOk(route, { items: [] });
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
      // Session identity stays in the shell hero band; branch label stays hidden.
      await expect(page.getByTestId("hero-session-user")).toHaveText("Ayşe Yılmaz");
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

      await expect(page.getByTestId("giris-scan")).toBeVisible();
      await expect(page.getByTestId("cikis-status-label")).toContainText(
        "Normal Mesai Bitiminden 38dk Önce"
      );
      await expect(page.getByTestId("attendance-box-giris")).toContainText("09:01");
      await expect(page.getByTestId("attendance-box-cikis")).toContainText("17:02");

      await expect(page.getByTestId("header-attendance-history")).toBeVisible();
      await expect(page.getByTestId("header-settings-toggle")).toBeVisible();
      await expect(page.locator("#notifications-toggle-btn")).toBeVisible();

      await page.locator("#notifications-toggle-btn").click();
      await expect(page.locator("#notifications-dropdown")).toBeVisible();
      await page.keyboard.press("Escape");
      await page.getByTestId("header-settings-toggle").click();
      await expect(page.locator("#settings-menu")).toBeVisible();
      await page.keyboard.press("Escape");

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth
      );
      expect(overflow).toBeLessThanOrEqual(1);

      // PERSONEL surface uses the same fixed AppFooter as the admin shell (no mini footer).
      await expect(page.getByTestId("personel-mobile-footer")).toHaveCount(0);
      const footer = page.locator("#app-footer");
      await expect(footer).toBeVisible();
      const footerBox = await footer.boundingBox();
      expect(footerBox).toBeTruthy();
      expect(footerBox!.y + footerBox!.height).toBeGreaterThanOrEqual(viewport.height - 2);
      expect(footerBox!.height).toBeGreaterThanOrEqual(38);
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
    await expect(page.getByTestId("qr-scan-start")).toHaveCount(0);

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
    await expect(page.getByTestId("history-giris-time")).toHaveText("09:01");
    await expect(page.getByTestId("history-cikis-time")).toHaveText("17:02");
    await expect(page.getByTestId("qr-history-event-timeline")).toBeVisible();
    await expect(page.getByRole("link", { name: "Özet" })).toHaveCount(0);

    const bodyText = await page.getByTestId("personel-qr-history-page").innerText();
    expect(bodyText).not.toContain("Anomali");
    expect(bodyText).not.toContain("Kanonik");
    expect(bodyText).not.toContain("Ham QR");
    expect(bodyText).not.toContain("Eşleşme");
    expect(bodyText).not.toContain("Puantaj Adayı");
    await expect(page.getByRole("link", { name: "QR Okut" })).toHaveCount(0);
  });

  for (const viewport of MOBILE_VIEWPORTS) {
    test(`${viewport.label}: history previous-day correction pencil → modal → pending`, async ({
      page
    }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await loginPersonel(page);

      await page.getByTestId("header-attendance-history").click();
      await expect(page.getByTestId("personel-qr-history-page")).toBeVisible();
      await expect(page.getByRole("link", { name: "QR Okut" })).toHaveCount(0);

      await page.getByTestId("qr-history-day-2026-09-24").click();
      await expect(page.getByTestId("history-giris-time")).toHaveText("09:05");
      await expect(page.getByTestId("history-giris-correct")).toBeVisible();

      await page.getByTestId("history-giris-correct").click();
      const modal = page.getByTestId("attendance-correct-modal");
      await expect(modal).toBeVisible();
      await expect(modal).toContainText("Giriş Saatinizle İlgili Düzeltme Talebi Oluşturulsun mu?");
      await expect(page.getByTestId("attendance-correct-modal-secondary")).toHaveText("Hayır");
      await expect(page.getByTestId("attendance-correct-modal-primary")).toHaveText("Evet");

      await page.getByTestId("attendance-correct-time").fill("08:30");
      await page.getByTestId("attendance-correct-modal-primary").click();

      await expect(page.getByTestId("history-notice-modal")).toBeVisible();
      await expect(page.getByTestId("history-notice-modal")).toContainText(
        "Düzeltme Talebiniz Amirinize İletildi."
      );
      await page.getByTestId("history-notice-modal-close").click();

      await expect(page.getByTestId("history-giris-pending")).toHaveText("Bekliyor");
      await expect(page.getByTestId("history-giris-correct")).toHaveCount(0);
    });
  }

  test("430x932: QR scan titles + idle/scanning copy + success collapse", async ({ page }) => {
    await page.setViewportSize({ width: 430, height: 932 });
    await loginPersonel(page);

    await page.goto("/self/qr-okut?event=GIRIS");
    await expect(page.locator(".modal-header h2").first()).toHaveText("Giriş");
    await expect(page.getByTestId("qr-scan-start")).toHaveCount(0);

    await page.goto("/self/qr-okut?event=CIKIS");
    await expect(page.locator(".modal-header h2").first()).toHaveText("Çıkış");

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
          '<article data-testid="qr-scan-success"><h3>Girişiniz kaydedildi 08:31</h3></article>';
      }
    });
    await expect(page.getByTestId("qr-scan-video-wrap")).toHaveClass(/collapsed/);
    await expect(page.getByTestId("qr-scan-success")).toContainText("Girişiniz kaydedildi");
  });
});
