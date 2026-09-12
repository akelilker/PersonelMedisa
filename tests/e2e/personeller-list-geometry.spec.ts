import { expect, test, type Locator, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

/**
 * PERSONEL_LIST_TASIT_TABLE_GEOMETRY_PARITY_FINAL
 *
 * Taşıt listesi referans geometrisi (tasitmedisa / tasitlar-base.css):
 *  - .view-list .list-item       → min-height 32px, border-bottom rgba(255,255,255,.08)
 *  - .view-list .list-cell       → font-size 12px, line-height 1.3, padding 2px 4px,
 *                                  display:flex; align-items:center; justify-content:center
 *  - .list-header-row .list-cell → font-size 11px, font-weight 600, justify-content:center
 *  - zebra rgba(255,255,255,.05) / hover --border-ultra = rgba(255,255,255,.1)
 *
 * Personel dense listesi bu ritme yaklaşmalı: 32px nefesli satır, body hücreleri
 * kolonunda yatay center, tek satır → sığmazsa 2 satır → ancak gerçekten taşarsa ellipsis.
 */

const DENSE_WRAP = '[data-testid="personeller-dense-list"]';
const TASIT_ROW_TARGET = 32;

type CellMetrics = {
  index: number;
  text: string;
  centerX: number;
  contentCenterX: number;
  width: number;
  contentWidth: number;
  textAlign: string;
  contentHeight: number;
  lineHeight: number;
  clampOverflow: number;
  clampLines: number;
};

type RowMetrics = {
  height: number;
  cells: CellMetrics[];
};

type HeaderMetrics = {
  text: string;
  centerX: number;
  contentCenterX: number;
  textAlign: string;
};

type Geometry = {
  headers: HeaderMetrics[];
  rows: RowMetrics[];
  wrapScrollWidth: number;
  wrapClientWidth: number;
  tableWidth: number;
  docOverflow: number;
};

function fixturePersoneller() {
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
      sicil_no: "P-901",
      ise_giris_tarihi: "2023-02-01",
      dogum_tarihi: "1992-03-14",
      telefon: "05550000000",
      sube_adi: "Medisa Ankara",
      bolum_adi: "Muhasebe",
      birim_adi: "Finans",
      gorev_adi: "İK Uzmanı",
      personel_tipi_adi: "Beyaz Yaka",
      completeness: {
        is_complete: false,
        missing_count: 2,
        critical_missing_labels: ["Telefon", "İşe Giriş Tarihi"],
        missing_fields: [
          { key: "telefon", label: "Telefon", category: "ILETISIM", severity: "CRITICAL", edit_target: "genel" },
          {
            key: "ise_giris_tarihi",
            label: "İşe Giriş Tarihi",
            category: "ISTIHDAM",
            severity: "CRITICAL",
            edit_target: "genel"
          }
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
      sicil_no: "P-902",
      ise_giris_tarihi: "2024-07-15",
      sube_adi: "Medisa Giresun",
      bolum_adi: "Satış",
      birim_adi: "Pazarlama",
      gorev_adi: "Satış Temsilcisi",
      personel_tipi_adi: "Mavi Yaka",
      completeness: { is_complete: true, missing_count: 0, critical_missing_labels: [] }
    },
    {
      id: 903,
      tc_kimlik_no: "34567890123",
      ad: "Abdurrahman",
      soyad: "Şahinoğlu",
      aktif_durum: "AKTIF",
      calisan_kapsami: "IC_PERSONEL",
      sube_id: 1,
      departman_id: 3,
      gorev_id: 8,
      personel_tipi_id: 1,
      birim_id: 11,
      bolum_id: 5,
      sicil_no: "P-903",
      ise_giris_tarihi: "2021-05-03",
      sube_adi: "Medisa Ankara",
      bolum_adi: "Üretim Planlama",
      birim_adi: "Lojistik Operasyon",
      gorev_adi: "Kıdemli Üretim Planlama Uzmanı",
      personel_tipi_adi: "Mavi Yaka",
      completeness: { is_complete: true, missing_count: 0, critical_missing_labels: [] }
    }
  ];
}


/** List endpoint'ini geometri fixture'ı ile besle; diğer /api çağrıları mock-api'ye düşsün. */
async function installPersonelGeometryFixture(page: Page) {
  await page.route("**/api/personeller**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() !== "GET" || url.pathname !== "/api/personeller") {
      await route.fallback();
      return;
    }

    const items = fixturePersoneller();
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        data: { items },
        meta: {
          page: 1,
          limit: 10,
          total: items.length,
          total_pages: 1,
          missing_personel_total: 1
        },
        errors: []
      })
    });
  });
}

async function openDenseList(page: Page, scopeAll: boolean): Promise<Locator> {
  await page.goto(scopeAll ? "/personeller?view=list&scope=all" : "/personeller?view=list");
  const wrap = page.locator(DENSE_WRAP);
  await expect(wrap).toBeVisible();
  await expect(wrap.locator("tbody tr").first()).toBeVisible();
  return wrap;
}

/** Şube scope'unu sabitle: active_sube_id null değilse Şube kolonu görünmez. */
async function setActiveSube(page: Page, subeId: number | null) {
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
  }, subeId);
}

async function measure(wrap: Locator): Promise<Geometry> {
  return wrap.evaluate((wrapEl) => {
    const table = wrapEl.querySelector("table") as HTMLTableElement;
    const headerEls = Array.from(table.querySelectorAll("thead th"));
    const rowEls = Array.from(table.querySelectorAll("tbody tr"));

    const contentBox = (el: Element) => {
      const range = document.createRange();
      range.selectNodeContents(el);
      return range.getBoundingClientRect();
    };
    const centerXOf = (rect: DOMRect) => rect.left + rect.width / 2;

    const headers = headerEls.map((th) => {
      const rect = th.getBoundingClientRect();
      const content = contentBox(th);
      return {
        text: (th.textContent ?? "").trim(),
        centerX: centerXOf(rect),
        contentCenterX: content.width > 0 ? centerXOf(content) : centerXOf(rect),
        textAlign: getComputedStyle(th).textAlign
      };
    });

    const rows = rowEls.map((row) => ({
      height: row.getBoundingClientRect().height,
      cells: Array.from(row.querySelectorAll("td")).map((cell, index) => {
        const rect = cell.getBoundingClientRect();
        const content = contentBox(cell);
        const style = getComputedStyle(cell);
        const clampTarget =
          (cell.querySelector(".personeller-cell-wrap > span") as HTMLElement | null) ??
          (cell.querySelector(".personeller-name-primary") as HTMLElement | null);
        return {
          index,
          text: (cell.textContent ?? "").trim(),
          centerX: centerXOf(rect),
          contentCenterX: content.width > 0 ? centerXOf(content) : centerXOf(rect),
          width: rect.width,
          contentWidth: content.width,
          textAlign: style.textAlign,
          contentHeight: content.height,
          lineHeight: parseFloat(style.lineHeight),
          clampOverflow: clampTarget ? clampTarget.scrollHeight - clampTarget.clientHeight : 0,
          clampLines: clampTarget
            ? clampTarget.clientHeight / (parseFloat(getComputedStyle(clampTarget).lineHeight) || parseFloat(style.lineHeight))
            : content.height / parseFloat(style.lineHeight)
        };
      })
    }));

    const tableRect = table.getBoundingClientRect();
    return {
      headers,
      rows,
      wrapScrollWidth: wrapEl.scrollWidth,
      wrapClientWidth: wrapEl.clientWidth,
      tableWidth: tableRect.width,
      docOverflow: document.documentElement.scrollWidth - window.innerWidth
    };
  });
}


type CellExpectation = "readable" | "clamped";

/** "readable": değer 2 satır içinde tam okunmalı (ellipsis yok). "clamped": uzun değer 2 satırda kesilmeli. */
const TUMU_EXPECTATIONS: CellExpectation[][] = [
  ["readable", "readable", "readable", "readable", "readable", "readable"],
  ["readable", "readable", "readable", "readable", "readable", "readable"],
  ["readable", "readable", "readable", "clamped", "clamped", "readable"]
];

const BRANCH_EXPECTATIONS: CellExpectation[][] = [
  ["readable", "readable", "readable", "readable", "readable"],
  ["readable", "readable", "readable", "readable", "readable"],
  ["readable", "readable", "clamped", "clamped", "readable"]
];

function assertCenteredRow(
  row: RowMetrics,
  headers: HeaderMetrics[],
  rowLabel: string,
  expectations: CellExpectation[]
) {
  expect(row.height, `${rowLabel}: satır yüksekliği Taşıt ritminin altında`).toBeGreaterThanOrEqual(
    TASIT_ROW_TARGET - 0.5
  );

  row.cells.forEach((cell) => {
    const header = headers[cell.index];
    const expectation = expectations[cell.index] ?? "readable";
    expect(header, `${rowLabel}: ${cell.index}. hücre için header yok`).toBeTruthy();
    expect(cell.textAlign, `${rowLabel}: "${cell.text}" hücresi yatay center değil`).toBe("center");
    expect(
      Math.abs(cell.centerX - header.centerX),
      `${rowLabel}: "${cell.text}" hücresi kendi kolonunun dışında`
    ).toBeLessThanOrEqual(1.5);

    // İçerik hücreye sığıyorsa tam merkezde olmalı (sığmıyorsa zaten kolon genişliği yetersizdir).
    if (cell.contentWidth <= cell.width - 2) {
      expect(
        Math.abs(cell.contentCenterX - cell.centerX),
        `${rowLabel}: "${cell.text}" içeriği hücre merkezinde değil`
      ).toBeLessThanOrEqual(3);
    }

    if (expectation === "clamped") {
      expect(cell.clampOverflow, `${rowLabel}: "${cell.text}" 2 satırda kesilmedi`).toBeGreaterThan(1);
      expect(
        cell.clampLines,
        `${rowLabel}: "${cell.text}" görünen satır sayısı 2'yi aştı`
      ).toBeLessThanOrEqual(2.05);
    } else {
      expect(
        cell.clampOverflow,
        `${rowLabel}: "${cell.text}" okunabilir olmalıydı ama ellipsis'e düştü`
      ).toBeLessThanOrEqual(1);
    }
  });
}


test.describe("personel listesi tablo geometrisi (Taşıt parity)", () => {
  test("1280 TÜMÜ: satır ritmi, center hizalama, 2 satır fallback, T.C. + !", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await installPersonelGeometryFixture(page);
    await login(page, { username: "yonetici", password: "secret" });

    const wrap = await openDenseList(page, true);
    const geometry = await measure(wrap);

    test.info().annotations.push({
      type: "geometry",
      description: JSON.stringify({
        headers: geometry.headers.map((h) => ({ text: h.text, align: h.textAlign })),
        rowHeights: geometry.rows.map((r) => Number(r.height.toFixed(2))),
        cellLines: geometry.rows.map((r) =>
          r.cells.map((c) => Number((c.contentHeight / c.lineHeight).toFixed(2)))
        ),
        cellWidths: geometry.rows.map((r) => r.cells.map((c) => Number(c.width.toFixed(2)))),
        contentWidths: geometry.rows.map((r) => r.cells.map((c) => Number(c.contentWidth.toFixed(2)))),
        wrapClientWidth: geometry.wrapClientWidth,
        wrapScrollWidth: geometry.wrapScrollWidth
      })
    });

    // Header: T.C. Kimlik, Ad Soyad, Şube, Bölüm / Birim, Görev, Statü — hepsi center.
    expect(geometry.headers.map((h) => h.text)).toEqual([
      "T.C. Kimlik",
      "Ad Soyad",
      "Şube",
      "Bölüm / Birim",
      "Görev",
      "Statü"
    ]);
    geometry.headers.forEach((header) => {
      expect(header.textAlign, `header "${header.text}" center değil`).toBe("center");
      expect(
        Math.abs(header.contentCenterX - header.centerX),
        `header "${header.text}" kolon merkezinde değil`
      ).toBeLessThanOrEqual(3);
    });

    // Body: tüm satırlar Taşıt ritminde ve hücreler kendi kolonlarında center.
    expect(geometry.rows).toHaveLength(3);
    geometry.rows.forEach((row, index) =>
      assertCenteredRow(row, geometry.headers, `satır ${index + 1}`, TUMU_EXPECTATIONS[index] ?? [])
    );

    // T.C. 11 hane tam okunuyor (maskeli gösterim yok).
    const tcValue = wrap.locator("tbody tr").first().locator(".personeller-tc-value");
    await expect(tcValue).toHaveText("10012345678");
    expect(await tcValue.textContent(), "T.C. maskelenmiş görünüyor").not.toContain("*");
    expect(await tcValue.textContent()).toMatch(/^\d{11}$/);
    expect(await tcValue.evaluate((el) => el.scrollWidth <= el.clientWidth + 1), "T.C. değeri kırpılıyor").toBe(true);

    // T.C. + ! aynı hücrenin içinde kalıyor (komşu kolona taşma yok).
    const tcFit = await wrap
      .locator("tbody tr")
      .first()
      .locator("td.personeller-tc-cell")
      .evaluate((cell) => {
        const range = document.createRange();
        range.selectNodeContents(cell);
        const content = range.getBoundingClientRect();
        const rect = cell.getBoundingClientRect();
        return { leftSlack: content.left - rect.left, rightSlack: rect.right - content.right };
      });
    expect(tcFit.leftSlack, "T.C. hücresi soldan taşıyor").toBeGreaterThanOrEqual(-0.5);
    expect(tcFit.rightSlack, "T.C. + ! hücresi sağdan komşu kolona taşıyor").toBeGreaterThanOrEqual(-0.5);

    // ! sade: çerçeve yok, zemin yok, kırmızı, T.C. ile aynı hücre merkezinde.
    const bang = wrap.locator("tbody tr").first().locator(".personeller-missing-bang");
    await expect(bang).toHaveText("!");
    const bangStyle = await bang.evaluate((el) => {
      const style = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      const cell = el.closest("td");
      const cellRect = cell ? cell.getBoundingClientRect() : null;
      return {
        borderTop: style.borderTopWidth,
        borderRight: style.borderRightWidth,
        borderBottom: style.borderBottomWidth,
        borderLeft: style.borderLeftWidth,
        background: style.backgroundColor,
        color: style.color,
        centerY: rect.top + rect.height / 2,
        cellCenterY: cellRect ? cellRect.top + cellRect.height / 2 : null
      };
    });
    expect(bangStyle.borderTop).toBe("0px");
    expect(bangStyle.borderRight).toBe("0px");
    expect(bangStyle.borderBottom).toBe("0px");
    expect(bangStyle.borderLeft).toBe("0px");
    expect(bangStyle.background === "rgba(0, 0, 0, 0)" || bangStyle.background === "transparent").toBe(true);
    expect(bangStyle.color).toBe("rgb(239, 68, 68)");
    expect(Math.abs((bangStyle.cellCenterY ?? 0) - bangStyle.centerY)).toBeLessThanOrEqual(3);

    // Statü okunur (2 satır olabilir ama ellipsis yok) ve değerler korunur.
    await expect(wrap.locator("tbody tr td:last-child")).toHaveText(["Beyaz Yaka", "Mavi Yaka", "Mavi Yaka"]);

    // Sağdan kırpma yok.
    expect(geometry.wrapScrollWidth, "liste kutusu yatayda taşıyor (sağ clipping)").toBeLessThanOrEqual(
      geometry.wrapClientWidth + 1
    );
    expect(geometry.docOverflow, "sayfa yatayda taşıyor").toBeLessThanOrEqual(1);
  });


  test("1280 TÜMÜ: zebra ve hover Taşıt seviyesinde ayrışır", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await installPersonelGeometryFixture(page);
    await login(page, { username: "yonetici", password: "secret" });

    const wrap = await openDenseList(page, true);
    const rows = wrap.locator("tbody tr");
    const rowBg = (index: number) =>
      rows.nth(index).locator("td").first().evaluate((el) => getComputedStyle(el).backgroundColor);

    const odd = await rowBg(0);
    const even = await rowBg(1);
    expect(odd === "rgba(0, 0, 0, 0)" || odd === "transparent").toBe(true);
    expect(even, "zebra ayrımı yok").toBe("rgba(255, 255, 255, 0.08)");
    expect(odd, "zebra ayrımı yok").not.toBe(even);

    await rows.nth(0).hover();
    const oddHover = await rowBg(0);
    await rows.nth(1).hover();
    const evenHover = await rowBg(1);

    expect(oddHover, "hover satırı aydınlatmıyor").toBe("rgba(255, 255, 255, 0.12)");
    expect(evenHover, "hover satırı aydınlatmıyor").toBe("rgba(255, 255, 255, 0.16)");
    expect(evenHover, "hover zebra farkını siliyor").not.toBe(oddHover);
  });

  test("1280 branch görünümü: Şube kolonu yok, kalan kolonlar alanı kullanır", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await installPersonelGeometryFixture(page);
    await login(page, { username: "yonetici", password: "secret" });
    await setActiveSube(page, 1);

    const wrap = await openDenseList(page, false);
    const geometry = await measure(wrap);

    expect(geometry.headers.map((h) => h.text)).toEqual([
      "T.C. Kimlik",
      "Ad Soyad",
      "Bölüm / Birim",
      "Görev",
      "Statü"
    ]);
    geometry.rows.forEach((row, index) => {
      expect(row.cells).toHaveLength(5);
      assertCenteredRow(row, geometry.headers, "branch satırı", BRANCH_EXPECTATIONS[index] ?? []);
    });
    // Kolonlar genişleyen alanı kullanıyor: tablo tam genişlik, yatay taşma yok.
    expect(geometry.tableWidth).toBeGreaterThanOrEqual(geometry.wrapClientWidth - 1);
    expect(geometry.wrapScrollWidth).toBeLessThanOrEqual(geometry.wrapClientWidth + 1);
  });

  for (const viewport of [
    { width: 430, height: 932 },
    { width: 390, height: 844 }
  ] as const) {
    test(`${viewport.width}: responsive regression ve overflow yok`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await mockApi(page, "GENEL_YONETICI");
      await installPersonelGeometryFixture(page);
      await login(page, { username: "yonetici", password: "secret" });

      const wrap = await openDenseList(page, true);
      const geometry = await measure(wrap);

      expect(geometry.headers).toHaveLength(6);
      geometry.headers.forEach((header) => {
        expect(header.textAlign, `${viewport.width}: header "${header.text}" center değil`).toBe("center");
      });
      geometry.rows.forEach((row, index) => {
        assertCenteredRow(row, geometry.headers, `${viewport.width} satır ${index + 1}`, TUMU_EXPECTATIONS[index] ?? []);
      });

      // Mobil kontrat: tablo doğal genişliğinde + sarmalayıcı yatay kaydırır; T.C. 11 hane tam.
      expect(geometry.tableWidth, `${viewport.width}: tablo sıkıştırılmış`).toBeGreaterThanOrEqual(519);
      expect(
        geometry.wrapScrollWidth,
        `${viewport.width}: yatay kaydırma yok`
      ).toBeGreaterThan(geometry.wrapClientWidth);

      const tcValue = wrap.locator("tbody tr").first().locator(".personeller-tc-value");
      await expect(tcValue).toHaveText("10012345678");
      expect(await tcValue.textContent(), `${viewport.width}: T.C. maskelenmiş`).not.toContain("*");

      // T.C. + ! kendi hücresinin içinde (komşu kolonla çakışma yok).
      const tcFit = await wrap
        .locator("tbody tr")
        .first()
        .locator("td.personeller-tc-cell")
        .evaluate((cell) => {
          const range = document.createRange();
          range.selectNodeContents(cell);
          const content = range.getBoundingClientRect();
          const rect = cell.getBoundingClientRect();
          return { leftSlack: content.left - rect.left, rightSlack: rect.right - content.right };
        });
      expect(tcFit.leftSlack, `${viewport.width}: T.C. hücresi soldan taşıyor`).toBeGreaterThanOrEqual(-0.5);
      expect(tcFit.rightSlack, `${viewport.width}: T.C. + ! komşu kolona taşıyor`).toBeGreaterThanOrEqual(-0.5);

      // Yatay kaydırma sonunda da sayfa taşmıyor (kaydırma sarmalayıcı içinde).
      await wrap.evaluate((el) => {
        el.scrollLeft = el.scrollWidth;
      });
      const docOverflowAfterScroll = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth
      );
      expect(docOverflowAfterScroll, `${viewport.width}: kaydırma sonrası sayfa yatayda taşıyor`).toBeLessThanOrEqual(1);
      expect(geometry.docOverflow, `${viewport.width}: sayfa yatayda taşıyor`).toBeLessThanOrEqual(1);
    });
  }
});

