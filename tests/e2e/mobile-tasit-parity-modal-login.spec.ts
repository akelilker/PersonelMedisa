import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const LOGIN_TITLE = "Personel Yönetim Sistemi";
const LOGIN_TITLE_VISUAL = "PERSONEL YÖNETİM SİSTEMİ";
const MOBILE_VIEWPORTS = [
  { width: 430, height: 932 },
  { width: 393, height: 852 },
  { width: 390, height: 844 },
  { width: 375, height: 812 },
  { width: 360, height: 800 },
  { width: 320, height: 720 }
] as const;

const MAX_LOGIN_HERO_FORM_GAP_PX = 48;
const MIN_LOGIN_TITLE_SAFE_GUTTER_PX = 8;

async function assertNoHorizontalOverflow(page: Page) {
  const metrics = await page.evaluate(() => ({
    docScrollWidth: document.documentElement.scrollWidth,
    viewportWidth: window.innerWidth,
    bodyScrollWidth: document.body.scrollWidth
  }));
  expect(metrics.docScrollWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  expect(metrics.bodyScrollWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);
}

async function assertLoginTitleParity(page: Page) {
  const title = page.locator("body.login-page .hero h1");
  await expect(title).toBeVisible();
  await expect(title).toHaveText(LOGIN_TITLE);
  await page.evaluate(async () => {
    await document.fonts.ready;
  });
  // WebKit can resolve `document.fonts.ready` while `document.fonts.status` still
  // reads "loading" (a known engine race). Poll for the webfont to be truly ready
  // — bounded by the test timeout — so gutter/ink metrics are never measured
  // mid-load. This keeps the parity intent without a flaky one-shot status read.
  await page.waitForFunction(() => document.fonts.status === "loaded");

  const titleMetrics = await title.evaluate((el) => {
    const style = getComputedStyle(el);
    const rect = el.getBoundingClientRect();
    const hero = el.closest(".hero");
    const heroRect = hero?.getBoundingClientRect();
    // Ink bounds of the rendered text (not the h1 content-box). Login CSS
    // intentionally uses overflow:visible + nowrap, so scrollWidth may exceed
    // clientWidth while the title still sits safely inside the hero.
    const range = document.createRange();
    range.selectNodeContents(el);
    const inkRect = range.getBoundingClientRect();
    const visualText = (el.textContent ?? "").toLocaleUpperCase("tr-TR");
    return {
      textOverflow: style.textOverflow,
      overflow: style.overflow,
      whiteSpace: style.whiteSpace,
      fontFamily: style.fontFamily,
      fontSize: style.fontSize,
      paddingLeft: style.paddingLeft,
      paddingRight: style.paddingRight,
      fontStatus: document.fonts.status,
      devicePixelRatio: window.devicePixelRatio,
      viewportWidth: window.innerWidth,
      scrollWidth: el.scrollWidth,
      clientWidth: el.clientWidth,
      titleBoxLeft: rect.left,
      titleBoxRight: rect.right,
      titleInkLeft: inkRect.left,
      titleInkRight: inkRect.right,
      heroLeft: heroRect?.left ?? null,
      heroRight: heroRect?.right ?? null,
      leftGutterPx: heroRect ? inkRect.left - heroRect.left : null,
      rightGutterPx: heroRect ? heroRect.right - inkRect.right : null,
      visible: rect.width > 0 && rect.height > 0 && inkRect.width > 0,
      visualText
    };
  });

  expect(titleMetrics.visualText).toBe(LOGIN_TITLE_VISUAL);
  expect(titleMetrics.textOverflow).not.toBe("ellipsis");
  expect(titleMetrics.overflow).not.toBe("hidden");
  expect(titleMetrics.whiteSpace).toBe("nowrap");
  expect(titleMetrics.fontStatus).toBe("loaded");
  expect(titleMetrics.heroLeft).not.toBeNull();
  expect(titleMetrics.heroRight).not.toBeNull();
  expect(titleMetrics.leftGutterPx).not.toBeNull();
  expect(titleMetrics.rightGutterPx).not.toBeNull();
  expect(titleMetrics.leftGutterPx!).toBeGreaterThanOrEqual(MIN_LOGIN_TITLE_SAFE_GUTTER_PX);
  expect(titleMetrics.rightGutterPx!).toBeGreaterThanOrEqual(MIN_LOGIN_TITLE_SAFE_GUTTER_PX);
  expect(titleMetrics.titleInkLeft).toBeGreaterThanOrEqual(
    titleMetrics.heroLeft! + MIN_LOGIN_TITLE_SAFE_GUTTER_PX
  );
  expect(titleMetrics.titleInkRight).toBeLessThanOrEqual(
    titleMetrics.heroRight! - MIN_LOGIN_TITLE_SAFE_GUTTER_PX
  );
  expect(titleMetrics.visible).toBe(true);
}

async function assertLoginHeroFormGap(page: Page) {
  const gapMetrics = await page.evaluate(() => {
    const hero = document.querySelector("body.login-page .hero");
    const form = document.querySelector(".auth-login-form");
    const heroRect = hero?.getBoundingClientRect();
    const formRect = form?.getBoundingClientRect();
    const authLogin = document.querySelector(".auth-login");
    const authStyle = authLogin ? getComputedStyle(authLogin) : null;
    return {
      heroFormGapPx: heroRect && formRect ? formRect.top - heroRect.bottom : null,
      authJustify: authStyle?.justifyContent ?? "",
      authMinHeight: authStyle?.minHeight ?? "",
      innerHeight: window.innerHeight,
      visualViewportHeight: window.visualViewport?.height ?? null,
      footerTop: document.querySelector("#app-footer")?.getBoundingClientRect().top ?? null
    };
  });

  expect(gapMetrics.heroFormGapPx).not.toBeNull();
  expect(gapMetrics.heroFormGapPx!).toBeGreaterThanOrEqual(0);
  expect(gapMetrics.heroFormGapPx!).toBeLessThanOrEqual(MAX_LOGIN_HERO_FORM_GAP_PX);
  expect(gapMetrics.authJustify).toBe("flex-start");
  expect(gapMetrics.authMinHeight).not.toBe("100%");
  if (gapMetrics.visualViewportHeight != null) {
    expect(gapMetrics.footerTop!).toBeLessThanOrEqual(gapMetrics.visualViewportHeight + 1);
  }
}

async function assertAuthHeroTitle(page: Page) {
  const title = page.locator(".hero.hero-with-session h1");
  await expect(title).toBeVisible();
  await page.evaluate(async () => {
    await document.fonts.ready;
  });

  const metrics = await title.evaluate((el, expectedVisual) => {
    const style = getComputedStyle(el);
    const hero = el.closest(".hero");
    const heroStyle = hero ? getComputedStyle(hero) : null;
    const heroRect = hero?.getBoundingClientRect();
    const logo = hero?.querySelector(".hero-logo");
    const spacer = hero?.querySelector(".hero-spacer");
    const titleRect = el.getBoundingClientRect();
    const logoRect = logo?.getBoundingClientRect();
    const spacerRect = spacer?.getBoundingClientRect();
    const range = document.createRange();
    range.selectNodeContents(el);
    const inkRect = range.getBoundingClientRect();
    const visualText = (el.textContent ?? "").toLocaleUpperCase("tr-TR");
    const overlapsLogo = logoRect ? titleRect.left < logoRect.right - 2 : false;
    const overlapsSpacer = spacerRect ? titleRect.right > spacerRect.left + 2 : false;
    const minHomeTitlePx = window.innerWidth <= 360 ? 13 : window.innerWidth <= 390 ? 15 : 15;
    return {
      visualText,
      scrollWidth: el.scrollWidth,
      clientWidth: el.clientWidth,
      offsetWidth: el.offsetWidth,
      textOverflow: style.textOverflow,
      overflow: style.overflow,
      heroOverflow: heroStyle?.overflow ?? "",
      gridTemplateColumns: heroStyle?.gridTemplateColumns ?? "",
      fontSizePx: Number.parseFloat(style.fontSize),
      minHomeTitlePx,
      letterSpacing: style.letterSpacing,
      overlapsLogo,
      overlapsSpacer,
      heroLeft: heroRect?.left ?? null,
      heroRight: heroRect?.right ?? null,
      titleInkLeft: inkRect.left,
      titleInkRight: inkRect.right,
      viewportWidth: window.innerWidth
    };
  }, LOGIN_TITLE_VISUAL);

  expect(metrics.visualText).toBe(LOGIN_TITLE_VISUAL);
  expect(metrics.textOverflow).not.toBe("ellipsis");
  expect(metrics.heroOverflow).not.toBe("hidden");
  expect(metrics.fontSizePx).toBeGreaterThanOrEqual(metrics.minHomeTitlePx);
  expect(metrics.overlapsLogo).toBe(false);
  expect(metrics.overlapsSpacer).toBe(false);
  if (metrics.heroLeft != null && metrics.heroRight != null) {
    expect(metrics.titleInkLeft).toBeGreaterThanOrEqual(metrics.heroLeft + MIN_LOGIN_TITLE_SAFE_GUTTER_PX);
    expect(metrics.titleInkRight).toBeLessThanOrEqual(metrics.heroRight - MIN_LOGIN_TITLE_SAFE_GUTTER_PX);
  }
}

async function openKayitModal(page: Page) {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container--kayit-surec").last();
  await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  return kayitModal;
}

async function assertKayitModalGeometry(page: Page, kayitModal: ReturnType<typeof page.locator>) {
  await assertNoHorizontalOverflow(page);

  const metrics = await kayitModal.evaluate((modal) => {
    const footer = document.querySelector("#app-footer");
    const overlay = modal.closest(".modal-overlay");
    if (!(footer instanceof HTMLElement)) {
      throw new Error("Missing #app-footer");
    }
    if (!(overlay instanceof HTMLElement)) {
      throw new Error("Missing .modal-overlay");
    }

    const modalBounds = modal.getBoundingClientRect();
    const overlayBounds = overlay.getBoundingClientRect();
    const footerBounds = footer.getBoundingClientRect();
    const body = modal.querySelector(".modal-body");
    const bodyBounds = body?.getBoundingClientRect();
    const bodyStyle = body ? getComputedStyle(body) : null;
    const columns = modal.querySelector(".personel-form-columns");
    const columnStyles = columns ? getComputedStyle(columns) : null;
    const firstInput = modal.querySelector(".personel-form-column input, .personel-form-column select");
    const inputBounds = firstInput?.getBoundingClientRect();

    return {
      overlayLeft: overlayBounds.left,
      overlayRight: overlayBounds.right,
      modalLeftEdgePx: modalBounds.left,
      modalRightEdgePx: window.innerWidth - modalBounds.right,
      modalFooterGapPx: footerBounds.top - modalBounds.bottom,
      bodyLeft: bodyBounds?.left ?? null,
      bodyRight: bodyBounds?.right ?? null,
      bodyOverflowY: bodyStyle?.overflowY ?? "",
      bodyScrollOwner: body ? body.scrollHeight > body.clientHeight : false,
      overlayScrollOwner: overlay.scrollHeight > overlay.clientHeight,
      docBodyOverflow: getComputedStyle(document.body).overflow,
      modalColumnsMobile: columnStyles?.gridTemplateColumns ?? "",
      minInputWidth: inputBounds?.width ?? null,
      viewportWidth: window.innerWidth,
      visualViewportHeight: window.visualViewport?.height ?? null
    };
  });

  expect(metrics.overlayLeft).toBeGreaterThanOrEqual(-1);
  expect(metrics.overlayRight).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  expect(metrics.modalLeftEdgePx).toBeGreaterThanOrEqual(-1);
  expect(metrics.modalLeftEdgePx).toBeLessThanOrEqual(1);
  expect(metrics.modalRightEdgePx).toBeGreaterThanOrEqual(-1);
  expect(metrics.modalRightEdgePx).toBeLessThanOrEqual(1);
  expect(metrics.modalFooterGapPx).toBeGreaterThan(0);
  expect(metrics.modalFooterGapPx).toBeCloseTo(6, 1);
  if (metrics.bodyLeft != null && metrics.bodyRight != null) {
    expect(metrics.bodyLeft).toBeGreaterThanOrEqual(metrics.modalLeftEdgePx - 1);
    expect(metrics.bodyRight).toBeLessThanOrEqual(metrics.viewportWidth - metrics.modalRightEdgePx + 1);
  }

  const columnTracks = metrics.modalColumnsMobile.trim().split(/\s+/);
  expect(columnTracks).toHaveLength(2);
  expect(columnTracks[0]).toBe(columnTracks[1]);
  if (metrics.minInputWidth != null) {
    expect(metrics.minInputWidth).toBeGreaterThanOrEqual(72);
  }

  expect(metrics.bodyOverflowY).toBe("auto");
  expect(metrics.docBodyOverflow).toBe("hidden");
  expect(metrics.overlayScrollOwner).toBe(false);

  await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();

  return metrics;
}

test.describe("mobile Taşıt parity — login", () => {
  for (const viewport of MOBILE_VIEWPORTS) {
    test(`login title + hero→form gap at ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.goto("/login");
      await assertNoHorizontalOverflow(page);
      await assertLoginTitleParity(page);
      await assertLoginHeroFormGap(page);
    });
  }
});

test.describe("mobile Taşıt parity — authenticated hero", () => {
  for (const viewport of MOBILE_VIEWPORTS) {
    test(`auth hero title full at ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await mockApi(page, "GENEL_YONETICI");
      await login(page, { username: "yonetici", password: "secret" });
      await assertNoHorizontalOverflow(page);
      await assertAuthHeroTitle(page);
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
      await assertKayitModalGeometry(page, kayitModal);
    });
  }
});

test.describe("mobile shell — notification + settings viewport panels", () => {
  for (const viewport of [
    { width: 390, height: 844 },
    { width: 320, height: 720 }
  ] as const) {
    test(`notification + settings panels fit at ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await mockApi(page, "GENEL_YONETICI");
      await login(page, { username: "yonetici", password: "secret" });

      await page.locator("#notifications-toggle-btn").click({ force: true });
      const notificationPanel = page.locator("#notifications-dropdown");
      await expect(notificationPanel).toBeVisible();

      const notifMetrics = await notificationPanel.evaluate((panel) => {
        const panelBounds = panel.getBoundingClientRect();
        const card = panel.querySelector(".notification-item");
        const cardBounds = card?.getBoundingClientRect();
        const line = panel.querySelector(".notif-line1");
        const lineStyle = line ? getComputedStyle(line) : null;
        return {
          left: panelBounds.left,
          right: panelBounds.right,
          width: panelBounds.width,
          cardLeft: cardBounds?.left ?? null,
          cardRight: cardBounds?.right ?? null,
          cardWidth: cardBounds?.width ?? null,
          maxWidth: getComputedStyle(panel).maxWidth,
          lineWhiteSpace: lineStyle?.whiteSpace ?? "",
          viewportWidth: window.innerWidth
        };
      });

      expect(notifMetrics.left).toBeGreaterThanOrEqual(-1);
      expect(notifMetrics.right).toBeLessThanOrEqual(notifMetrics.viewportWidth + 1);
      expect(notifMetrics.width).toBeGreaterThanOrEqual(Math.min(240, notifMetrics.viewportWidth - 32));
      expect(notifMetrics.maxWidth).toMatch(/px|vw|%|calc/i);
      expect(notifMetrics.maxWidth).not.toMatch(/^calc\(100%/);
      if (notifMetrics.cardLeft != null && notifMetrics.cardRight != null && notifMetrics.cardWidth != null) {
        expect(notifMetrics.cardLeft).toBeGreaterThanOrEqual(notifMetrics.left - 1);
        expect(notifMetrics.cardRight).toBeLessThanOrEqual(notifMetrics.right + 1);
        expect(notifMetrics.cardWidth).toBeGreaterThanOrEqual(Math.min(200, notifMetrics.width - 16));
      }
      expect(notifMetrics.lineWhiteSpace).not.toBe("nowrap");

      await page.keyboard.press("Escape");
      await page.getByTestId("header-settings-toggle").click();
      const settingsPanel = page.locator("#settings-menu");
      await expect(settingsPanel).toBeVisible();

      const settingsMetrics = await settingsPanel.evaluate((panel) => {
        const panelBounds = panel.getBoundingClientRect();
        const item = panel.querySelector("button");
        const itemBounds = item?.getBoundingClientRect();
        return {
          left: panelBounds.left,
          right: panelBounds.right,
          width: panelBounds.width,
          itemLeft: itemBounds?.left ?? null,
          itemRight: itemBounds?.right ?? null,
          maxWidth: getComputedStyle(panel).maxWidth,
          viewportWidth: window.innerWidth
        };
      });

      expect(settingsMetrics.left).toBeGreaterThanOrEqual(-1);
      expect(settingsMetrics.right).toBeLessThanOrEqual(settingsMetrics.viewportWidth + 1);
      expect(settingsMetrics.width).toBeGreaterThanOrEqual(Math.min(220, settingsMetrics.viewportWidth - 32));
      expect(settingsMetrics.maxWidth).not.toMatch(/^calc\(100%/);
      if (settingsMetrics.itemLeft != null && settingsMetrics.itemRight != null) {
        expect(settingsMetrics.itemLeft).toBeGreaterThanOrEqual(settingsMetrics.left - 1);
        expect(settingsMetrics.itemRight).toBeLessThanOrEqual(settingsMetrics.right + 1);
      }
    });
  }
});

test.describe("desktop regression — hero + Kayıt modal", () => {
  test.beforeEach(({ }, testInfo) => {
    if (testInfo.project.name === "webkit-mobile-regression") {
      test.skip();
    }
  });

  for (const viewport of [
    { width: 1366, height: 768 },
    { width: 1440, height: 900 },
    { width: 1920, height: 1080 }
  ] as const) {
    test(`desktop hero title 19px at ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await mockApi(page, "GENEL_YONETICI");
      await login(page, { username: "yonetici", password: "secret" });

      const title = page.locator(".hero.hero-with-session h1");
      await expect(title).toBeVisible();
      await page.waitForFunction(() => {
        const el = document.querySelector(".hero.hero-with-session h1");
        if (!el) return false;
        const size = Number.parseFloat(getComputedStyle(el).fontSize);
        return Number.isFinite(size) && size > 0;
      });

      const metrics = await title.evaluate((el) => {
        const style = getComputedStyle(el);
        const line = el.closest(".hero")?.querySelector(".animated-line");
        const lineStyle = line ? getComputedStyle(line) : null;
        const titleRect = el.getBoundingClientRect();
        const lineRect = line?.getBoundingClientRect();
        const centerDelta =
          titleRect && lineRect
            ? Math.abs(lineRect.left + lineRect.width / 2 - (titleRect.left + titleRect.width / 2))
            : null;
        return {
          fontSizePx: Number.parseFloat(style.fontSize),
          scrollWidth: el.scrollWidth,
          clientWidth: el.clientWidth,
          offsetWidth: el.offsetWidth,
          accentCenterDelta: centerDelta
        };
      });

      expect(metrics.fontSizePx).toBeCloseTo(19, 0);
      expect(metrics.offsetWidth).toBeGreaterThanOrEqual(metrics.scrollWidth - 2);
      if (metrics.accentCenterDelta != null) {
        expect(metrics.accentCenterDelta).toBeLessThanOrEqual(6);
      }
    });
  }

  test("keeps 2-column grid, auto-sicil note left, and bottom row alignment", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openKayitModal(page);

    const desktopMetrics = await kayitModal.evaluate((modal) => {
      const columns = modal.querySelectorAll(".personel-form-column");
      const kan = modal.querySelector('[name="create-kan"]');
      const maas = modal.querySelector('[name="create-maas"]');
      const sicil = modal.querySelector('[data-testid="create-sicil-auto-note"]');
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
