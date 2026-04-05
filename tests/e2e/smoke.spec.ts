import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("e2e smoke", () => {
  test("management user completes login to kapanis flow", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await expect(page).toHaveURL("/");
    await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
    await expect(page.getByTestId("menu-kayit-surec")).toBeVisible();
    await expect(page.getByTestId("menu-personel-karti")).toBeVisible();
    await expect(page.getByTestId("menu-raporlar")).toBeVisible();

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();
    await expect(page.getByText("Ayse Yilmaz")).toBeVisible();

    await page.getByRole("link", { name: "Detay" }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Personel Detayi");

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Gunluk Puantaj");

    await page.getByLabel("Personel ID").fill("1");
    await page.getByLabel("Tarih").fill("2026-04-12");
    await page.getByRole("button", { name: /Kayd.*Getir/i }).click();

    await expect(page.getByText(/Hesapland/i)).toBeVisible();
    await expect(page.getByText(/510/)).toBeVisible();

    await page.locator("[name='puantaj-giris']").fill("08:30");
    await page.locator("[name='puantaj-cikis']").fill("18:00");
    await page.locator("[name='puantaj-mola']").fill("60");
    await page.getByRole("button", { name: "Kaydet" }).click();
    await expect(page.getByText(/570/)).toBeVisible();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/haftalik-kapanis$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Haftalik Kapanis");

    await page.locator("[name='kapanis-bas']").fill("2026-04-06");
    await page.locator("[name='kapanis-bitis']").fill("2026-04-12");
    await page.locator("[name='kapanis-departman']").fill("3");
    await page.locator(".form-filter-panel button[type='submit']").click();

    await expect(page.getByText(/Durum: /)).toBeVisible();
    await expect(page.getByText(/Kapan.. ID:|KapanÄ±ÅŸ ID:|Kapanis ID:/)).toBeVisible();

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");
    await page.getByRole("button", { name: /Raporu .*al.*/i }).click();
    await expect(page.locator(".raporlar-result-card")).toContainText("1");
  });

  test("birim amiri gunluk durum bildirir ama puantaj ve kapanis tarafinda read-only kalir", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");

    await login(page, { username: "birim", password: "secret" });

    await expect(page).toHaveURL("/");
    await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
    await expect(page.getByTestId("menu-gunluk-durum")).toBeVisible();
    await expect(page.getByTestId("menu-personel-karti")).toBeVisible();
    await expect(page.getByTestId("menu-raporlar")).toBeVisible();
    await expect(page.getByTestId("menu-kayit-surec")).toHaveCount(0);

    await page.getByTestId("menu-gunluk-durum").click();
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Bildirimler");

    const amirBildirimModal = page.locator(".modal-container").last();
    await expect(amirBildirimModal).toBeVisible();
    await amirBildirimModal.getByLabel("Tarih").fill("2026-04-11");
    await amirBildirimModal.getByLabel("Personel").selectOption("2");
    await amirBildirimModal.getByLabel("Durum").selectOption("IZINSIZ_GELMEDI");
    await amirBildirimModal.getByLabel("Aciklama").fill("Habersiz devamsizlik");
    await amirBildirimModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("Izinsiz Gelmedi");
    await expect(page.locator(".bildirimler-list")).toContainText("Mehmet Kaya");

    await page.goto("/personeller");
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("button", { name: "Yeni Personel" })).toHaveCount(0);

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.getByRole("button", { name: "Kaydet" })).toBeDisabled();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: /Yetkisiz/i })).toBeVisible();

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: /Yetkisiz/i })).toBeVisible();
  });

  test("management user can create update and cancel surec bildirim and finans", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await expect(page).toHaveURL("/");

    await page.goto("/surecler");
    await expect(page).toHaveURL(/\/surecler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Surec Takibi");

    await page.getByRole("button", { name: /Yeni S.*re.*/i }).click();
    const surecCreateModal = page.locator(".modal-container").last();
    await expect(surecCreateModal).toBeVisible();
    await surecCreateModal.locator("[name='surec-create-personel']").fill("1");
    await surecCreateModal.locator("[name='surec-create-turu-text']").fill("RAPOR");
    await surecCreateModal.locator("[name='surec-create-bas']").fill("2026-04-12");
    await surecCreateModal.locator("[name='surec-create-bitis']").fill("2026-04-12");
    await surecCreateModal.locator("[name='surec-create-aciklama']").fill("Yeni surec kaydi");
    await surecCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText(/Rapor/i);

    await page.locator(".surecler-list .module-item-actions button").first().click();
    const surecEditModal = page.locator(".modal-container").last();
    await expect(surecEditModal).toBeVisible();
    await surecEditModal.locator("[name='surec-edit-turu-text']").fill("RAPOR_GUNCEL");
    await surecEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText(/Rapor Guncel/i);

    page.once("dialog", (dialog) => void dialog.accept());
    await page.locator(".surecler-list .module-item-actions button").nth(1).click();
    await expect(page.locator(".surecler-list")).toContainText(/Iptal|Ä°ptal/i);

    await page.goto("/bildirimler");
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Bildirimler");

    await page.getByRole("button", { name: "Yeni Bildirim" }).click();
    const bildirimCreateModal = page.locator(".modal-container").last();
    await expect(bildirimCreateModal).toBeVisible();
    await bildirimCreateModal.getByLabel("Tarih").fill("2026-04-11");
    await bildirimCreateModal.getByLabel("Personel").selectOption("1");
    await bildirimCreateModal.getByLabel("Durum").selectOption("DEVAMSIZLIK");
    await bildirimCreateModal.getByLabel("Aciklama").fill("Yeni bildirim kaydi");
    await bildirimCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("Devamsizlik");

    await page.getByRole("button", { name: "Duzenle" }).first().click();
    const bildirimEditModal = page.locator(".modal-container").last();
    await expect(bildirimEditModal).toBeVisible();
    await bildirimEditModal.getByLabel("Durum").selectOption("RAPORLU");
    await bildirimEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("Raporlu");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: "Iptal" }).first().click();
    await expect(page.locator(".bildirimler-list")).toContainText("Durum: Iptal");

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/finans$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Finans");

    await page.getByRole("button", { name: /Yeni Finans Kalemi/i }).click();
    const finansCreateModal = page.locator(".modal-container").last();
    await expect(finansCreateModal).toBeVisible();
    await finansCreateModal.locator("[name='finans-create-personel']").fill("1");
    await finansCreateModal.locator("[name='finans-create-donem']").fill("2026-04");
    await finansCreateModal.locator("[name='finans-create-kalem']").fill("PRIM");
    await finansCreateModal.locator("[name='finans-create-tutar']").fill("1500");
    await finansCreateModal.locator("[name='finans-create-aciklama']").fill("Yeni finans kalemi");
    await finansCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText(/Prim/i);

    await page.locator(".finans-list .module-item-actions button").first().click();
    const finansEditModal = page.locator(".modal-container").last();
    await expect(finansEditModal).toBeVisible();
    await finansEditModal.locator("[name='finans-edit-kalem']").fill("CEZA");
    await finansEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText(/Ceza/i);

    page.once("dialog", (dialog) => void dialog.accept());
    await page.locator(".finans-list .module-item-actions button").nth(1).click();
    await expect(page.locator(".finans-list")).toContainText(/Iptal|Ä°ptal/i);
  });
});
