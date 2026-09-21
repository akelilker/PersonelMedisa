import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

/**
 * Home (MainMenu) mobile contract:
 * - the document never gains horizontal scroll / overflow
 * - the header band and all three main-menu cards stay inside the viewport
 * - the footer is a full-width band pinned to the viewport bottom, without the
 *   desktop frame side rails (Taşıt style-core ≤640 parity)
 *
 * The footer's safe-area growth (env(safe-area-inset-bottom)) and the viewport
 * meta contract behind it cannot be exercised from an emulated viewport, where
 * env() always resolves to 0px — those are covered by the rendered-geometry
 * checks at 0px inset.
 */

const MOBILE_VIEWPORTS = [
  { width: 390, height: 844 },
  { width: 360, height: 800 }
] as const;

const DESKTOP_VIEWPORT = { width: 1280, height: 900 } as const;
const SHELL_MAX_WIDTH = 500;
const EDGE_TOLERANCE = 1;

async function openHome(page: Page) {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "yonetici", password: "secret" });
  await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
}

async function assertNoHorizontalOverflow(page: Page) {
  const metrics = await page.evaluate(() => {
    const root = document.documentElement;
    root.scrollLeft = 0;
    return {
      docScrollWidth: root.scrollWidth,
      docClientWidth: root.clientWidth,
      bodyScrollWidth: document.body.scrollWidth,
      maxScrollLeftAttempt: (() => {
        root.scrollLeft = 200;
        const value = root.scrollLeft;
        root.scrollLeft = 0;
        return value;
      })(),
      viewportWidth: window.innerWidth
    };
  });

  expect(metrics.docScrollWidth).toBeLessThanOrEqual(metrics.viewportWidth + EDGE_TOLERANCE);
  expect(metrics.bodyScrollWidth).toBeLessThanOrEqual(metrics.viewportWidth + EDGE_TOLERANCE);
  expect(metrics.docScrollWidth).toBeLessThanOrEqual(metrics.docClientWidth + EDGE_TOLERANCE);
  expect(metrics.maxScrollLeftAttempt).toBe(0);
}

test.describe("mobile Taşıt parity — home shell + footer", () => {
  for (const viewport of MOBILE_VIEWPORTS) {
    test(`home stays inside the viewport at ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await openHome(page);

      await assertNoHorizontalOverflow(page);

      const header = page.locator(".icons-row");
      await expect(header).toBeVisible();
      const headerBounds = await header.boundingBox();
      expect(headerBounds).not.toBeNull();
      expect(headerBounds!.x).toBeGreaterThanOrEqual(-EDGE_TOLERANCE);
      expect(headerBounds!.x + headerBounds!.width).toBeLessThanOrEqual(
        viewport.width + EDGE_TOLERANCE
      );

      const cardBounds = await page.evaluate(() =>
        Array.from(document.querySelectorAll("#main-menu .menu-btn")).map((card) => {
          const rect = card.getBoundingClientRect();
          return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom };
        })
      );
      expect(cardBounds).toHaveLength(3);
      for (const card of cardBounds) {
        expect(card.left).toBeGreaterThanOrEqual(-EDGE_TOLERANCE);
        expect(card.right).toBeLessThanOrEqual(viewport.width + EDGE_TOLERANCE);
      }
    });

    test(`footer is a bottom-anchored full-band at ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await openHome(page);

      const metrics = await page.evaluate(() => {
        const footer = document.querySelector("#app-footer");
        if (!(footer instanceof HTMLElement)) {
          throw new Error("Missing #app-footer");
        }
        const rect = footer.getBoundingClientRect();
        const style = getComputedStyle(footer);
        const cards = Array.from(document.querySelectorAll("#main-menu .menu-btn"));
        const lastCardBottom = cards.length
          ? Math.max(...cards.map((card) => card.getBoundingClientRect().bottom))
          : null;

        return {
          left: rect.left,
          right: rect.right,
          width: rect.width,
          top: rect.top,
          bottom: rect.bottom,
          height: rect.height,
          position: style.position,
          visibility: style.visibility,
          borderLeftWidth: style.borderLeftWidth,
          borderRightWidth: style.borderRightWidth,
          borderTopWidth: style.borderTopWidth,
          innerHeight: window.innerHeight,
          viewportWidth: window.innerWidth,
          realHeight: getComputedStyle(document.documentElement)
            .getPropertyValue("--app-footer-real-height")
            .trim(),
          lastCardBottom
        };
      });

      expect(metrics.position).toBe("fixed");
      expect(metrics.visibility).toBe("visible");
      expect(metrics.bottom).toBeCloseTo(metrics.innerHeight, 0);
      expect(metrics.top).toBeLessThan(metrics.innerHeight);
      // Full-bleed band: the mobile footer owns the whole viewport width.
      expect(metrics.left).toBeGreaterThanOrEqual(-EDGE_TOLERANCE);
      expect(metrics.right).toBeLessThanOrEqual(metrics.viewportWidth + EDGE_TOLERANCE);
      expect(metrics.width).toBeCloseTo(metrics.viewportWidth, 0);
      // Mobile band height is the token height (safe-area inset is 0px here).
      expect(metrics.realHeight).toBe("38px");
      expect(metrics.height).toBeCloseTo(38, 0);
      // Taşıt ≤640 parity: no desktop frame side rails on the phone band.
      expect(metrics.borderLeftWidth).toBe("0px");
      expect(metrics.borderRightWidth).toBe("0px");
      expect(Number.parseFloat(metrics.borderTopWidth)).toBeGreaterThan(0);
      // The footer must never sit on top of the menu cards.
      expect(metrics.lastCardBottom).not.toBeNull();
      expect(metrics.lastCardBottom!).toBeLessThanOrEqual(metrics.top + EDGE_TOLERANCE);
    });
  }
});

test.describe("desktop smoke — home shell + footer unchanged", () => {
  test("footer keeps the frame rails and shell width at 1280px", async ({ page }) => {
    await page.setViewportSize(DESKTOP_VIEWPORT);
    await openHome(page);

    await assertNoHorizontalOverflow(page);

    const metrics = await page.evaluate(() => {
      const footer = document.querySelector("#app-footer");
      if (!(footer instanceof HTMLElement)) {
        throw new Error("Missing #app-footer");
      }
      const rect = footer.getBoundingClientRect();
      const style = getComputedStyle(footer);
      return {
        left: rect.left,
        right: rect.right,
        width: rect.width,
        bottom: rect.bottom,
        borderLeftWidth: style.borderLeftWidth,
        borderRightWidth: style.borderRightWidth,
        innerHeight: window.innerHeight,
        viewportWidth: window.innerWidth
      };
    });

    expect(metrics.width).toBeCloseTo(SHELL_MAX_WIDTH, 0);
    expect(metrics.left).toBeCloseTo((metrics.viewportWidth - SHELL_MAX_WIDTH) / 2, 0);
    expect(metrics.right).toBeCloseTo(metrics.viewportWidth - metrics.left, 0);
    expect(metrics.bottom).toBeCloseTo(metrics.innerHeight, 0);
    expect(Number.parseFloat(metrics.borderLeftWidth)).toBeGreaterThan(0);
    expect(Number.parseFloat(metrics.borderRightWidth)).toBeGreaterThan(0);
  });
});
