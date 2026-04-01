import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

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
    await expect(page.getByRole("heading", { name: "Personel Detay" })).toBeVisible();

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);

    await page.getByLabel("Personel ID").fill("1");
    await page.getByLabel("Tarih").fill("2026-04-12");
    await page.getByRole("button", { name: "Kaydi Getir" }).click();

    await expect(page.getByText("HESAPLANDI")).toBeVisible();
    await expect(page.getByText("Net Calisma (dk): 510")).toBeVisible();

    await page.getByLabel("Giris Saati").fill("08:30");
    await page.getByLabel("Cikis Saati").fill("18:00");
    await page.getByLabel("Gercek Mola (dk)").fill("60");
    await page.getByRole("button", { name: "Kaydet" }).click();
    await expect(page.getByText("Gunluk Brut Sure (dk): 570")).toBeVisible();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/haftalik-kapanis$/);

    await page.getByLabel("Hafta Baslangic").fill("2026-04-06");
    await page.getByLabel("Hafta Bitis").fill("2026-04-12");
    await page.getByLabel("Departman ID (Opsiyonel)").fill("3");
    await page.getByRole("button", { name: "Haftayi Kapat" }).click();

    await expect(page.getByText("Durum: KAPANDI")).toBeVisible();
    await expect(page.getByText("Kapanis ID: 99")).toBeVisible();

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await page.getByRole("button", { name: "Raporu Calistir" }).click();
    await expect(page.getByText("Toplam Kayit: 1")).toBeVisible();
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
    await expect(page.getByText("Bu modulu sadece goruntuleme yetkin var.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Kaydet" })).toBeDisabled();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: "Yetkisiz Erisim" })).toBeVisible();

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.getByRole("heading", { name: "Raporlar" })).toBeVisible();

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: "Yetkisiz Erisim" })).toBeVisible();
  });

  test("management user can create update and cancel surec bildirim and finans", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await expect(page).toHaveURL("/");

    await page.goto("/surecler");
    await expect(page).toHaveURL(/\/surecler$/);
    await expect(page.getByRole("heading", { name: "Surec Takibi" })).toBeVisible();

    await page.getByRole("button", { name: "Yeni Surec" }).click();
    const surecCreateModal = page.locator(".modal-container").last();
    await expect(surecCreateModal).toBeVisible();
    await surecCreateModal.getByLabel("Personel ID").fill("1");
    await surecCreateModal.getByLabel("Surec Turu").fill("RAPOR");
    await surecCreateModal.getByLabel("Baslangic Tarihi").fill("2026-04-12");
    await surecCreateModal.getByLabel("Bitis Tarihi").fill("2026-04-12");
    await surecCreateModal.getByLabel("Aciklama").fill("Yeni surec kaydi");
    await surecCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText("RAPOR");

    await page.getByRole("button", { name: "Duzenle" }).first().click();
    const surecEditModal = page.locator(".modal-container").last();
    await expect(surecEditModal).toBeVisible();
    await surecEditModal.getByLabel("Surec Turu").fill("RAPOR_GUNCEL");
    await surecEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText("RAPOR_GUNCEL");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "Iptal" }).first().click();
    await expect(page.locator(".surecler-list")).toContainText("Durum: IPTAL");

    await page.goto("/bildirimler");
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.getByRole("heading", { name: "Bildirimler" })).toBeVisible();

    await page.getByRole("button", { name: "Yeni Bildirim" }).click();
    const bildirimCreateModal = page.locator(".modal-container").last();
    await expect(bildirimCreateModal).toBeVisible();
    await bildirimCreateModal.getByLabel("Tarih").fill("2026-04-11");
    await bildirimCreateModal.getByLabel("Departman ID").fill("3");
    await bildirimCreateModal.getByLabel("Personel ID").fill("1");
    await bildirimCreateModal.getByLabel("Bildirim Turu").fill("DEVAMSIZLIK");
    await bildirimCreateModal.getByLabel("Aciklama").fill("Yeni bildirim kaydi");
    await bildirimCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("DEVAMSIZLIK");

    await page.getByRole("button", { name: "Duzenle" }).first().click();
    const bildirimEditModal = page.locator(".modal-container").last();
    await expect(bildirimEditModal).toBeVisible();
    await bildirimEditModal.getByLabel("Bildirim Turu").fill("RAPORLU");
    await bildirimEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("RAPORLU");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "Iptal" }).first().click();
    await expect(page.locator(".bildirimler-list")).toContainText("IPTAL_EDILDI");

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/finans$/);
    await expect(page.getByRole("heading", { name: "Finans" })).toBeVisible();

    await page.getByRole("button", { name: "Yeni Finans Kalemi" }).click();
    const finansCreateModal = page.locator(".modal-container").last();
    await expect(finansCreateModal).toBeVisible();
    await finansCreateModal.getByLabel("Personel ID").fill("1");
    await finansCreateModal.getByLabel("Donem").fill("2026-04");
    await finansCreateModal.getByLabel("Kalem Turu").fill("PRIM");
    await finansCreateModal.getByLabel("Tutar").fill("1500");
    await finansCreateModal.getByLabel("Aciklama").fill("Yeni finans kalemi");
    await finansCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText("PRIM");

    await page.getByRole("button", { name: "Duzenle" }).first().click();
    const finansEditModal = page.locator(".modal-container").last();
    await expect(finansEditModal).toBeVisible();
    await finansEditModal.getByLabel("Kalem Turu").fill("CEZA");
    await finansEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText("CEZA");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "Iptal" }).first().click();
    await expect(page.locator(".finans-list")).toContainText("Durum: IPTAL");
  });
});
