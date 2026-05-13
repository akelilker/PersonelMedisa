import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec Ayrilma sekmesi", () => {
  test("yonetici kayit modalinda Ayrilma ile ISTEN_AYRILMA kaydeder ve personel pasife duser", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    await kayitModal.getByTestId("kayit-tab-surec").click();
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toBeVisible();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "Ayrılma" }).click();

    await expect(kayitModal.locator("[name='surec-create-bas']")).toBeVisible();
    await expect(kayitModal.locator("[name='surec-create-turu']")).toHaveCount(0);
    await expect(kayitModal.locator("[name='surec-create-turu-text']")).toHaveCount(0);
    await expect(kayitModal.locator("[name='surec-create-ucret']")).toHaveCount(0);

    await kayitModal.locator("[name='surec-create-bas']").fill("2026-05-01");
    await kayitModal.locator("[name='surec-create-bitis']").fill("2026-05-01");
    await kayitModal.locator("[name='surec-create-aciklama']").fill("E2E Kayit Ayrilma surec");

    await kayitModal.locator(".workspace-form-actions").getByRole("button", { name: "Kaydet" }).click();

    await expect(kayitModal.locator(".workspace-success--inline")).toContainText(/Süreç kaydı eklendi/i, {
      timeout: 15_000
    });

    await kayitModal.getByRole("button", { name: "Kapat" }).click();

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/İşten Ayrıldı|Pasif/i);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText(/Isten Ayr[\u0131i]lma/i);
    await expect(timeline).toContainText("E2E Kayit Ayrilma surec");
  });

  test("pasif personelde Ayrilma sekmesinde form yerine uyari gorunur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByTestId("kayit-tab-surec").click();

    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Pasif");
    await kayitModal.getByRole("option", { name: /Pasif Ornek/i }).click();

    await kayitModal.getByRole("tab", { name: "Ayrılma" }).click();

    await expect(kayitModal.locator(".surec-person-placeholder")).toContainText(
      "Bu personel pasif; ayrılma kaydı eklenmez."
    );
    await expect(kayitModal.locator("[name='surec-create-bas']")).toHaveCount(0);
  });
});
