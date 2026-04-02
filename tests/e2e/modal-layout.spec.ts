import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test("home modal shell aligns to app container and footer gap contract", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "genel_yonetici", password: "demo123" });

  await expect(page).toHaveURL("/");

  await page.getByTestId("menu-giris-surec").click();
  await page.locator(".modal-container").waitFor({ state: "visible" });

  const metrics = await page.evaluate(() => {
    function rect(selector: string) {
      const element = document.querySelector(selector);
      if (!(element instanceof HTMLElement)) {
        throw new Error(`Missing element for selector: ${selector}`);
      }

      const bounds = element.getBoundingClientRect();
      return {
        top: bounds.top,
        right: bounds.right,
        bottom: bounds.bottom,
        left: bounds.left
      };
    }

    const appShell = rect(".app-shell");
    const overlay = rect(".modal-overlay");
    const modal = rect(".modal-container");
    const footer = rect("#app-footer");

    return {
      overlayLeftDelta: overlay.left - appShell.left,
      overlayRightDelta: appShell.right - overlay.right,
      overlayTopDelta: overlay.top - appShell.top,
      modalTopDelta: modal.top - appShell.top,
      modalLeftDelta: modal.left - appShell.left,
      modalRightDelta: appShell.right - modal.right,
      footerGapDelta: footer.top - modal.bottom
    };
  });

  expect(metrics.overlayLeftDelta).toBeCloseTo(0, 1);
  expect(metrics.overlayRightDelta).toBeCloseTo(0, 1);
  expect(metrics.overlayTopDelta).toBeCloseTo(0, 1);
  expect(metrics.modalTopDelta).toBeCloseTo(0, 1);
  expect(metrics.modalLeftDelta).toBeCloseTo(0, 1);
  expect(metrics.modalRightDelta).toBeCloseTo(0, 1);
  expect(metrics.footerGapDelta).toBeCloseTo(8, 1);
});
