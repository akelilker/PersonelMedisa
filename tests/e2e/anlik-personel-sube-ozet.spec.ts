/**
 * Anlık Personel Durumu — şube özeti + yaka drill-down (mock API).
 */

import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";
import { mkdirSync } from "node:fs";
import { resolve } from "node:path";

const ARTIFACT_DIR = resolve(process.cwd(), "/opt/cursor/artifacts/screenshots");

const bildirim = {
  status: "BEKLENIYOR",
  status_label: "Bekleniyor",
  tamamlandi_mi: false,
  tamamlandi_at: null,
  tamamlayan_user_id: null,
  completion_id: null
};

/** Kişi satırları ile tutarlı şube sayımları (mock). */
const FABRIKA_PAYLOAD = {
  tarih: "2026-10-07",
  timezone: "Europe/Istanbul",
  workday_start: "08:30",
  on_time_deadline: "09:30",
  server_now: "2026-10-07T10:00:00+03:00",
  attention_count: 1,
  branches: [
    {
      sube_id: 1,
      sube_adi: "Medisa Fabrika",
      period_writable: true,
      counts: {
        toplam: 6,
        geldi: 2,
        gec_geldi: 1,
        erken_cikti: 0,
        gelmedi: 1,
        izinli: 1,
        raporlu: 0,
        gorevde: 0,
        henuz_degerlendirilmedi: 1
      },
      birim_bildirim: { tamamlanan: 1, toplam: 2 },
      units: [
        {
          birim_id: 101,
          birim_adi: "Demir",
          bolum_id: 10,
          bolum_adi: "Üretim",
          counts: {
            toplam: 4,
            geldi: 1,
            gec_geldi: 1,
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
              personel_tipi_ad: "Mavi Yaka",
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
              personel_tipi_ad: "Beyaz Yaka",
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
            },
            {
              personel_id: 1003,
              ad_soyad: "Mehmet DEMİR",
              personel_tipi_ad: "Mavi Yaka",
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
            },
            {
              personel_id: 1004,
              ad_soyad: "Zeynep KAYA",
              personel_tipi_ad: "Beyaz Yaka",
              durum: "GELDI",
              durum_label: "Geldi",
              gec_kalma_dakika: null,
              erken_cikis_dakika: null,
              giris_saati: "08:20",
              cikis_saati: null,
              aciklama: null,
              alt_tur: null,
              detail_line: "08:20",
              group: "ACTUAL"
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
            gec_geldi: 0,
            erken_cikti: 0,
            gelmedi: 0,
            izinli: 0,
            raporlu: 0,
            gorevde: 0,
            henuz_degerlendirilmedi: 1
          },
          bildirim,
          personeller: [
            {
              personel_id: 1005,
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
              detail_line: "Henüz değerlendirilmedi",
              group: "PENDING"
            },
            {
              personel_id: 1006,
              ad_soyad: "Deniz ARSLAN",
              personel_tipi_ad: "Mavi Yaka",
              durum: "GELDI",
              durum_label: "Geldi",
              gec_kalma_dakika: null,
              erken_cikis_dakika: null,
              giris_saati: "08:15",
              cikis_saati: null,
              aciklama: null,
              alt_tur: null,
              detail_line: "08:15",
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
  test("mobil — trio özet, yaka akışı, geri satırı", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const modal = await openFabrikaOverview(page);

    await expect(modal.getByTestId("bugun-branch-overview")).toBeVisible();
    await expect(modal.getByTestId("bugun-toplam-personel-card")).toBeVisible();
    await expect(modal.getByTestId("bugun-toplam-personel")).toHaveText("6");
    await expect(modal.getByTestId("bugun-gelen-count")).toHaveText("3");
    await expect(modal.getByTestId("bugun-gelmeyen-count")).toHaveText("2");
    await expect(modal.getByTestId("bugun-henuz-count")).toHaveText("1");
    await expect(modal.getByTestId("bugun-personel-durumu-back")).toBeVisible();

    await saveShot(page, "anlik-personel-ozet-mobil-fabrika.png");
    await saveShot(page, "anlik-personel-ozet-mobil-geri-satir.png");

    await modal.getByTestId("bugun-gelmeyen").click();
    await expect(modal.getByTestId("bugun-yaka-breakdown")).toBeVisible();
    await expect(modal.getByTestId("bugun-yaka-mavi")).toContainText("1");
    await expect(modal.getByTestId("bugun-yaka-beyaz")).toContainText("1");
    await expect(modal.getByTestId("bugun-yaka-statusuz")).toHaveCount(0);
    await expect(modal.locator(".bugun-personel-crumb")).toHaveCount(0);

    await saveShot(page, "anlik-personel-ozet-mobil-gelmeyen-yaka.png");

    await modal.getByTestId("bugun-yaka-mavi").click();
    await expect(modal.getByTestId("bugun-branch-roster")).toBeVisible();
    await expect(modal.getByText("Ali VELİ — İzinli")).toBeVisible();

    await saveShot(page, "anlik-personel-ozet-mobil-gelmeyen-mavi-liste.png");

    await modal.getByTestId("bugun-personel-durumu-back").click();
    await modal.getByTestId("bugun-personel-durumu-back").click();

    await modal.getByTestId("bugun-gelen").click();
    await expect(modal.getByTestId("bugun-yaka-mavi")).toContainText("2");
    await expect(modal.getByTestId("bugun-yaka-beyaz")).toContainText("1");

    await saveShot(page, "anlik-personel-ozet-mobil-gelen-yaka.png");

    await modal.getByTestId("bugun-yaka-mavi").click();
    await expect(modal.getByText("Mehmet DEMİR — Geç Geldi")).toBeVisible();
    await saveShot(page, "anlik-personel-ozet-mobil-gelen-mavi-liste.png");
  });

  test("masaüstü — trio özet ve yaka", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    const modal = await openFabrikaOverview(page);
    await expect(modal.getByTestId("bugun-overview-trio")).toBeVisible();
    await saveShot(page, "anlik-personel-ozet-desktop-fabrika.png");
    await modal.getByTestId("bugun-gelen").click();
    await expect(modal.getByTestId("bugun-yaka-breakdown")).toBeVisible();
    await saveShot(page, "anlik-personel-ozet-desktop-gelen-yaka.png");
  });
});
