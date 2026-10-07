/**
 * Geri satırı global geometri kontratı (tek sahip: modal.css `.modal-body > .universal-back-bar`).
 * Referans: Anlık Personel Durumu şube detayı (#511). Her ekranda sol ok
 * header'ın hemen altında aynı üst boşlukta ve aynı sol x'te başlar (±1px).
 */

import { expect, test, type Locator, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const BUGUN_PAYLOAD = {
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
        toplam: 1,
        geldi: 1,
        gec_geldi: 0,
        erken_cikti: 0,
        gelmedi: 0,
        izinli: 0,
        raporlu: 0,
        gorevde: 0,
        henuz_degerlendirilmedi: 0
      },
      birim_bildirim: { tamamlanan: 0, toplam: 1 },
      units: []
    }
  ]
};

type ArrowGeometry = { top: number; left: number };

async function measureArrow(back: Locator): Promise<ArrowGeometry> {
  await expect(back).toBeVisible();
  return back.evaluate((btn) => {
    const container = btn.closest(".modal-container") as HTMLElement;
    const header = container.querySelector(".modal-header") as HTMLElement;
    const arrow = btn.querySelector("svg") as SVGElement;
    const h = header.getBoundingClientRect();
    const c = container.getBoundingClientRect();
    const a = arrow.getBoundingClientRect();
    return { top: a.top - h.bottom, left: a.left - c.left };
  });
}

type Screen = { name: string; open: (page: Page) => Promise<Locator> };

const SCREENS: Screen[] = [
  {
    name: "Kullanıcı Yönetimi",
    open: async (page) => {
      await page.goto("/yonetim-paneli?tab=kullanicilar");
      return page.locator(".modal-container--yonetim").last().getByTestId("yonetim-back-ayarlar");
    }
  },
  {
    name: "Personel Kartı liste",
    open: async (page) => {
      await page.goto("/personeller");
      await page.getByTestId("personeller-scope-all").click();
      return page.getByTestId("personeller-internal-back");
    }
  },
  {
    name: "Personel Kartı detay",
    open: async (page) => {
      await page.goto("/personeller/1");
      return page.locator(".modal-container").last().locator(".universal-back-bar .universal-back-btn").first();
    }
  },
  {
    name: "Süreç Detayı",
    open: async (page) => {
      await page.goto("/surecler/1");
      return page.locator(".modal-container").last().locator(".universal-back-bar .universal-back-btn").first();
    }
  },
  {
    name: "Günlük Kayıt Detayı",
    open: async (page) => {
      await page.goto("/bildirimler/1");
      return page.locator(".modal-container").last().locator(".universal-back-bar .universal-back-btn").first();
    }
  },
  {
    name: "Toplu Kayıt Aktarma",
    open: async (page) => {
      await page.goto("/");
      await page.getByTestId("menu-kayit-surec").click();
      await page.getByTestId("kayit-bulk-import-link").click();
      return page.locator(".personel-import-dry-run-modal").last().getByTestId("personel-import-back-kayit");
    }
  }
];

for (const [label, width, height] of [
  ["desktop 1280", 1280, 800],
  ["mobil 390", 390, 844]
] as const) {
  test.describe(`geri satırı global hiza — ${label}`, () => {
    test.beforeEach(async ({ page }) => {
      await page.setViewportSize({ width, height });
      await mockApi(page, "GENEL_YONETICI");
      await page.route(
        (url) => url.pathname.startsWith("/api/bildirimler/bugun-personel-durumu"),
        (route) =>
          route.fulfill({
            status: 200,
            contentType: "application/json",
            body: JSON.stringify({ data: BUGUN_PAYLOAD, meta: {}, errors: [] })
          })
      );
      await login(page, { username: "yonetici", password: "secret" });
    });

    test("tüm ekranlar Anlık Personel referansıyla aynı üst boşluk ve sol x", async ({ page }) => {
      test.setTimeout(120_000);
      await page.getByTestId("bugun-personel-durumu-entry").click();
      const bugun = page.locator(".modal-container--bugun-personel").last();
      await bugun.getByTestId("bugun-branch-1").click();
      const reference = await measureArrow(bugun.getByTestId("bugun-personel-durumu-back"));
      // Referans: ok header'ın hemen altında (8px satır boşluğu + ortalanmış 28/44px kontrol), sola yaslı.
      expect(reference.top).toBeGreaterThanOrEqual(12);
      expect(reference.top).toBeLessThanOrEqual(14);

      const results: Record<string, ArrowGeometry> = { "Anlık Personel Durumu": reference };
      for (const screen of SCREENS) {
        const back = await screen.open(page);
        const geometry = await measureArrow(back);
        results[screen.name] = geometry;
        expect.soft(Math.abs(geometry.top - reference.top), `${screen.name} üst boşluk`).toBeLessThanOrEqual(1);
        expect.soft(Math.abs(geometry.left - reference.left), `${screen.name} sol x`).toBeLessThanOrEqual(1);
      }
      test.info().attach("back-bar-geometry", {
        body: JSON.stringify(results, null, 2),
        contentType: "application/json"
      });
    });
  });
}
