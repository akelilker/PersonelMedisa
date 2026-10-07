/**
 * Back bar flush under modal header — geometry contract (bugun-personel only).
 */

import { expect, test, type Locator, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";
import { mkdirSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";

const ARTIFACT_DIR = resolve(process.cwd(), "/opt/cursor/artifacts/screenshots");

const MINI_PAYLOAD = {
  tarih: "2026-10-07",
  timezone: "Europe/Istanbul",
  workday_start: "08:30",
  on_time_deadline: "09:30",
  server_now: "2026-10-07T10:00:00+03:00",
  attention_count: 0,
  branches: [
    {
      sube_id: 1,
      sube_adi: "Medisa Fabrika",
      period_writable: true,
      counts: {
        toplam: 2,
        geldi: 1,
        gec_geldi: 0,
        erken_cikti: 0,
        gelmedi: 0,
        izinli: 0,
        raporlu: 0,
        gorevde: 0,
        henuz_degerlendirilmedi: 1
      },
      birim_bildirim: { tamamlanan: 0, toplam: 1 },
      units: [
        {
          birim_id: 1,
          birim_adi: "Demir",
          bolum_id: null,
          bolum_adi: null,
          counts: {
            toplam: 2,
            geldi: 1,
            gec_geldi: 0,
            erken_cikti: 0,
            gelmedi: 0,
            izinli: 0,
            raporlu: 0,
            gorevde: 0,
            henuz_degerlendirilmedi: 1
          },
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
              ad_soyad: "Mehmet DEMİR",
              personel_tipi_ad: "Mavi Yaka",
              durum: "GEC_GELDI",
              durum_label: "Geç Geldi",
              gec_kalma_dakika: 5,
              erken_cikis_dakika: null,
              giris_saati: "08:40",
              cikis_saati: null,
              aciklama: null,
              alt_tur: null,
              detail_line: "08:40",
              group: "ACTUAL"
            },
            {
              personel_id: 2,
              ad_soyad: "Can ÖZTÜRK",
              personel_tipi_ad: null,
              durum: "HENUZ_DEGERLENDIRILMEDI",
              durum_label: "Henüz Değerlendirilmedi",
              gec_kalma_dakika: null,
              erken_cikis_dakika: null,
              giris_saati: null,
              cikis_saati: null,
              aciklama: null,
              alt_tur: null,
              detail_line: "Henüz",
              group: "PENDING"
            }
          ]
        }
      ]
    }
  ]
};

type BackBarMetrics = {
  gapHeaderToBack: number;
  backHeight: number;
  gapBackToPanel: number;
  backLeftOffset: number;
};

async function routeBugun(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname.startsWith("/api/bildirimler/bugun-personel-durumu"),
    (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data: MINI_PAYLOAD, meta: {}, errors: [] })
      })
  );
}

async function openFabrikaBranch(modal: Locator) {
  await modal.getByTestId("bugun-branch-1").click();
  await expect(modal.getByTestId("bugun-branch-overview")).toBeVisible();
}

async function measureBackBar(modal: Locator): Promise<BackBarMetrics> {
  const header = modal.locator(".modal-header");
  const backBar = modal.locator("> .modal-body--bugun-personel > .universal-back-bar").first();
  const body = modal.locator(".modal-body--bugun-personel");
  const panel = modal.getByTestId("bugun-personel-durumu-panel");
  const [h, b, p, bodyBox] = await Promise.all([
    header.boundingBox(),
    backBar.boundingBox(),
    panel.boundingBox(),
    body.boundingBox()
  ]);
  if (!h || !b || !p || !bodyBox) {
    throw new Error("Missing bounding box for back-bar geometry");
  }
  return {
    gapHeaderToBack: b.y - (h.y + h.height),
    backHeight: b.height,
    gapBackToPanel: p.y - (b.y + b.height),
    backLeftOffset: b.x - bodyBox.x
  };
}

function assertBackBarMetrics(m: BackBarMetrics) {
  expect(m.gapHeaderToBack).toBeLessThanOrEqual(2);
  expect(m.backHeight).toBeLessThanOrEqual(40);
  expect(m.gapBackToPanel).toBeLessThanOrEqual(8);
  expect(m.backLeftOffset).toBeGreaterThanOrEqual(7);
  expect(m.backLeftOffset).toBeLessThanOrEqual(9);
}

async function setupModal(page: Page, width: number, height: number) {
  await page.setViewportSize({ width, height });
  await mockApi(page, "GENEL_YONETICI");
  await routeBugun(page);
  await login(page, { username: "yonetici", password: "secret" });
  await page.getByTestId("bugun-personel-durumu-entry").click();
  const modal = page.locator(".modal-container--bugun-personel").last();
  await openFabrikaBranch(modal);
  return modal;
}

test.describe("back bar header flush geometry", () => {
  test("mobil 390 — özet, Gelen, Mavi Yaka liste", async ({ page }, testInfo) => {
    mkdirSync(ARTIFACT_DIR, { recursive: true });
    const modal = await setupModal(page, 390, 844);

    const summary = await measureBackBar(modal);
    writeFileSync(
      resolve("/opt/cursor/artifacts", "back-bar-metrics-summary-mobile.json"),
      JSON.stringify(summary, null, 2)
    );
    assertBackBarMetrics(summary);
    await page.screenshot({
      path: resolve(ARTIFACT_DIR, "back-bar-fix-mobil-fabrika-ozet.png"),
      fullPage: true
    });

    await modal.getByTestId("bugun-gelen").click();
    const gelen = await measureBackBar(modal);
    assertBackBarMetrics(gelen);
    await page.screenshot({
      path: resolve(ARTIFACT_DIR, "back-bar-fix-mobil-gelen.png"),
      fullPage: true
    });

    await modal.getByTestId("bugun-yaka-mavi").click();
    const mavi = await measureBackBar(modal);
    assertBackBarMetrics(mavi);
    await page.screenshot({
      path: resolve(ARTIFACT_DIR, "back-bar-fix-mobil-mavi-yaka-liste.png"),
      fullPage: true
    });

    testInfo.attach("metrics-mobile", {
      body: JSON.stringify({ summary, gelen, mavi }, null, 2),
      contentType: "application/json"
    });
  });

  test("desktop 1280 — özet ölçüm", async ({ page }) => {
    const modal = await setupModal(page, 1280, 800);
    const m = await measureBackBar(modal);
    assertBackBarMetrics(m);
    await page.screenshot({
      path: resolve(ARTIFACT_DIR, "back-bar-fix-desktop-ozet.png"),
      fullPage: true
    });
  });
});
