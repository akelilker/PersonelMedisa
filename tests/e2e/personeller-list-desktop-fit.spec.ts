import { expect, test } from "@playwright/test";

/** t849/t852: masaüstü liste modal içine sığar (11px eşit kenar), son kolon tam görünür, hücre ≤ 3 satır. */
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const D: [string, string, string, string, string, string, string][] = [
  ["34333541534","Abdul Kadir","KAN","Medisa Ankara","Finans ve Risk Yönetimi","Finans ve Risk Yönetimi","Finans ve Risk Yönetimi Genel Müdür Yardımcısı"],
  ["34283379188","Adem","AYDIN","Medisa Fabrika","Karabük Depo","Depo","Depo Ve Sevkiyat Elemanı"],
  ["41686937532","Ahmet","ÖZDEMİR","Medisa Giresun","Beyaz Eşya Bayi Satış","Beyaz Eşya Satış","Satış Temsilcisi"],
  ["13116084284","Barış","IŞIK","Medisa Fabrika","Döşeme Atölyesi","Demir","Üretim Elemanı"],
  ["11832125878","Bayram","OLKUN","Medisa Fabrika","Satış Sonrası Hizmetler","Satış Sonrası Hizmetler","Satış Sonrası Hizmetler Sorumlusu"],
  ["33152416252","Berat","GÜRBÜZ","Medisa Fabrika","Döşeme Atölyesi","Çakım","Üretim Elemanı"],
  ["39818196230","Birol","TABAK","Medisa Fabrika","Karabük Depo","Depo","Depo Ve Sevkiyat Elemanı"],
  ["48643128286","Bora","BAYAZIT","Medisa Kayseri","İhracat","İhracat","İhracat Müdürü"],
  ["53542736678","Cankan","ŞAHİN","Medisa Fabrika","Döşeme Atölyesi","Çakım","Üretim Elemanı"],
  ["11111111110","Cemal","YÜCEL","Medisa Ankara","Muhasebe","Muhasebe","Muhasebe Uzmanı"],
  ["22222222220","Derya","KOÇ","Medisa Giresun","Beyaz Eşya Bayi Satış","Beyaz Eşya Satış","Satış Temsilcisi"],
  ["33333333330","Emine","ARSLAN","Medisa Fabrika","Döşeme Atölyesi","Demir","Üretim Elemanı"]
];
const rows = D.map((r, i) => ({ id: 700 + i, tc_kimlik_no: r[0], ad: r[1], soyad: r[2], aktif_durum: "AKTIF", sube_id: 1 + (i % 4), sube_adi: r[3], bolum_id: i + 1, bolum_adi: r[4], birim_id: i + 1, birim_adi: r[5], gorev_id: i + 1, gorev_adi: r[6], personel_tipi_id: 1 + (i % 2), personel_tipi_adi: i % 2 ? "Mavi Yaka" : "Beyaz Yaka" }));

for (const [w, h] of [[1024, 505], [1280, 800]] as const) {
  test(`${w}: tablo modal içine sığar, STATÜ tam görünür, hücre en çok 3 satır`, async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await page.route(/\/api\/personeller(\?.*)?$/, async (route) => {
      if (route.request().method() !== "GET") return route.fallback();
      await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ data: { items: rows }, meta: { page: 1, limit: 100, total: rows.length, total_pages: 1, missing_personel_total: 0 }, errors: [] }) });
    });
    await login(page, { username: "yonetici", password: "secret" });
    await page.setViewportSize({ width: w, height: h });
    await page.goto("/personeller?view=list&scope=all");
    const wrap = page.getByTestId("personeller-dense-list");
    await expect(wrap.locator("tbody tr")).toHaveCount(rows.length);
    const m = await wrap.evaluate((el) => {
      const b = el.getBoundingClientRect();
      const modal = el.closest(".modal-container")!.getBoundingClientRect();
      const lastTh = el.querySelector("thead th:last-child") as HTMLElement;
      const range = document.createRange();
      range.selectNodeContents(lastTh);
      const text = range.getBoundingClientRect();
      const lastCells = Array.from(el.querySelectorAll<HTMLElement>("tbody td:last-child")).map((td) => td.getBoundingClientRect().right);
      const tall = Array.from(el.querySelectorAll<HTMLElement>("tbody td.personeller-cell-wrap > span"))
        .map((s) => ({ t: s.textContent, lines: Math.round(s.getBoundingClientRect().height / parseFloat(getComputedStyle(s).lineHeight)) }))
        .filter((x) => x.lines > 3);
      return {
        sw: el.scrollWidth, cw: el.clientWidth, gl: b.left - modal.left, gr: modal.right - b.right,
        thTextRight: text.right, thRight: lastTh.getBoundingClientRect().right, wrapRight: b.right,
        lastCellMaxRight: Math.max(...lastCells), tall
      };
    });
    expect(m.sw, "yatay taşma").toBeLessThanOrEqual(m.cw);
    expect(Math.abs(m.gl - 11), `sol kenar ${m.gl}`).toBeLessThanOrEqual(1);
    expect(Math.abs(m.gr - 11), `sağ kenar ${m.gr}`).toBeLessThanOrEqual(1);
    expect(m.thTextRight, "STATÜ başlığı kolonundan taşıyor").toBeLessThanOrEqual(m.thRight + 0.5);
    expect(m.thRight).toBeLessThanOrEqual(m.wrapRight + 0.5);
    expect(m.lastCellMaxRight).toBeLessThanOrEqual(m.wrapRight + 0.5);
    expect(m.tall, "3 satırı aşan Bölüm/Görev hücresi").toEqual([]);
    const yrd = wrap.locator('td[title="Finans ve Risk Yönetimi Genel Müdür Yardımcısı"] > span');
    await expect(yrd).toHaveText("Finans ve Risk Yönetimi Genel Müdür Yrd.");
  });
}
