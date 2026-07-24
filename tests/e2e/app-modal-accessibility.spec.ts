import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { openHeaderSettingsMenu } from "./helpers/header-nav";
import { mockApi } from "./helpers/mock-api";

test.describe("AppModal dialog a11y", () => {
  test("module modal focuses dialog, contains focus, and restores opener", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    const opener = page.getByTestId("menu-personel-karti");
    await opener.click();
    await expect(page).toHaveURL(/\/personeller$/);

    const dialog = page.getByRole("dialog", { name: "Personel Kartı" });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute("aria-modal", "true");
    await expect.poll(async () => dialog.evaluate((node) => document.activeElement === node)).toBe(true);

    const close = page.locator(".modal-overlay.open .modal-close-btn").first();
    await expect(close).not.toBeFocused();

    await page.evaluate(() => {
      const outside = document.createElement("button");
      outside.type = "button";
      outside.id = "s93b2-outside-focus";
      outside.textContent = "Outside";
      document.body.appendChild(outside);
      outside.focus();
    });

    await expect.poll(async () => dialog.evaluate((node) => document.activeElement === node)).toBe(true);

    await page.keyboard.press("Tab");
    await expect
      .poll(async () =>
        dialog.evaluate((node) => node.contains(document.activeElement) && document.activeElement !== node)
      )
      .toBe(true);

    await close.focus();
    await page.keyboard.press("Shift+Tab");
    await expect
      .poll(async () =>
        dialog.evaluate((node) => node.contains(document.activeElement) && document.activeElement !== node)
      )
      .toBe(true);

    await close.focus();
    await page.keyboard.press("Escape");
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole("dialog", { name: "Personel Kartı" })).toHaveCount(0);
    await expect.poll(async () => opener.evaluate((node) => node === document.activeElement)).toBe(true);
  });

  test("nested yonetim modal containment, Escape stack and opener restore", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await openHeaderSettingsMenu(page);
    const anaOpener = page.getByTestId("settings-yonetim-paneli");
    await expect(anaOpener).toBeVisible();
    await anaOpener.click();

    const outer = page.getByRole("dialog", { name: "KULLANICI YÖNETİMİ" });
    await expect(outer).toBeVisible();
    await expect.poll(async () => outer.evaluate((node) => document.activeElement === node)).toBe(true);

    const createButton = page.getByRole("button", { name: /Yeni Kullanıcı/i });
    await createButton.focus();
    await createButton.click();

    const nested = page.getByRole("dialog", { name: /Yeni Kullanıcı/i });
    await expect(nested).toBeVisible();
    await expect.poll(async () => nested.evaluate((node) => document.activeElement === node)).toBe(true);

    await page.evaluate(() => {
      const outside = document.createElement("button");
      outside.type = "button";
      outside.id = "s93b2-nested-outside-focus";
      outside.textContent = "Outside nested";
      document.body.appendChild(outside);
      outside.focus();
    });
    await expect.poll(async () => nested.evaluate((node) => document.activeElement === node)).toBe(true);

    await outer.evaluate((node) => {
      const focusable = node.querySelector<HTMLElement>("button, [href], input, select, textarea");
      focusable?.focus();
    });
    await expect.poll(async () => nested.evaluate((node) => document.activeElement === node)).toBe(true);
    await expect.poll(async () => outer.evaluate((node) => document.activeElement === node)).toBe(false);

    await page.keyboard.press("Escape");
    await expect(nested).toHaveCount(0);
    await expect(outer).toBeVisible();
    await expect(page).toHaveURL(/\/yonetim-paneli/);
    await expect.poll(async () => createButton.evaluate((node) => node === document.activeElement)).toBe(true);

    await page.keyboard.press("Escape");
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole("dialog")).toHaveCount(0);

    // settings-yonetim-paneli remounts inside a closed dropdown (display:none), so restore
    // correctly skips the unsafe node. Expose the same opener and confirm it can take focus.
    await openHeaderSettingsMenu(page);
    await expect(anaOpener).toBeVisible();
    await anaOpener.focus();
    await expect(anaOpener).toBeFocused();
  });
});
