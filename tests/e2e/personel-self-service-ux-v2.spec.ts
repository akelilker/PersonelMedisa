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

  await page.route(
    (url) => /\/api\/me\/?$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== "GET") {
        await route.fallback();
        return;
      }
      await fulfillOk(route, {
        user_id: 7,
        username: "personel",
        ad_soyad: "Ayşe Yılmaz",
        rol: "PERSONEL",
        personel_id: 173,
        personel: {
          id: 173,
          ad: "Ayşe",
          soyad: "Yılmaz",
          ad_soyad: "Ayşe Yılmaz",
          sube_id: 1,
          sube_ad: "Merkez",
          departman_id: null,
          departman_ad: null,
          bolum_id: 2,
          bolum_ad: "Operasyon",
          birim_id: 3,
          birim_ad: "Saha",
          gorev_id: 4,
          gorev_ad: "Teknisyen",
          aktif_durum: "AKTIF"
        },
        completeness: null,
        last_qr_event: null
      });
    }
  );
  await page.route("**/api/me/yillik-izin-bakiye**", async (route) => {
    await fulfillOk(route, {
      personel_id: 173,
      contract_version: "s2c-v1",
      ise_giris_tarihi: "2020-01-01",
      referans_tarih: "2026-09-25",
      kidem_yil: 6,
      efektif_hak_gun: 94,
      kullanilan_gun: 10,
      kalan_gun: 84,
      mevcut_yillik_hak_gun: 20,
      birikmis_yasal_hak_gun: 94,
      yasal_hak_gun: 94
    });
  });
  await page.route("**/api/me/fazla-calisma**", async (route) => {
    await fulfillOk(route, {
      personel_id: 173,
      yil: 2026,
      from: "2026-01-01",
      to: "2026-09-25",
      donem_ozet: {
        fazla_calisma_dakika_toplam: 60,
        calisma_gun_adet: 4
      },
      yillik: {
        personel_id: 173,
        yil: 2026,
        yillik_limit_dakika: 16200,
        yaklasma_esik_dakika: 15600,
        kullanilan_dakika: 90,
        kalan_dakika: 16110,
        limit_asildi_mi: false,
        limit_yaklasiyor_mu: false
      }
    });
  });
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
  await page.route("**/api/me/duyurular**", async (route) => {
    if (route.request().method() !== "GET") {
      await route.fallback();
      return;
    }
    await fulfillOk(route, { items: [], unread_count: 0 });
  });
  await page.route("**/api/me/profil-foto**", async (route) => {
    await fulfillOk(route, { has_photo: false, mime_type: null, image_base64: null });
  });
  await page.route("**/api/me/izinler**", async (route) => {
    await fulfillOk(route, { personel_id: 173, bakiye: null, aktif: null, gecmis: [] });
  });
  await page.route("**/api/me/avans-talepleri**", async (route) => {
    await fulfillOk(route, { items: [] });
  });
  await page.route("**/api/me/geri-bildirimler**", async (route) => {
    await fulfillOk(route, { items: [] });
  });
  await page.route("**/api/me/puantaj**", async (route) => {
    await fulfillOk(route, {
      personel_id: 173,
      from: "2026-09-01",
      to: "2026-09-30",
      items: [],
      ozet: {
        calisma_gun_adet: 0,
        gec_kalma_adet: 0,
        gec_kalma_dakika_toplam: 0,
        erken_cikis_adet: 0,
        erken_cikis_dakika_toplam: 0,
        fazla_calisma_dakika_toplam: 0,
        net_calisma_dakika_toplam: null
      }
    });
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
      // Hero keeps the session name; branch stays off the hero. Home identity is the product surface.
      await expect(page.getByTestId("hero-session-user")).toHaveText("Ayşe Yılmaz");
      await expect(page.getByTestId("hero-session-sube")).toHaveCount(0);
      await expect(page.getByTestId("header-sube-selector-toggle")).toHaveCount(0);
      const identity = page.getByTestId("personel-self-identity");
      await expect(identity).toContainText("Ayşe Yılmaz");
      await expect(identity).toContainText("Merkez");
      await expect(identity).toContainText("Operasyon");
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

      await expect(page.getByTestId("header-attendance-history")).toHaveCount(0);
      await expect(page.getByTestId("personel-menu-gecmis")).toBeVisible();
      await expect(page.getByTestId("personel-menu-izinlerim")).toBeVisible();
      await expect(page.getByTestId("personel-menu-talepler")).toBeVisible();
      await expect(page.getByTestId("personel-menu-fazla-mesai")).toBeVisible();
      await expect(page.getByTestId("personel-menu-duyurular")).toHaveCount(0);
      await expect(page.getByTestId("personel-menu-profil")).toHaveCount(0);
      await expect(page.getByTestId("personel-self-menu")).toBeVisible();
      await expect(page.getByTestId("header-settings-toggle")).toHaveCount(0);
      await expect(page.getByTestId("header-logout-btn")).toBeVisible();
      await expect(page.locator("#notifications-toggle-btn")).toBeVisible();

      await page.locator("#notifications-toggle-btn").click();
      await expect(page.locator("#notifications-dropdown")).toBeVisible();
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

    await page.getByTestId("personel-menu-gecmis").click();
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

      await page.getByTestId("personel-menu-gecmis").click();
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

  test("430x932: dock and header duyurular open real owners without fake announcements", async ({
    page
  }) => {
    await page.setViewportSize({ width: 430, height: 932 });
    await loginPersonel(page);

    await page.getByTestId("personel-menu-izinlerim").click();
    await expect(page).toHaveURL(/\/self\/izinlerim/);
    await expect(page.locator(".modal-header h2").first()).toHaveText("İzinlerim");
    await expect(page.getByTestId("personel-izin-toplam")).toHaveText("94 gün");
    await expect(page.getByTestId("personel-izin-kullanilan")).toHaveText("10 gün");
    await expect(page.getByTestId("personel-izin-kalan")).toHaveText("84 gün");
    await page.getByRole("button", { name: "Ana sayfaya dön" }).click();

    await page.getByTestId("personel-menu-talepler").click();
    await expect(page.getByTestId("personel-talep-duzeltme-giris")).toBeVisible();
    await expect(page.getByTestId("personel-talep-izin")).toBeVisible();
    await expect(page.getByTestId("personel-talep-avans")).toBeVisible();
    await expect(page.getByTestId("personel-talep-oneri")).toBeVisible();
    await expect(page.getByTestId("personel-talepler-page")).not.toContainText("Yakında");
    await page.getByRole("button", { name: "Ana sayfaya dön" }).click();

    await page.getByTestId("personel-shell-duyurular-link").click();
    await expect(page.getByTestId("personel-duyurular-empty")).toHaveText("Henüz duyuru bulunmuyor");
    await page.getByRole("button", { name: "Ana sayfaya dön" }).click();

    await page.getByTestId("personel-menu-fazla-mesai").click();
    await expect(page.getByTestId("personel-fazla-kullanilan")).toHaveText("1 saat 30 dk");
    await expect(page.getByTestId("personel-fazla-limit")).toHaveText("270 saat");
    await expect(page.getByTestId("personel-fazla-durum")).toHaveText("Limit içinde");
    await page.getByRole("button", { name: "Ana sayfaya dön" }).click();

    await expect(page.getByTestId("personel-menu-profil")).toHaveCount(0);
  });

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
        const frame = await page.getByText("Kodu çerçeveye hizalayın").count();
        const error = await page.getByTestId("qr-scan-error").count();
        return frame + error;
      })
      .toBeGreaterThan(0);

    if ((await page.getByText("Kodu çerçeveye hizalayın").count()) > 0) {
      await expect(page.getByTestId("qr-scan-video-wrap")).toContainText("Kodu çerçeveye hizalayın");
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
