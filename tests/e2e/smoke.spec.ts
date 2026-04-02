import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

function modalRouteHeading(page: Page, name: string) {
  return page.locator(".modal-header").first().getByRole("heading", { name });
}

test.describe("e2e smoke", () => {
  test("management user completes login to kapanis flow", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await expect(page).toHaveURL("/");
    await expect(page.getByTestId("menu-personel-karti")).toBeVisible();

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();
    await expect(page.getByText("Ayse Yilmaz")).toBeVisible();

    await page.getByRole("link", { name: "Detay" }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(modalRouteHeading(page, "Personel Detayı")).toBeVisible();

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);

    await page.getByLabel("Personel ID").fill("1");
    await page.getByLabel("Tarih").fill("2026-04-12");
    await page.getByRole("button", { name: "Kaydı Getir" }).click();

    await expect(page.getByText("Hesaplandı")).toBeVisible();
    await expect(page.getByText("Net Çalışma (dk): 510")).toBeVisible();

    await page.getByLabel("Giriş Saati").fill("08:30");
    await page.getByLabel("Çıkış Saati").fill("18:00");
    await page.getByLabel("Gerçek Mola (dk)").fill("60");
    await page.getByRole("button", { name: "Kaydet" }).click();
    await expect(page.getByText("Günlük Brüt Süre (dk): 570")).toBeVisible();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/haftalik-kapanis$/);

    await page.getByLabel("Hafta Başlangıç").fill("2026-04-06");
    await page.getByLabel("Hafta Bitiş").fill("2026-04-12");
    await page.getByLabel("Departman ID (Opsiyonel)").fill("3");
    await page.getByRole("button", { name: "Haftayı Kapat" }).click();

    await expect(page.getByText("Durum: Kapandı")).toBeVisible();
    await expect(page.getByText("Kapanış ID: 99")).toBeVisible();

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await page.getByRole("button", { name: "Raporu Çalıştır" }).click();
    await expect(page.getByText("Toplam Kayıt: 1")).toBeVisible();
  });

  test("birim amiri remains read-only and cannot access kapanis route", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");

    await login(page, { username: "birim", password: "secret" });

    await expect(page).toHaveURL("/");

    await page.goto("/personeller");
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("button", { name: "Yeni Personel" })).toHaveCount(0);

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.getByText("Bu modülü sadece görüntüleme yetkin var.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Kaydet" })).toBeDisabled();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: "Yetkisiz Erişim" })).toBeVisible();

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(modalRouteHeading(page, "Raporlar")).toBeVisible();

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: "Yetkisiz Erişim" })).toBeVisible();
  });

  test("management user can create update and cancel surec bildirim and finans", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await expect(page).toHaveURL("/");

    await page.goto("/surecler");
    await expect(page).toHaveURL(/\/surecler$/);
    await expect(modalRouteHeading(page, "Süreç Takibi")).toBeVisible();

    await page.getByRole("button", { name: "Yeni Süreç" }).click();
    const surecCreateModal = page.locator(".modal-container").last();
    await expect(surecCreateModal).toBeVisible();
    await surecCreateModal.getByLabel("Personel ID").fill("1");
    await surecCreateModal.getByLabel("Süreç Türü").fill("RAPOR");
    await surecCreateModal.getByLabel("Başlangıç Tarihi").fill("2026-04-12");
    await surecCreateModal.getByLabel("Bitiş Tarihi").fill("2026-04-12");
    await surecCreateModal.getByLabel("Açıklama").fill("Yeni süreç kaydı");
    await surecCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText("Rapor");

    await page.getByRole("button", { name: "Düzenle" }).first().click();
    const surecEditModal = page.locator(".modal-container").last();
    await expect(surecEditModal).toBeVisible();
    await surecEditModal.getByLabel("Süreç Türü").fill("RAPOR_GUNCEL");
    await surecEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText("Rapor Guncel");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "İptal" }).first().click();
    await expect(page.locator(".surecler-list")).toContainText("Durum: İptal");

    await page.goto("/bildirimler");
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(modalRouteHeading(page, "Bildirimler")).toBeVisible();

    await page.getByRole("button", { name: "Yeni Bildirim" }).click();
    const bildirimCreateModal = page.locator(".modal-container").last();
    await expect(bildirimCreateModal).toBeVisible();
    await bildirimCreateModal.getByLabel("Tarih").fill("2026-04-11");
    await bildirimCreateModal.getByLabel("Departman ID").fill("3");
    await bildirimCreateModal.getByLabel("Personel ID").fill("1");
    await bildirimCreateModal.getByLabel("Bildirim Turu").fill("DEVAMSIZLIK");
    await bildirimCreateModal.getByLabel("Açıklama").fill("Yeni bildirim kaydı");
    await bildirimCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("Devamsızlık");

    await page.getByRole("button", { name: "Düzenle" }).first().click();
    const bildirimEditModal = page.locator(".modal-container").last();
    await expect(bildirimEditModal).toBeVisible();
    await bildirimEditModal.getByLabel("Bildirim Turu").fill("RAPORLU");
    await bildirimEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("Raporlu");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "İptal" }).first().click();
    await expect(page.locator(".bildirimler-list")).toContainText("İptal Edildi");

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/finans$/);
    await expect(modalRouteHeading(page, "Finans")).toBeVisible();

    await page.getByRole("button", { name: "Yeni Finans Kalemi" }).click();
    const finansCreateModal = page.locator(".modal-container").last();
    await expect(finansCreateModal).toBeVisible();
    await finansCreateModal.getByLabel("Personel ID").fill("1");
    await finansCreateModal.getByLabel("Dönem").fill("2026-04");
    await finansCreateModal.getByLabel("Kalem Turu").fill("PRIM");
    await finansCreateModal.getByLabel("Tutar").fill("1500");
    await finansCreateModal.getByLabel("Açıklama").fill("Yeni finans kalemi");
    await finansCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText("Prim");

    await page.getByRole("button", { name: "Düzenle" }).first().click();
    const finansEditModal = page.locator(".modal-container").last();
    await expect(finansEditModal).toBeVisible();
    await finansEditModal.getByLabel("Kalem Turu").fill("CEZA");
    await finansEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText("Ceza");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "İptal" }).first().click();
    await expect(page.locator(".finans-list")).toContainText("Durum: İptal");
  });
});
