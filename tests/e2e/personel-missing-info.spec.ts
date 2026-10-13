import { expect, test } from "@playwright/test";
import type { Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

function kayitSurecModal(page: Page) {
  return page.locator(".modal-container--kayit-surec, .modal-container").filter({
    has: page.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })
  });
}

test.describe("personel eksik bilgi UX", () => {
  test("liste badge, kart vurgusu ve Genel gateway aynı completeness owner'ını kullanır", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller?view=list");
    await expect(page.getByRole("button", { name: /Eksik bilgiler:.*Bölüm.*Birim/i }).first()).toBeVisible();

    await page.getByRole("row", { name: /Ayşe Yılmaz.*kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    // t869: uyarı eksik alanları adıyla listeler.
    const ozet = page.getByTestId("personel-eksik-bilgi-ozeti");
    await expect(ozet).toContainText("2 Eksik Bilgi:");
    await expect(page.getByTestId("personel-eksik-bilgi-alan-bolum_id")).toHaveText("Bölüm");
    await expect(page.getByTestId("personel-eksik-bilgi-alan-birim_id")).toHaveText("Birim");
    await expect(page.getByRole("tab", { name: /Genel/ })).toContainText("2");

    const missingFields = page.locator("[data-missing='true']");
    await expect(missingFields.filter({ hasText: "Bölüm" }).first()).toContainText("bu alanın adına tıklayın");
    await expect(missingFields.filter({ hasText: "Birim" }).first()).toBeVisible();

    // Bölüm Genel formunda yok: Görev / Organizasyon sekmesi açılır, Bölüm alanı kırmızı ve odakta.
    await page.getByTestId("personel-eksik-bilgi-alan-bolum_id").click();

    const kayitModal = kayitSurecModal(page);
    await expect(kayitModal).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");
    await expect(kayitModal.getByTestId("kayit-surec-organizasyon-panel")).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-surec-personel-duzenle")).toHaveCount(0);
    const bolumSection = kayitModal.locator(".field-has-error");
    await expect(bolumSection).toHaveCount(1);
    await expect(bolumSection).toContainText("Bölüm");
    await expect(kayitModal.locator("[data-field-error='true']")).toBeFocused();

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
  });

  test("sekme degisimi URL tab parametresini yazir ve deep-link okur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/1?tab=disiplin");
    await expect(page.getByRole("tab", { name: /Disiplin/ })).toHaveAttribute("aria-selected", "true");

    await page.getByRole("tab", { name: /Genel/ }).click();
    await expect(page).toHaveURL(/\/personeller\/1\?tab=genel-bilgiler/);
    await expect(page.getByRole("tab", { name: /Genel/ })).toHaveAttribute("aria-selected", "true");
  });
});
