import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

test.describe("S81 Bildirim ve Onay Zinciri", () => {
  test("BIRIM_AMIRI: gun ozeti, GELMEDI olustur/gonder, personel ID yok, haftalik panel", async ({
    page
  }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/bildirimler");
    await expect(page.getByRole("heading", { name: "Bugünkü Personel Durumu" })).toBeVisible();
    await expect(page.getByTestId("gunluk-bildirim-ozet")).toBeVisible();
    await expect(page.getByTestId("haftalik-mutabakat-panel")).toBeVisible();
    await expect(page.getByLabel("Personel ID")).toHaveCount(0);

    await page.getByRole("button", { name: "Bildirim Gir" }).first().click();
    await expect(page.locator("#gunluk-kayit-create-form")).toBeVisible();
    await page.locator('select[name="bildirim-create-personel"]').selectOption({ index: 1 });
    await page.getByRole("button", { name: "Gelmedi", exact: true }).click();
    await page.getByRole("button", { name: "Kaydet" }).click();
    await expect(page.locator("#gunluk-kayit-create-form")).toHaveCount(0);

    const submitButton = page.getByRole("button", { name: "Gönder", exact: true }).first();
    if ((await submitButton.count()) > 0 && (await submitButton.isEnabled())) {
      await submitButton.click();
    }
  });

  test("BIRIM_AMIRI: duplicate create conflict mesaji", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/bildirimler");

    const createTwice = async () => {
      await page.getByRole("button", { name: "Bildirim Gir" }).first().click();
      await page.locator('select[name="bildirim-create-personel"]').selectOption({ index: 1 });
      await page.getByRole("button", { name: "Gelmedi", exact: true }).click();
      await page.getByRole("button", { name: "Kaydet" }).click();
    };

    await createTwice();
    await createTwice();
    await expect(page.locator(".bildirim-form-error, .form-error, [class*='error']").first()).toContainText(
      /açık bildirim|acik bildirim/i,
      { timeout: 10_000 }
    );
  });

  test("BOLUM_YONETICISI: paneller salt okunur, haftalik/aylik onay yok", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI");
    await page.goto("/bildirimler");
    await expect(page.getByTestId("bildirim-panel-context")).toBeVisible();
    await expect(page.getByRole("button", { name: "Bildirim Gir" })).toHaveCount(0);
    await expect(page.getByTestId("haftalik-mutabakat-approve")).toHaveCount(0);
    await expect(page.getByTestId("aylik-bildirim-onay-approve")).toHaveCount(0);
  });

  test("MUHASEBE: sayfa gorunur, create ve haftalik onay yok", async ({ page }) => {
    await loginAsMockRole(page, "MUHASEBE");
    await page.goto("/bildirimler");
    await expect(page.getByTestId("bildirim-panel-context")).toBeVisible();
    await expect(page.getByRole("button", { name: /Bildirim Gir|Yeni Bildirim/ })).toHaveCount(0);
    await expect(page.getByTestId("haftalik-mutabakat-approve")).toHaveCount(0);
  });

  test("GENEL_YONETICI: panel baglami + GY paneli; bloklu approve disabled", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/bildirimler");
    await expect(page.getByTestId("bildirim-panel-context")).toBeVisible();
    await expect(page.getByTestId("genel-yonetici-bildirim-onay-panel")).toBeVisible();
    const approve = page.getByTestId("genel-yonetici-bildirim-onay-approve");
    if ((await approve.count()) > 0) {
      await expect(approve).toBeDisabled();
    }
  });

  test("PATRON: /bildirimler yetkisiz", async ({ page }) => {
    await loginAsMockRole(page, "PATRON");
    await page.goto("/bildirimler");
    await expect(page).toHaveURL(/\/yetkisiz/);
  });
});
