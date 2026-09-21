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

    await expect(page.getByTestId("personel-eksik-bilgi-ozeti")).toContainText(
      "2 Eksik Bilgi Mevcut. Tamamlamak İçin Tıklayınız."
    );
    await expect(page.getByRole("tab", { name: /Genel/ })).toContainText("2");

    const missingFields = page.locator(".personel-dosya-record.is-missing");
    await expect(missingFields.filter({ hasText: "Bölüm" })).toContainText("Bilgi girilmemiş");
    await expect(missingFields.filter({ hasText: "Birim" })).toContainText("Bilgi girilmemiş");
    await expect(missingFields.first()).toContainText("eksik bilgi bağlantısına tıklayın");

    await page.getByTestId("personel-eksik-bilgi-tamamla").click();

    const kayitModal = kayitSurecModal(page);
    await expect(kayitModal).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");
    await expect(kayitModal.getByRole("tab", { name: "Genel" })).toHaveAttribute("aria-selected", "true");
    await expect(kayitModal.getByText("Ayşe Yılmaz", { exact: false }).first()).toBeVisible();

    await kayitModal.getByTestId("kayit-surec-personel-duzenle").click();
    await expect(kayitModal.getByLabel("Sicil No")).toBeVisible();
    await expect(kayitModal.getByLabel("İşe Giriş Tarihi")).toBeVisible();

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
