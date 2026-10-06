import { mkdirSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Locator, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

const SHOT_DIR = resolve(process.cwd(), ".tmp/modal-header-collision");

const statusCounts = (toplam: number, geldi: number) => ({
  toplam,
  geldi,
  gec_geldi: 0,
  gelmedi: 0,
  izinli: 0,
  raporlu: 0,
  gorevde: 0,
  erken_cikti: 0,
  henuz_degerlendirilmedi: 0
});

/** Bugünkü Personel Durumu L2: uzun back label ("Bugünkü Personel Durumu") + uzun centered title. */
const BUGUN_PAYLOAD = {
  tarih: "2024-05-02",
  timezone: "Europe/Istanbul",
  workday_start: "08:30",
  on_time_deadline: "09:00",
  server_now: "2024-05-02T09:30:00+03:00",
  attention_count: 1,
  branches: [
    {
      sube_id: 1,
      sube_adi: "Demo Holding Merkez",
      counts: statusCounts(1, 1),
      period_writable: true,
      birim_bildirim: { tamamlanan: 0, toplam: 1 },
      units: [
        {
          birim_id: 10,
          birim_adi: "Muhasebe",
          bolum_id: null,
          bolum_adi: null,
          counts: statusCounts(1, 1),
          bildirim: {
            status: "BEKLENIYOR",
            status_label: "Bekleniyor",
            tamamlandi_mi: false,
            tamamlandi_at: null,
            tamamlayan_user_id: null,
            completion_id: null
          },
          personeller: [
            {
              personel_id: 1,
              ad_soyad: "Ayşe Yılmaz",
              durum: "GELDI",
              durum_label: "Geldi",
              gec_kalma_dakika: null,
              erken_cikis_dakika: null,
              giris_saati: "08:29",
              cikis_saati: null,
              aciklama: null,
              alt_tur: null,
              detail_line: "08:29 giriş",
              evidence: "ATTENDANCE",
              group: "ACTUAL"
            }
          ]
        }
      ]
    }
  ]
};

async function routeBugunPayload(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname.startsWith("/api/bildirimler/bugun-personel-durumu"),
    (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data: BUGUN_PAYLOAD, meta: {}, errors: [] })
      })
  );
}

async function openBugunRoot(page: Page): Promise<Locator> {
  await page.goto("/");
  await page.getByTestId("bugun-personel-durumu-entry").click();
  const modal = page.locator(".modal-container--bugun-personel").last();
  await expect(modal).toBeVisible();
  return modal;
}

type HeaderGeometry = {
  leadingRight: number;
  titleLeft: number;
  titleRight: number;
  titleCenterOffset: number;
  closeLeft: number;
  closeRight: number;
  headerLeft: number;
  headerRight: number;
  backTop: number;
  headerBottom: number;
};

async function readHeaderGeometry(modal: Locator): Promise<HeaderGeometry> {
  return modal.evaluate((root) => {
    const must = (selector: string): HTMLElement => {
      const node = root.querySelector(selector);
      if (!(node instanceof HTMLElement)) {
        throw new Error(`Missing header element: ${selector}`);
      }
      return node;
    };

    const header = must(".modal-header");
    const leading = must(".modal-header-leading");
    const back = must('[data-testid="bugun-personel-durumu-back"]');
    const title = must('[data-testid="bugun-personel-durumu-title"]');
    const close = must(".modal-close-btn");

    const headerBox = header.getBoundingClientRect();
    const leadingBox = leading.getBoundingClientRect();
    const backBox = back.getBoundingClientRect();
    const titleBox = title.getBoundingClientRect();
    const closeBox = close.getBoundingClientRect();

    return {
      leadingRight: leadingBox.right,
      titleLeft: titleBox.left,
      titleRight: titleBox.right,
      titleCenterOffset: (titleBox.left + titleBox.right) / 2 - (headerBox.left + headerBox.right) / 2,
      closeLeft: closeBox.left,
      closeRight: closeBox.right,
      headerLeft: headerBox.left,
      headerRight: headerBox.right,
      backTop: backBox.top,
      headerBottom: headerBox.bottom
    };
  });
}

function assertHeaderGeometry(geometry: HeaderGeometry, label: string): void {
  // Home (leading) lane vs centered title: no horizontal intersection.
  expect(geometry.leadingRight, `${label}: home/title overlap`).toBeLessThanOrEqual(
    geometry.titleLeft + 0.5
  );
  // Centered title vs close lane.
  expect(geometry.titleRight, `${label}: title/close overlap`).toBeLessThanOrEqual(geometry.closeLeft + 0.5);
  // Title stays centered in the header.
  expect(Math.abs(geometry.titleCenterOffset), `${label}: title not centered`).toBeLessThanOrEqual(2);
  // Close stays inside the header frame.
  expect(geometry.closeLeft).toBeGreaterThanOrEqual(geometry.headerLeft);
  expect(geometry.closeRight).toBeLessThanOrEqual(geometry.headerRight + 0.5);
  // Back control lives in a separate row BELOW the header (not inside it).
  expect(geometry.backTop, `${label}: back control not below header`).toBeGreaterThanOrEqual(
    geometry.headerBottom - 0.5
  );
}


for (const viewport of [
  { width: 390, height: 844 },
  { width: 430, height: 932 },
  { width: 1280, height: 900 }
] as const) {
  test(`Bugünkü Personel Durumu L2 back row sits below header with zero home/title/close overlap @ ${viewport.width}x${viewport.height}`, async ({
    page
  }) => {
    mkdirSync(SHOT_DIR, { recursive: true });
    await page.setViewportSize(viewport);
    await loginAsMockRole(page, "IK_SORUMLUSU");
    await routeBugunPayload(page);

    const modal = await openBugunRoot(page);
    await modal.getByTestId("bugun-branch-1").click();
    await expect(modal.getByTestId("bugun-personel-durumu-title")).toHaveText("Demo Holding Merkez");
    await expect(modal.getByTestId("bugun-personel-durumu-back")).toBeVisible();

    const l2 = await readHeaderGeometry(modal);
    assertHeaderGeometry(l2, `${viewport.width}x${viewport.height} L2`);
    await page.screenshot({ path: `${SHOT_DIR}/bugun-l2-${viewport.width}x${viewport.height}.png` });

    // İkinci uzun-label L2: back label şube adı, centered title birim adı.
    await modal.getByTestId("bugun-unit-open-10").click();
    await expect(modal.getByTestId("bugun-personel-durumu-title")).toHaveText("Muhasebe");
    const l3 = await readHeaderGeometry(modal);
    assertHeaderGeometry(l3, `${viewport.width}x${viewport.height} L3`);
    await page.screenshot({ path: `${SHOT_DIR}/bugun-l3-${viewport.width}x${viewport.height}.png` });
  });
}

