import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec Mali finans", () => {
  test("yonetici Mali sekmesinden finans kaydi olusturur ve Finans modulunde gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toBeVisible();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "Mali İşlemler" }).click();

    const uniqueDonem = "2031-07";
    const uniqueTutar = "88442.51";

    await kayitModal.locator('[name="kayit-mali-donem"]').fill(uniqueDonem);
    await kayitModal.locator('[name="kayit-mali-kalem"]').fill("AVANS");
    await kayitModal.locator('[name="kayit-mali-tutar"]').fill(uniqueTutar);
    await kayitModal.locator('[name="kayit-mali-aciklama"]').fill("E2E Mali kayit surec");

    await kayitModal.locator('button[type="submit"][form="kayit-surec-mali-form"]').click();

    await expect(kayitModal.locator('[name="kayit-mali-tutar"]')).toHaveValue("", { timeout: 15_000 });

    await kayitModal.getByRole("button", { name: "Kapat" }).click();

    await page.goto("/finans");
    await expect(page.locator(".modal-header h2").first()).toContainText("Finans");
    await expect(page.locator(".finans-list")).toContainText(uniqueTutar);
    await expect(page.locator(".finans-list")).toContainText("Personel: 1");
    await expect(page.locator(".finans-list")).toContainText(uniqueDonem);
  });
});
