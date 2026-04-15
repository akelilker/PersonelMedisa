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

    await page.getByRole("link", { name: /Ayse Yilmaz.*kisisi kartini ac/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Personel Detayı");

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Puantaj");

    await page.getByLabel("Personel ID").fill("1");
    await page.getByLabel("Tarih").fill("2026-04-12");
    await page.getByRole("button", { name: /Kayd.*Getir/i }).click();

    await expect(page.getByText(/Hesaplandı/i)).toBeVisible();
    await expect(page.getByText(/510/)).toBeVisible();

    await page.locator("[name='puantaj-giris']").fill("08:30");
    await page.locator("[name='puantaj-cikis']").fill("18:00");
    await page.locator("[name='puantaj-mola']").fill("60");
    await page.getByRole("button", { name: "Kaydet" }).click();
    await expect(page.getByText(/570/)).toBeVisible();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/$/);

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");
    await page.getByRole("button", { name: /Raporu .*al.*/i }).click();
    await expect(page.getByTestId("raporlar-resmi-sonuc")).toContainText("1");
    await expect(page.getByTestId("raporlar-resmi-sonuc")).toContainText("sgk_prim_gun");
    await page.locator("[name='engine-turu']").selectOption("finans");
    await expect(page.locator("[name='engine-turu']")).toHaveValue("finans");
    const engineCard = page.locator(".raporlar-engine-card");
    await expect(engineCard).toBeVisible();
    await expect(engineCard.locator("h3.raporlar-engine-title")).toHaveText("Yardımcı önbellek aracı");
    await expect(engineCard.locator(".raporlar-engine-table")).toBeVisible();
  });

  test("birim amiri gunluk kayit girer ama puantaj ve kapanis tarafinda read-only kalir", async ({ page }) => {
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
    await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Kayıt Merkezi");

    const amirBildirimModal = page.locator(".modal-container").last();
    await expect(amirBildirimModal).toBeVisible();
    await amirBildirimModal.getByLabel("Tarih").fill("2026-04-11");
    await amirBildirimModal.getByLabel("Personel").selectOption("2");
    await amirBildirimModal.getByLabel("Kayit Senaryosu").selectOption("IZINSIZ_GELMEDI");
    await amirBildirimModal.getByLabel("Not / Aciklama").fill("Habersiz devamsizlik");
    await amirBildirimModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText(/[Iİ]zinsiz Gelmedi/i);
    await expect(page.locator(".bildirimler-list")).toContainText("Mehmet Kaya");

    await page.goto("/personeller");
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("button", { name: "Yeni Personel" })).toHaveCount(0);

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.getByRole("button", { name: "Kaydet" })).toBeDisabled();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/$/);

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
    await expect(page.locator(".modal-header h2").first()).toContainText("Süreç Takibi");

    await page.getByRole("button", { name: /Yeni S.*re.*/i }).click();
    const surecCreateModal = page.locator(".modal-container").last();
    await expect(surecCreateModal).toBeVisible();
    await surecCreateModal.locator("[name='surec-create-personel']").fill("1");
    if (await surecCreateModal.locator("[name='surec-create-turu']").count()) {
      await surecCreateModal.locator("[name='surec-create-turu']").selectOption("RAPOR");
    } else {
      await surecCreateModal.locator("[name='surec-create-turu-text']").fill("RAPOR");
    }
    await surecCreateModal.locator("[name='surec-create-bas']").fill("2026-04-12");
    await surecCreateModal.locator("[name='surec-create-bitis']").fill("2026-04-12");
    await surecCreateModal.locator("[name='surec-create-aciklama']").fill("Yeni surec kaydi");
    await surecCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText(/Rapor/i);

    await page.locator(".surecler-list .module-item-actions button").first().click();
    const surecEditModal = page.locator(".modal-container").last();
    await expect(surecEditModal).toBeVisible();
    if (await surecEditModal.locator("[name='surec-edit-turu']").count()) {
      await surecEditModal.locator("[name='surec-edit-turu']").selectOption("RAPOR");
    } else {
      await surecEditModal.locator("[name='surec-edit-turu-text']").fill("RAPOR_GUNCEL");
    }
    await surecEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText(/Rapor/i);

    page.once("dialog", (dialog) => void dialog.accept());
    await page.locator(".surecler-list .module-item-actions button").nth(1).click();
    await expect(page.locator(".surecler-list")).toContainText(/İptal|Iptal/i);

    await page.goto("/bildirimler");
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Kayıt Merkezi");

    await page.getByRole("button", { name: "Yeni Gunluk Kayit" }).click();
    const bildirimCreateModal = page.locator(".modal-container").last();
    await expect(bildirimCreateModal).toBeVisible();
    await bildirimCreateModal.getByLabel("Tarih").fill("2026-04-11");
    await bildirimCreateModal.getByLabel("Personel").selectOption("1");
    await bildirimCreateModal.getByLabel("Kayit Senaryosu").selectOption("DEVAMSIZLIK");
    await bildirimCreateModal.getByLabel("Not / Aciklama").fill("Yeni gunluk kayit");
    await bildirimCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText(/Devams/i);

    await page.getByRole("button", { name: /Düzenle|Duzenle/i }).first().click();
    const bildirimEditModal = page.locator(".modal-container").last();
    await expect(bildirimEditModal).toBeVisible();
    await bildirimEditModal.getByLabel("Kayit Senaryosu").selectOption("RAPORLU");
    await bildirimEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText("Raporlu");

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: /İptal|Iptal/i }).first().click();
    await expect(page.locator(".bildirimler-list")).toContainText(/Kayit Durumu: .*ptal/i);

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
    await expect(page.locator(".finans-list")).toContainText(/İptal|Iptal/i);
  });
});
