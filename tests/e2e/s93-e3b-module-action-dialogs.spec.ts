import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

function trackNativeDialogs(page: Page) {
  const nativeDialogs: string[] = [];
  page.on("dialog", (dialog) => {
    nativeDialogs.push(`${dialog.type()}:${dialog.message()}`);
    void dialog.dismiss();
  });
  return nativeDialogs;
}

test.describe("S93-E3B Süreç, Finans ve Bildirim action dialogs", () => {
  test("GENEL_YONETICI: süreç iptal dialogu native dialog oluşturmaz", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/surecler");
    const surecPage = page.locator(".surec-page");
    await expect(surecPage.getByRole("heading", { name: "Süreç Takibi" })).toBeVisible();

    const cancelButton = surecPage.getByRole("button", { name: "İptal", exact: true }).first();
    await expect(cancelButton).toBeVisible();
    await cancelButton.click();

    await expect(page.getByTestId("surec-action-dialog")).toBeVisible();
    await expect(page.getByTestId("surec-action-dialog-title")).toHaveText("Süreci İptal Et");
    await expect(page.getByTestId("surec-action-dialog-description")).toContainText("Süreç #");
    await expect(page.getByTestId("surec-action-dialog-cancel")).toBeFocused();

    await page.getByTestId("surec-action-dialog-cancel").click();
    await expect(page.getByTestId("surec-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);

    await cancelButton.click();
    await page.getByTestId("surec-action-dialog-confirm").click();
    await expect(page.getByTestId("surec-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: finans iptal dialogu destructive ve ilk odak Vazgeç", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/finans");
    const finansPage = page.locator(".finans-page");
    await expect(finansPage.getByRole("heading", { name: "Finans" })).toBeVisible();

    const cancelButton = finansPage.getByRole("button", { name: "İptal", exact: true }).first();
    await expect(cancelButton).toBeVisible();
    await cancelButton.click();

    await expect(page.getByTestId("finans-action-dialog")).toBeVisible();
    await expect(page.getByTestId("finans-action-dialog-title")).toHaveText("Finans Kaydını İptal Et");
    await expect(page.getByTestId("finans-action-dialog-cancel")).toBeFocused();

    await page.getByTestId("finans-action-dialog-confirm").dblclick();
    await expect(page.getByTestId("finans-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("BIRIM_AMIRI: günlük kayıt iptal dialogu cancel ve confirm", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/bildirimler");
    await expect(page.getByRole("heading", { name: "Bugünkü Personel Durumu" }).first()).toBeVisible();

    const cancelButton = page
      .locator(".bildirimler-item")
      .filter({ hasText: "Kayıt Durumu: Taslak" })
      .getByRole("button", { name: "İptal", exact: true });
    await expect(cancelButton).toBeVisible();
    await cancelButton.click();

    await expect(page.getByTestId("bildirim-action-dialog")).toBeVisible();
    await expect(page.getByTestId("bildirim-action-dialog-title")).toHaveText("Günlük Kaydı İptal Et");
    await expect(page.getByTestId("bildirim-action-dialog-cancel")).toBeFocused();

    await page.getByTestId("bildirim-action-dialog-cancel").click();
    await expect(page.getByTestId("bildirim-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);

    await cancelButton.click();
    await page.getByTestId("bildirim-action-dialog-confirm").click();
    await expect(page.getByTestId("bildirim-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("dialog klavye: Escape dialogu kapatır", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/finans");
    const finansPage = page.locator(".finans-page");
    await finansPage.getByRole("button", { name: "İptal", exact: true }).first().click();
    await expect(page.getByTestId("finans-action-dialog")).toBeVisible();
    await expect(page.getByTestId("finans-action-dialog-cancel")).toBeFocused();

    await page.keyboard.press("Escape");
    await expect(page.getByTestId("finans-action-dialog")).toHaveCount(0);
  });

  test.describe("viewport", () => {
    for (const viewport of [
      { width: 1366, height: 768, label: "desktop" },
      { width: 390, height: 844, label: "mobile" },
      { width: 320, height: 568, label: "narrow" }
    ]) {
      test(`finans dialog ${viewport.label} viewport'ta kullanılabilir`, async ({ page }) => {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await loginAsMockRole(page, "GENEL_YONETICI");
        await page.goto("/finans");
        await page.locator(".finans-page").getByRole("button", { name: "İptal", exact: true }).first().click();

        const dialog = page.getByTestId("finans-action-dialog");
        await expect(dialog).toBeVisible();
        await expect(page.getByTestId("finans-action-dialog-title")).toBeVisible();
        await expect(page.getByTestId("finans-action-dialog-confirm")).toBeVisible();
        await expect(page.getByTestId("finans-action-dialog-cancel")).toBeVisible();
      });
    }
  });
});
