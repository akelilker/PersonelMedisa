/**
 * Anlık Personel Durumu — birim/bölüm aynı ad tekrarı (focused render kanıtı).
 *
 * Birim kartı her zaman birim adını gösterir; bölüm adı ikinci satır olarak
 * yalnız FARKLI olduğunda gösterilir. Bölüm adı birim adıyla aynıysa aynı metin
 * iki kez çizilmez. Üç durum gerçek render'da doğrulanır:
 *   1) birim == bölüm  → isim yalnız 1 kez
 *   2) birim != bölüm  → iki farklı bilgi de görünür
 *   3) bölüm boş       → yalnız birim görünür
 *
 * Yalnız bu ekranı ve mock'lanmış API yanıtını kapsar; gruplama, sayımlar ve
 * diğer ekranlar değişmez.
 */

import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const statusCounts = (toplam: number) => ({
  toplam,
  geldi: 0,
  gec_geldi: 0,
  gelmedi: 0,
  izinli: 0,
  raporlu: 0,
  gorevde: 0,
  erken_cikti: 0,
  henuz_degerlendirilmedi: toplam
});

const bildirim = {
  status: "BEKLENIYOR",
  status_label: "Bekleniyor",
  tamamlandi_mi: false,
  tamamlandi_at: null,
  tamamlayan_user_id: null,
  completion_id: null
};

const BUGUN_PAYLOAD = {
  tarih: "2024-05-02",
  timezone: "Europe/Istanbul",
  workday_start: "08:30",
  on_time_deadline: "09:30",
  server_now: "2024-05-02T09:00:00+03:00",
  attention_count: 0,
  branches: [
    {
      sube_id: 5,
      sube_adi: "Medisa Ankara",
      counts: statusCounts(6),
      period_writable: true,
      birim_bildirim: { tamamlanan: 0, toplam: 3 },
      units: [
        {
          birim_id: 16,
          birim_adi: "Finans ve Risk Yönetimi",
          bolum_id: 8,
          bolum_adi: "Finans ve Risk Yönetimi",
          counts: statusCounts(2),
          bildirim,
          personeller: []
        },
        {
          birim_id: 24,
          birim_adi: "Muhasebe",
          bolum_id: 8,
          bolum_adi: "Finans ve Risk Yönetimi",
          counts: statusCounts(2),
          bildirim,
          personeller: []
        },
        {
          birim_id: 22,
          birim_adi: "İdari İşler",
          bolum_id: null,
          bolum_adi: null,
          counts: statusCounts(2),
          bildirim,
          personeller: []
        }
      ]
    }
  ]
};

async function routeBugunPersonelDurumu(page: Page): Promise<void> {
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

test("birim adıyla aynı bölüm adı ikinci satır olarak tekrar gösterilmez", async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockApi(page, "GENEL_YONETICI");
  await routeBugunPersonelDurumu(page);
  await login(page, { username: "yonetici", password: "secret" });

  await page.getByTestId("bugun-personel-durumu-entry").click();
  const bugunModal = page.locator(".modal-container--bugun-personel").last();
  await expect(bugunModal.getByTestId("bugun-personel-durumu-title")).toHaveText("Anlık Personel Durumu");

  await bugunModal.getByTestId("bugun-branch-5").click();

  // 1) birim = bölüm → isim yalnız 1 kez görünür, ikinci satır yok.
  const duplicateUnit = bugunModal.getByTestId("bugun-unit-16");
  await expect(duplicateUnit).toBeVisible();
  await expect(duplicateUnit.locator(".bugun-personel-unit-head strong")).toHaveText("Finans ve Risk Yönetimi");
  await expect(
    duplicateUnit.getByText("Finans ve Risk Yönetimi", { exact: true })
  ).toHaveCount(1);
  await expect(duplicateUnit.locator(".bugun-personel-unit-bolum")).toHaveCount(0);

  // 2) birim != bölüm → iki farklı bilgi de görünür.
  const differentUnit = bugunModal.getByTestId("bugun-unit-24");
  await expect(differentUnit.locator(".bugun-personel-unit-head strong")).toHaveText("Muhasebe");
  await expect(differentUnit.locator(".bugun-personel-unit-bolum")).toHaveText("Finans ve Risk Yönetimi");

  // 3) bölüm boş → yalnız birim görünür.
  const noBolumUnit = bugunModal.getByTestId("bugun-unit-22");
  await expect(noBolumUnit.locator(".bugun-personel-unit-head strong")).toHaveText("İdari İşler");
  await expect(noBolumUnit.locator(".bugun-personel-unit-bolum")).toHaveCount(0);

  await page.screenshot({
    path: testInfo.outputPath("anlik-personel-durumu-bolum-dedupe.png"),
    fullPage: true
  });
});
