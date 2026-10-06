import { mkdirSync } from "node:fs";
import { join } from "node:path";
import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const OUT = "/opt/cursor/artifacts/pr507-closure";

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

const BUGUN_BRANCHES_PAYLOAD = {
  tarih: "2024-05-02",
  timezone: "Europe/Istanbul",
  workday_start: "08:30",
  on_time_deadline: "09:00",
  server_now: "2024-05-02T09:30:00+03:00",
  attention_count: 5,
  branches: Array.from({ length: 8 }, (_, index) => ({
    sube_id: index + 1,
    sube_adi: `Şube ${index + 1}`,
    counts: statusCounts(1),
    period_writable: true,
    birim_bildirim: { tamamlanan: 0, toplam: 1 },
    units: []
  }))
};

async function routeBugunBranches(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname.startsWith("/api/bildirimler/bugun-personel-durumu"),
    (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data: BUGUN_BRANCHES_PAYLOAD, meta: {}, errors: [] })
      })
  );
}

test.beforeAll(() => {
  mkdirSync(OUT, { recursive: true });
});

test("PR507 desktop closure visuals @ 1280", async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await mockApi(page, "GENEL_YONETICI");
  await routeBugunBranches(page);
  await login(page, { username: "yonetici", password: "secret" });

  await page.getByTestId("bugun-personel-durumu-entry").click();
  const bugunModal = page.locator(".modal-container--bugun-personel").last();
  await expect(bugunModal.getByTestId("bugun-personel-durumu-title")).toHaveText("Anlık Personel Durumu");

  const firstCard = bugunModal.locator(".bugun-personel-branch-card").first();
  await expect(firstCard).toBeVisible();
  const borderAlpha = await firstCard.evaluate((el) => {
    const color = getComputedStyle(el).borderColor;
    const match = color.match(/rgba?\(([^)]+)\)/);
    if (!match) return 1;
    const parts = match[1].split(",").map((p) => p.trim());
    if (parts.length === 4) return Number.parseFloat(parts[3]);
    return 1;
  });
  expect(borderAlpha).toBeGreaterThan(0);

  const gridCols = await bugunModal.locator(".bugun-personel-branch-card").first().evaluate((el) => {
    const basis = getComputedStyle(el).flexBasis;
    return basis;
  });
  expect(gridCols).toContain("calc");

  await page.screenshot({ path: join(OUT, "desktop-anlik-root-grid-borders.png") });

  await bugunModal.locator(".bugun-personel-branch-card").first().click();
  await expect(bugunModal.getByTestId("bugun-personel-durumu-back")).toContainText("Anlık Personel Durumu");
  await expect(bugunModal.getByTestId("bugun-personel-durumu-back")).toBeVisible();
  await page.screenshot({ path: join(OUT, "desktop-anlik-sube-detail-back-row.png") });

  await bugunModal.locator(".modal-close-btn").click();
  await expect(bugunModal).toBeHidden();
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container--kayit-surec").last();
  await kayitModal.getByTestId("kayit-tab-surec").click();
  await kayitModal.getByTestId("kayit-surec-personel-search-toggle").click();
  await expect(kayitModal.getByTestId("kayit-surec-personel-panel-search")).toBeVisible();
  expect(await page.getByTestId("kayit-surec-personel-search-input").count()).toBe(0);
  await expect(kayitModal.getByTestId("kayit-surec-personel-panel-search")).toHaveAttribute(
    "placeholder",
    "Ad/Soyad Veya Sicil No. Girin."
  );
  const panel = page.locator('[data-app-select-panel="1"]');
  await expect(panel).toBeVisible();
  const optionAlign = await panel.locator(".app-select-option").first().evaluate((el) => getComputedStyle(el).textAlign);
  expect(optionAlign).toBe("center");
  await page.screenshot({ path: join(OUT, "desktop-kayit-surec-picker-single-search.png") });

  await page.screenshot({ path: join(OUT, "desktop-footer-wordmark.png"), clip: { x: 0, y: 820, width: 1280, height: 80 } });
});
