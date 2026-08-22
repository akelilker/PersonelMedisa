import { mkdirSync } from "node:fs";
import { join } from "node:path";
import { test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const OUT_DIR = join(process.cwd(), "artifacts", "mobile-tasit-parity-screenshots");

const VIEWPORTS = [
  { width: 430, height: 932 },
  { width: 390, height: 844 },
  { width: 360, height: 800 },
  { width: 320, height: 720 }
] as const;

test.describe("mobile Taşıt parity screenshots", () => {
  test.beforeAll(() => {
    mkdirSync(OUT_DIR, { recursive: true });
  });

  for (const viewport of VIEWPORTS) {
    test(`login ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.goto("/login");
      await page.waitForTimeout(300);
      await page.screenshot({
        path: join(OUT_DIR, `login-${viewport.width}.png`),
        fullPage: true
      });
    });
  }

  for (const viewport of VIEWPORTS) {
    test(`kayit-modal ${viewport.width}px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await mockApi(page, "GENEL_YONETICI");
      await login(page, { username: "yonetici", password: "secret" });
      await page.getByTestId("menu-kayit-surec").click();
      await page.locator(".modal-container--kayit-surec").last().waitFor({ state: "visible" });
      await page.waitForTimeout(300);
      await page.screenshot({
        path: join(OUT_DIR, `kayit-modal-${viewport.width}.png`),
        fullPage: true
      });
    });
  }

  test("desktop smoke", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    await page.screenshot({
      path: join(OUT_DIR, "login-desktop.png"),
      fullPage: true
    });
    await page.getByTestId("menu-kayit-surec").click();
    await page.locator(".modal-container--kayit-surec").last().waitFor({ state: "visible" });
    await page.waitForTimeout(300);
    await page.screenshot({
      path: join(OUT_DIR, "kayit-modal-desktop.png"),
      fullPage: true
    });
  });
});
