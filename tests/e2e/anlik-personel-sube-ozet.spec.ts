/**
 * Anlık Personel Durumu — şube/lokasyon detayı sade özet (2026-10-07 kararı).
 * Mock API ile tam render kanıtı; mobil + masaüstü.
 */

import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";
import { mkdirSync } from "node:fs";
import { dirname, resolve } from "node:path";

const ARTIFACT_DIR = resolve(process.cwd(), "/opt/cursor/artifacts/screenshots");

const bildirim = {
  status: "BEKLENIYOR",
  status_label: "Bekleniyor",
  tamamlandi_mi: false,
  tamamlandi_at: null,
  tamamlayan_user_id: null,
  completion_id: null
};

const FABRIKA_PAYLOAD = {
  tarih: "2026-10-07",
  timezone: "Europe/Istanbul",
  workday_start: "08:30",
  on_time_deadline: "09:30",
  server_now: "2026-10-07T10:00:00+03:00",
  attention_count: 8,
  branches: [
    {
      sube_id: 1,
      sube_adi: "Medisa Fabrika",
      period_writable: true,
      counts: {
        toplam: 110,
        geldi: 50,
        gec_geldi: 12,
        erken_cikti: 10,
        gelmedi: 8,
        izinli: 10,
        raporlu: 5,
        gorevde: 2,
        henuz_degerlendirilmedi: 13
      },
      birim_bildirim: { tamamlanan: 20, toplam: 28 },
      units: [
        {
          birim_id: 101,
          birim_adi: "Demir",
          bolum_id: 10,
          bolum_adi: "Üretim",
          counts: {
            toplam: 4,
            geldi: 2,
            gec_geldi: 0,
            erken_cikti: 0,
            gelmedi: 1,
            izinli: 1,
            raporlu: 0,
            gorevde: 0,
            henuz_degerlendirilmedi: 0
          },
          bildirim,
          personeller: [
            {
              personel_id: 1001,
              ad_soyad: "Ali VELİ",
              durum: "IZINLI",
              durum_label: "İzinli",
              gec_kalma_dakika: null,
              erken_cikis_dakika: null,
              giris_saati: null,
              cikis_saati: null,
              aciklama: null,
              alt_tur: null,
              detail_line: "Yıllık izin",
              group: "PLANNED"
            },
            {
              personel_id: 1002,
              ad_soyad: "Ayşe YILMAZ",
              durum: "GELMEDI",
              durum_label: "Gelmedi",
              gec_kalma_dakika: null,
              erken_cikis_dakika: null,
              giris_saati: null,
              cikis_saati: null,
              aciklama: null,
              alt_tur: null,
              detail_line: "Gelmedi",
              group: "ABSENT"
            }
          ]
        },
        {
          birim_id: 102,
          birim_adi: "Depo",
          bolum_id: 11,
          bolum_adi: "Lojistik",
          counts: {
            toplam: 2,
            geldi: 1,
            gec_geldi: 1,
            erken_cikti: 0,
            gelmedi: 0,
            izinli: 0,
            raporlu: 0,
            gorevde: 0,
            henuz_degerlendirilmedi: 0
          },
          bildirim,
          personeller: [
            {
              personel_id: 1003,
              ad_soyad: "Mehmet DEMİR",
              durum: "GEC_GELDI",
              durum_label: "Geç Geldi",
              gec_kalma_dakika: 15,
              erken_cikis_dakika: null,
              giris_saati: "08:45",
              cikis_saati: null,
              aciklama: null,
              alt_tur: null,
              detail_line: "08:45 · 15 dk geç",
              group: "ACTUAL"
            }
          ]
        }
      ]
    }
  ]
};

async function routeBugun(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname.startsWith("/api/bildirimler/bugun-personel-durumu"),
    (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data: FABRIKA_PAYLOAD, meta: {}, errors: [] })
      })
  );
}

async function openFabrikaOverview(page: Page) {
  await mockApi(page, "GENEL_YONETICI");
  await routeBugun(page);
  await login(page, { username: "yonetici", password: "secret" });
  await page.getByTestId("bugun-personel-durumu-entry").click();
  const modal = page.locator(".modal-container--bugun-personel").last();
  await expect(modal.getByTestId("bugun-personel-durumu-title")).toHaveText("Anlık Personel Durumu");
  await modal.getByTestId("bugun-branch-1").click();
  return modal;
}

function saveShot(page: Page, filename: string) {
  mkdirSync(ARTIFACT_DIR, { recursive: true });
  return page.screenshot({ path: resolve(ARTIFACT_DIR, filename), fullPage: true });
}

test.describe("şube detayı sade personel özeti", () => {
  test("mobil — özet, gruplar ve drill-down", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const modal = await openFabrikaOverview(page);

    await expect(modal.getByTestId("bugun-branch-overview")).toBeVisible();
    await expect(modal.getByTestId("bugun-unit-101")).toHaveCount(0);
    await expect(modal.getByTestId("bugun-toplam-personel")).toHaveText("110");
    await expect(modal.getByTestId("bugun-gelen-count")).toHaveText("72");
    await expect(modal.getByTestId("bugun-gelmeyen-count")).toHaveText("25");
    await expect(modal.getByTestId("bugun-henuz-count")).toHaveText("13");
    await expect(modal.getByTestId("bugun-gelen")).toContainText("Gelen");
    await expect(modal.getByTestId("bugun-gelmeyen")).toContainText("Gelmeyen");

    await saveShot(page, "anlik-personel-ozet-mobil-fabrika.png");

    await modal.getByTestId("bugun-gelmeyen").click();
    await expect(modal.getByTestId("bugun-group-breakdown")).toBeVisible();
    await expect(modal.getByTestId("bugun-group-status-izinli")).toContainText("10");
    await expect(modal.getByTestId("bugun-group-status-gelmedi")).toContainText("8");
    await expect(modal.getByTestId("bugun-group-status-gec_geldi")).toHaveCount(0);

    await saveShot(page, "anlik-personel-ozet-mobil-gelmeyen-detay.png");

    await modal.getByTestId("bugun-group-status-izinli").click();
    await expect(modal.getByTestId("bugun-branch-status-roster")).toBeVisible();
    await expect(modal.getByTestId("bugun-person-1001")).toBeVisible();

    await modal.getByTestId("bugun-personel-durumu-back").click();
    await modal.getByTestId("bugun-personel-durumu-back").click();

    await modal.getByTestId("bugun-gelen").click();
    await expect(modal.getByTestId("bugun-group-breakdown")).toBeVisible();
    await saveShot(page, "anlik-personel-ozet-mobil-gelen-detay.png");
    await expect(modal.getByTestId("bugun-group-status-geldi")).toContainText("50");
    await expect(modal.getByTestId("bugun-group-status-gec_geldi")).toContainText("12");
    await expect(modal.getByTestId("bugun-group-status-erken_cikti")).toContainText("10");

    await modal.getByTestId("bugun-group-status-gec_geldi").click();
    await expect(modal.getByTestId("bugun-person-1003")).toBeVisible();

    await modal.getByTestId("bugun-personel-durumu-back").click();
    await modal.getByTestId("bugun-personel-durumu-back").click();

    await expect(modal.getByTestId("bugun-branch-overview")).toBeVisible();
    await modal.getByTestId("bugun-org-detay").click();
    await expect(modal.getByTestId("bugun-org-unit-list")).toBeVisible();
    await expect(modal.getByTestId("bugun-unit-101")).toBeVisible();
  });

  test("masaüstü — özet görünümü", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    const modal = await openFabrikaOverview(page);
    await expect(modal.getByTestId("bugun-toplam-personel")).toHaveText("110");
    await saveShot(page, "anlik-personel-ozet-desktop-fabrika.png");
  });
});
