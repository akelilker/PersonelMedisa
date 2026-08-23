import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const LOGIN_TITLE = "Personel Yönetim Sistemi";
const LOGIN_TITLE_VISUAL = "PERSONEL YÖNETİM SİSTEMİ";
const MOBILE_VIEWPORTS = [
  { width: 430, height: 932 },
  { width: 390, height: 844 },
  { width: 375, height: 812 },
  { width: 360, height: 800 },
  { width: 320, height: 720 }
] as const;

async function assertNoHorizontalOverflow(page: Page) {
  const metrics = await page.evaluate(() => ({
    docScrollWidth: document.documentElement.scrollWidth,
    viewportWidth: window.innerWidth
  }));
  expect(metrics.docScrollWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);
}

async function assertLoginTitleParity(page: Page) {
  const title = page.locator("body.login-page .hero h1");
  await expect(title).toBeVisible();
  await expect(title).toHaveText(LOGIN_TITLE);

  const titleMetrics = await title.evaluate((el, expectedVisual) => {
    const style = getComputedStyle(el);
    const rect = el.getBoundingClientRect();
    const visualText = (el.textContent ?? "").toLocaleUpperCase("tr-TR");
    return {
      textOverflow: style.textOverflow,
      overflow: style.overflow,
      whiteSpace: style.whiteSpace,
      textTransform: style.textTransform,
      scrollWidth: el.scrollWidth,
      clientWidth: el.clientWidth,
      visible: rect.width > 0 && rect.height > 0,
      visualText
    };
  }, LOGIN_TITLE_VISUAL);

  expect(titleMetrics.visualText).toBe(LOGIN_TITLE_VISUAL);
  expect(titleMetrics.textOverflow).not.toBe("ellipsis");
  expect(titleMetrics.overflow).not.toBe("hidden");
  expect(titleMetrics.whiteSpace).toBe("nowrap");
  expect(titleMetrics.scrollWidth).toBeLessThanOrEqual(titleMetrics.clientWidth + 2);
  expect(titleMetrics.visible).toBe(true);
}

async function openKayitModal(page: Page) {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container--kayit-surec").last();
  await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  return kayitModal;
}

test.describe("mobile Taşıt parity — login", () => {
  for (const viewport of MOBILE_VIEWPORTS) {
    test(`login title full at ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.goto("/login");
      await assertNoHorizontalOverflow(page);
      await assertLoginTitleParity(page);
    });
  }
});

test.describe("mobile Taşıt parity — Kayıt modal", () => {
  for (const viewport of MOBILE_VIEWPORTS) {
    test(`Kayıt modal layout at ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await mockApi(page, "GENEL_YONETICI");
      await login(page, { username: "yonetici", password: "secret" });

      const kayitModal = await openKayitModal(page);
      await assertNoHorizontalOverflow(page);

      const metrics = await kayitModal.evaluate((modal) => {
        const footer = document.querySelector("#app-footer");
        if (!(footer instanceof HTMLElement)) {
          throw new Error("Missing #app-footer");
        }

        const modalBounds = modal.getBoundingClientRect();
        const footerBounds = footer.getBoundingClientRect();
        const columns = modal.querySelector(".personel-form-columns");
        const columnStyles = columns ? getComputedStyle(columns) : null;

        return {
          modalLeftEdgePx: modalBounds.left,
          modalRightEdgePx: window.innerWidth - modalBounds.right,
          modalFooterGapPx: footerBounds.top - modalBounds.bottom,
          modalColumnsMobile: columnStyles?.gridTemplateColumns ?? "",
          overflowX: getComputedStyle(document.documentElement).overflowX
        };
      });

      expect(metrics.modalLeftEdgePx).toBeGreaterThanOrEqual(-1);
      expect(metrics.modalLeftEdgePx).toBeLessThanOrEqual(1);
      expect(metrics.modalRightEdgePx).toBeGreaterThanOrEqual(-1);
      expect(metrics.modalRightEdgePx).toBeLessThanOrEqual(1);
      expect(metrics.modalFooterGapPx).toBeGreaterThan(0);
      expect(metrics.modalFooterGapPx).toBeCloseTo(6, 1);
      const columnTracks = metrics.modalColumnsMobile.trim().split(/\s+/);
      expect(columnTracks).toHaveLength(2);
      expect(columnTracks[0]).toBe(columnTracks[1]);
      expect(metrics.overflowX).not.toBe("scroll");

      await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();
    });
  }
});

test.describe("desktop regression — Kayıt modal alignment", () => {
  test("keeps 2-column grid, sicil left, and bottom row alignment", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openKayitModal(page);

    const desktopMetrics = await kayitModal.evaluate((modal) => {
      const columns = modal.querySelectorAll(".personel-form-column");
      const kan = modal.querySelector('[name="create-kan"]');
      const maas = modal.querySelector('[name="create-maas"]');
      const sicil = modal.querySelector('[name="create-sicil"]');
      const leftColumn = modal.querySelector(".personel-form-column:first-child");
      const columnsTemplate = getComputedStyle(modal.querySelector(".personel-form-columns") as Element)
        .gridTemplateColumns;

      const kanRect = kan?.getBoundingClientRect();
      const maasRect = maas?.getBoundingClientRect();

      return {
        columnCount: columns.length,
        sicilInLeft: Boolean(leftColumn?.contains(sicil)),
        bottomRowDelta: kanRect && maasRect ? Math.abs(kanRect.bottom - maasRect.bottom) : null,
        columnsTemplate,
        columnTracks: columnsTemplate.trim().split(/\s+/)
      };
    });

    expect(desktopMetrics.columnCount).toBe(2);
    expect(desktopMetrics.columnTracks).toHaveLength(2);
    expect(desktopMetrics.sicilInLeft).toBe(true);
    if (desktopMetrics.bottomRowDelta !== null) {
      expect(desktopMetrics.bottomRowDelta).toBeLessThanOrEqual(4);
    }
  });
});
