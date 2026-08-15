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

    await page.goto("/personeller");
    await expect(page.getByTestId("personel-eksik-bilgi-1")).toHaveText("Eksik Bilgi");

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await expect(page.getByTestId("personel-eksik-bilgi-ozeti")).toContainText("2 eksik bilgi");

    const missingFields = page.locator(".personel-dosya-field.is-missing");
    await expect(missingFields.filter({ hasText: "Bölüm" })).toContainText("Bilgi girilmemiş");
    await expect(missingFields.filter({ hasText: "Birim" })).toContainText("Bilgi girilmemiş");

    await page.getByTestId("personel-eksik-bilgi-tamamla").click();

    const kayitModal = kayitSurecModal(page);
    await expect(kayitModal).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");
    await expect(kayitModal.getByRole("tab", { name: "Genel" })).toHaveAttribute("aria-selected", "true");
    await expect(kayitModal.getByText("Ayşe Yılmaz", { exact: false }).first()).toBeVisible();

    await kayitModal.getByTestId("kayit-surec-personel-duzenle").click();
    await expect(kayitModal.getByLabel("Sicil No")).toBeVisible();
    await expect(kayitModal.getByLabel("İşe Giriş Tarihi")).toBeVisible();
  });
});
