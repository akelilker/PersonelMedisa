import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "yonetici", password: "secret" },
  birimAmiri: { username: "birim", password: "demo123" }
};

test.describe("S86 personel belge yönetimi", () => {
  test("yetkili kullanıcı belge ekler, günceller, dosya değiştirir, iptal eder", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    const uniqueAd = `S86 Belge ${Date.now()}`;

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const panel = page.locator("#personel-kart-panel-egitim-belgeler");
    await panel.getByTestId("personel-belge-yeni-btn").click();
    await page.getByTestId("personel-belge-ad").fill(uniqueAd);
    await page.locator("#personel-belge-tipi").selectOption("SERTIFIKA");
    await page.getByTestId("personel-belge-create-submit").click();
    await expect(panel.getByText(/Belge kaydı eklendi/i)).toBeVisible({ timeout: 15_000 });
    await expect(panel.getByTestId("personel-belge-kayit-list")).toContainText(uniqueAd);

    const createdRow = panel.locator("tbody tr", { hasText: uniqueAd }).first();
    const rowTestId = await createdRow.getAttribute("data-testid");
    const kayitId = rowTestId?.replace("personel-belge-kayit-row-", "") ?? "";

    await createdRow.getByTestId(`personel-belge-duzenle-${kayitId}`).click();
    const updatedAd = `${uniqueAd} Güncellendi`;
    await page.getByTestId("personel-belge-ad").fill(updatedAd);
    await page.getByTestId("personel-belge-edit-submit").click();
    await expect(panel.getByText(/Belge bilgileri güncellendi/i)).toBeVisible();
    await expect(panel.getByTestId("personel-belge-kayit-list")).toContainText(updatedAd);

    await panel.getByTestId(`personel-belge-dosya-degistir-${kayitId}`).click();
    await page.getByTestId("personel-belge-replace-dosya").setInputFiles({
      name: "s86-belge.pdf",
      mimeType: "application/pdf",
      buffer: Buffer.from("%PDF-1.4 s86")
    });
    await page.getByTestId("personel-belge-replace-submit").click();
    await expect(panel.getByText(/Belge dosyası güncellendi/i)).toBeVisible();

    await panel.getByTestId(`personel-belge-gecmis-${kayitId}`).click();
    await expect(page.getByTestId("personel-belge-history-list")).toContainText("Dosya değiştirildi");

    await page.getByTestId("personel-belge-history-close").click();
    await panel.getByTestId(`personel-belge-iptal-${kayitId}`).click();
    await page.getByTestId("personel-belge-cancel-neden").fill("S86 e2e iptal");
    await page.getByTestId("personel-belge-cancel-submit").click();
    await expect(panel.getByText(/Belge kaydı iptal edildi/i)).toBeVisible();
    await expect(panel.getByTestId("personel-belge-kayit-list")).not.toContainText(updatedAd);
  });

  test("BIRIM_AMIRI yazma butonlarını göremez", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, users.birimAmiri);

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const panel = page.locator("#personel-kart-panel-egitim-belgeler");
    await expect(panel.getByTestId("personel-belge-yeni-btn")).toHaveCount(0);
    await expect(panel.getByTestId("personel-belge-kayit-list")).toBeVisible();
    await expect(panel.locator('[data-testid^="personel-belge-duzenle-"]')).toHaveCount(0);
    await expect(panel.locator('[data-testid^="personel-belge-iptal-"]')).toHaveCount(0);
  });

  test("belge takip sayfasında süresi yaklaşan ve dolmuş rozetler görünür", async ({ page }) => {
    const belgeReferenceDate = new Date("2026-12-02T12:00:00.000Z");
    await page.clock.setFixedTime(belgeReferenceDate);
    await mockApi(page, "GENEL_YONETICI", { belgeReferenceDate });

    await login(page, users.genelYonetici);
    await page.goto("/personeller/belge-takip");

    await expect(page.getByTestId("belge-takip-page")).toBeVisible();
    await expect(page.getByTestId("belge-takip-table")).toContainText("Süresi yaklaşıyor");
    await expect(page.getByTestId("belge-takip-table")).toContainText("Süresi doldu");
  });

  test("geçersiz dosya tipi mesajı gösterilir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const panel = page.locator("#personel-kart-panel-egitim-belgeler");
    await panel.getByTestId("personel-belge-yeni-btn").click();
    await page.getByTestId("personel-belge-create-dosya").setInputFiles({
      name: "virus.exe",
      mimeType: "application/octet-stream",
      buffer: Buffer.from("bad")
    });
    await expect(page.getByTestId("personel-belge-file-error")).toContainText(
      /Bu dosya tipi yüklenemez/i
    );
  });
});
