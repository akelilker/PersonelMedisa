import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

/**
 * PERSONEL_LIST_MOBILE_SCROLL_DISCOVERABILITY_FINAL_CLOSE
 *
 * Mobilde liste yatay kaydırılabilir (personeller-table-wrap overflow-x:auto).
 * Bu spec yalnız sağ-devam göstergesini (edge fade) doğrular:
 *  - sağda içerik varken fade görünür
 *  - scroll sona geldiğinde fade kaybolur
 *  - fade tabloyu/scroll'u engellemez (pointer-events: none, hit-test tablo hücresi)
 *  - 1280'de fade yok, tablo geometrisi değişmemiş
 */

const SHELL = ".personeller-table-scroll-shell";
const DENSE_WRAP = '[data-testid="personeller-dense-list"]';

type Affordance = {
  scrollMore: string | null;
  afterContent: string;
  afterBackgroundImage: string;
  afterPointerEvents: string;
  afterWidth: string;
  scrollWidth: number;
  clientWidth: number;
  scrollLeft: number;
};

function fixtureItems() {
  return [
    {
      id: 901,
      tc_kimlik_no: "10012345678",
      ad: "Ahmet",
      soyad: "Özdemir",
      aktif_durum: "AKTIF",
      calisan_kapsami: "IC_PERSONEL",
      sube_id: 1,
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      birim_id: 10,
      bolum_id: 5,
      sube_adi: "Medisa Ankara",
      bolum_adi: "Muhasebe",
      birim_adi: "Finans",
      gorev_adi: "İK Uzmanı",
      personel_tipi_adi: "Beyaz Yaka",
      completeness: {
        is_complete: false,
        missing_count: 1,
        critical_missing_labels: ["Telefon"],
        missing_fields: [
          { key: "telefon", label: "Telefon", category: "ILETISIM", severity: "CRITICAL", edit_target: "genel" }
        ]
      }
    },
    {
      id: 902,
      tc_kimlik_no: "23456789012",
      ad: "Zeynep",
      soyad: "Kaya",
      aktif_durum: "AKTIF",
      calisan_kapsami: "IC_PERSONEL",
      sube_id: 2,
      departman_id: 6,
      gorev_id: 10,
      personel_tipi_id: 2,
      birim_id: 20,
      bolum_id: 6,
      sube_adi: "Medisa Giresun",
      bolum_adi: "Satış",
      birim_adi: "Pazarlama",
      gorev_adi: "Satış Temsilcisi",
      personel_tipi_adi: "Mavi Yaka",
      completeness: { is_complete: true, missing_count: 0, critical_missing_labels: [] }
    }
  ];
}

async function installFixture(page: Page) {
  await page.route("**/api/personeller**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() !== "GET" || url.pathname !== "/api/personeller") {
      await route.fallback();
      return;
    }

    const items = fixtureItems();
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        data: { items },
        meta: { page: 1, limit: 10, total: items.length, total_pages: 1, missing_personel_total: 1 },
        errors: []
      })
    });
  });
}

async function openList(page: Page, options: { scopeAll: boolean; subeId?: number | null }) {
  if (!options.scopeAll) {
    await page.evaluate((id) => {
      const key = "medisa_auth_session";
      const fromSession = sessionStorage.getItem(key);
      const storage = fromSession ? sessionStorage : localStorage;
      const raw = fromSession ?? localStorage.getItem(key);
      if (!raw) {
        return;
      }
      const session = JSON.parse(raw) as { active_sube_id?: number | null };
      session.active_sube_id = id;
      storage.setItem(key, JSON.stringify(session));
    }, options.subeId ?? 1);
  }

  await page.goto(options.scopeAll ? "/personeller?view=list&scope=all" : "/personeller?view=list");
  const wrap = page.locator(DENSE_WRAP);
  await expect(wrap).toBeVisible();
  await expect(wrap.locator("tbody tr").first()).toBeVisible();
  return wrap;
}

async function readAffordance(page: Page): Promise<Affordance> {
  return page.locator(SHELL).evaluate((shell) => {
    const wrap = shell.querySelector<HTMLElement>(".personeller-table-wrap");
    const after = getComputedStyle(shell, "::after");
    return {
      scrollMore: shell.getAttribute("data-scroll-more"),
      afterContent: after.content,
      afterBackgroundImage: after.backgroundImage,
      afterPointerEvents: after.pointerEvents,
      afterWidth: after.width,
      scrollWidth: wrap ? wrap.scrollWidth : 0,
      clientWidth: wrap ? wrap.clientWidth : 0,
      scrollLeft: wrap ? wrap.scrollLeft : 0
    };
  });
}

/**
 * Onaylı karar (2026-10-12, t853–t856): ekranlar yalnız dikey kayar. Mobilde tüm kolonlar ekrana
 * sığar; yatay kaydırma ve "devam var" fade'i yoktur. Görev, Bölüm/Birim hücresinde ikinci satırdır.
 * Soyad hiçbir zaman bölünmez/sarılmaz.
 */
async function expectNoHorizontalScroll(page: Page, label: string) {
  const affordance = await readAffordance(page);
  expect(affordance.scrollWidth, `${label}: tablo yatay taşıyor`).toBeLessThanOrEqual(affordance.clientWidth + 1);
  expect(affordance.scrollMore, `${label}: sağ devam bayrağı true`).toBe("false");
  expect(affordance.afterContent, `${label}: fade görünmemeli`).toBe("none");
  const fit = await page.getByTestId("personeller-dense-list").evaluate((el) => {
    const wrap = el.getBoundingClientRect();
    const page = el.closest(".personeller-page")!.getBoundingClientRect();
    const visibleRight = Math.min(wrap.right, page.right);
    const cells = Array.from(el.querySelectorAll<HTMLElement>("thead th, tbody td")).filter(
      (c) => getComputedStyle(c).display !== "none"
    );
    const overflowing = cells.filter((c) => c.getBoundingClientRect().right > visibleRight + 0.5).length;
    const soyad = Array.from(el.querySelectorAll<HTMLElement>(".personeller-name-soyad")).filter((s) => {
      const lh = parseFloat(getComputedStyle(s).lineHeight) || parseFloat(getComputedStyle(s).fontSize) * 1.3;
      return s.getBoundingClientRect().height > lh * 1.5;
    }).length;
    const gorevInBolum = el.querySelectorAll(".personeller-mobil-gorev").length;
    const visibleGorev = Array.from(el.querySelectorAll<HTMLElement>(".personeller-mobil-gorev")).filter(
      (g) => getComputedStyle(g).display !== "none"
    ).length;
    return { overflowing, soyad, gorevInBolum, visibleGorev };
  });
  expect(fit.overflowing, `${label}: görünür alanın dışında kalan hücre var`).toBe(0);
  expect(fit.soyad, `${label}: Soyad sarıldı`).toBe(0);
  expect(fit.visibleGorev, `${label}: Görev, Bölüm/Birim hücresinde görünmüyor`).toBe(fit.gorevInBolum);
}

test.describe("personel listesi mobil: yalnız dikey kaydırma", () => {
  for (const viewport of [
    { width: 390, height: 844 },
    { width: 375, height: 667 },
    { width: 430, height: 932 }
  ] as const) {
    test(`${viewport.width} TÜMÜ: yatay kaydırma yok, tüm kolonlar sığar, Soyad tek satır`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await mockApi(page, "GENEL_YONETICI");
      await installFixture(page);
      await login(page, { username: "yonetici", password: "secret" });

      const wrap = await openList(page, { scopeAll: true });
      await expectNoHorizontalScroll(page, `${viewport.width} TÜMÜ`);
      await expect(wrap.locator(".personeller-tc-value").first()).toHaveText("10012345678");
    });
  }

  test("390 ŞUBE: Şube kolonu yok, yatay kaydırma yok", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockApi(page, "GENEL_YONETICI");
    await installFixture(page);
    await login(page, { username: "yonetici", password: "secret" });

    const wrap = await openList(page, { scopeAll: false, subeId: 1 });
    await expect(wrap.locator("thead th")).toHaveCount(5);
    await expectNoHorizontalScroll(page, "390 ŞUBE");
  });

  test("1280 regression: fade yok, tablo görünümü değişmemiş", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await installFixture(page);
    await login(page, { username: "yonetici", password: "secret" });

    const wrap = await openList(page, { scopeAll: true });
    await expect(wrap.locator("thead th")).toHaveCount(6);

    const affordance = await readAffordance(page);
    expect(affordance.scrollWidth, "1280: tablo beklenmedik şekilde yatay taşıyor").toBeLessThanOrEqual(
      affordance.clientWidth + 1
    );
    expect(affordance.scrollMore, "1280: sağ devam bayrağı true").toBe("false");
    expect(affordance.afterContent, "1280: fade görünmemeli").toBe("none");

    // Geometri korunmuş: T.C. tam, hücreler yatay center, satır ritmi aynı.
    await expect(wrap.locator(".personeller-tc-value").first()).toHaveText("10012345678");
    const geometry = await wrap.evaluate((el) => {
      const row = el.querySelector("tbody tr") as HTMLTableRowElement;
      const cell = row.querySelector("td") as HTMLTableCellElement;
      const cellStyle = getComputedStyle(cell);
      return {
        rowHeight: row.getBoundingClientRect().height,
        textAlign: cellStyle.textAlign,
        cellMinHeight: parseFloat(cellStyle.height)
      };
    });
    expect(geometry.rowHeight).toBeGreaterThanOrEqual(31.5);
    expect(geometry.rowHeight, "1280: satır ritmi bozuldu").toBeLessThanOrEqual(48);
    expect(geometry.textAlign).toBe("center");
    expect(geometry.cellMinHeight, "1280: hücre minimum yüksekliği korunmadı").toBeGreaterThanOrEqual(32);
  });
});

