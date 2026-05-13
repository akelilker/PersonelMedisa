import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec Belgeler metadata", () => {
  test("yonetici Belgeler sekmesinde VAR YOK kaydeder ve tekrar acilista yukler", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
    await expect(kayitModal.getByText("Kimlik")).toBeVisible();

    await kayitModal.locator('input[name="belge-durum-KIMLIK"][value="VAR"]').check();
    await kayitModal.locator('button[type="submit"][form="kayit-surec-belgeler-form"]').click();

    await expect(kayitModal.getByText(/Belge durumu kaydedildi/i)).toBeVisible({ timeout: 15_000 });

    await kayitModal.getByTestId("kayit-surec-subtab-mali").click();
    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
    await expect(kayitModal.locator('input[name="belge-durum-KIMLIK"][value="VAR"]')).toBeChecked();
  });

  test("pasif personelde Belgeler formu yerine uyari gorunur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Pasif");
    await kayitModal.getByRole("option", { name: /Pasif Ornek/i }).click();

    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
    await expect(kayitModal.locator(".surec-person-placeholder")).toContainText(/belge durumu güncellenmez/i);
    await expect(kayitModal.locator('input[name="belge-durum-KIMLIK"]')).toHaveCount(0);
  });
});
