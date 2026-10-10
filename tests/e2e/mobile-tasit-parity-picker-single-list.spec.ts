/**
 * iOS çift liste kilidi (WebKit iPhone emülasyonu, dokunmatik): Süreç personel seçicide
 * dokunuş yalnız kanonik listeyi açar; gizli native <select> focus almaz (WebKit'te focus
 * sistem picker'ını açar), tek arama alanı vardır. Prim Kuralı detayda görünmez.
 */
import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const SHOT_DIR = process.env.SHOT_DIR;

// Yalnız dokunmatik (iPhone/WebKit) projede anlamlı: masaüstü Chromium projesinde tap yok.
test.skip(({ hasTouch }) => !hasTouch, "dokunmatik cihaz emülasyonu gerekir");

test("Süreç personel seçici dokunuşta tek liste açar (native select focus yok)", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "yonetici", password: "secret" });
  await page.getByTestId("menu-kayit-surec").click();
  const modal = page.locator(".modal-container--kayit-surec").last();
  await modal.getByRole("button", { name: "Süreç" }).click();

  const trigger = modal.locator(".surec-personel-combobox [data-app-select-trigger]");
  await expect(trigger).toBeVisible();
  await trigger.tap();

  const panel = modal.locator("[data-app-select-panel]");
  await expect(panel).toBeVisible();
  const state = await modal.evaluate((root) => {
    const select = root.querySelector(".surec-personel-combobox select") as HTMLSelectElement | null;
    return {
      activeIsSelect: document.activeElement === select,
      selectVisibility: select ? getComputedStyle(select).visibility : "missing",
      selectTabIndex: select?.tabIndex ?? null,
      searchInputs: root.querySelectorAll("input[type='search']").length
    };
  });
  expect(state.activeIsSelect).toBe(false);
  expect(state.selectVisibility).toBe("hidden");
  expect(state.selectTabIndex).toBe(-1);
  expect(state.searchInputs).toBe(1);
  if (SHOT_DIR) {
    await page.screenshot({ path: `${SHOT_DIR}/v3-webkit-iphone-1-picker-open.png` });
  }

  await panel.getByRole("option", { name: /Ayşe Yılmaz/i }).tap();
  const genel = modal.getByTestId("kayit-surec-personel-genel-panel");
  await expect(genel).toBeVisible();
  await expect(genel).not.toContainText(/Prim Kural/i);
  await expect(genel).not.toContainText("No'lu Prim");
  if (SHOT_DIR) {
    await page.screenshot({ path: `${SHOT_DIR}/v3-webkit-iphone-2-genel-top.png` });
    await genel.screenshot({ path: `${SHOT_DIR}/v3-webkit-iphone-3-detail-grid.png` });
  }
});
