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
    await expect(page.getByRole("heading", { name: /Personel Detay/i })).toBeVisible();

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.getByRole("heading", { name: /Puantaj/i })).toBeVisible();

    await page.getByLabel("Personel ID").fill("1");
    await page.getByLabel("Tarih").fill("2026-04-12");
    await page.getByRole("button", { name: /Kayd.*Getir/i }).click();

    await expect(page.getByText(/Hesapland/i)).toBeVisible();
    await expect(page.getByText(/510/)).toBeVisible();

    await page.getByLabel(/Giri.* Saati/i).fill("08:30");
    await page.getByLabel(/(Ciki.* Saati|Çıkış Saati)/i).fill("18:00");
    await page.getByLabel(/Ger.* Mola .*dk/i).fill("60");
    await page.getByRole("button", { name: "Kaydet" }).click();
    await expect(page.getByText(/570/)).toBeVisible();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/haftalik-kapanis$/);
    await expect(page.getByRole("heading", { name: /Haftalik Kapanis|Haftalık Kapanış/i })).toBeVisible();

    await page.getByLabel("Hafta Başlangıç").fill("2026-04-06");
    await page.getByLabel("Hafta Bitiş").fill("2026-04-12");
    await page.getByLabel("Departman ID (Opsiyonel)").fill("3");
    await page.getByRole("button", { name: "Haftayı Kapat" }).click();

    await expect(page.getByText(/Durum: /)).toBeVisible();
    await expect(page.getByText(/Kapan.. ID:|Kapanış ID:|Kapanis ID:/)).toBeVisible();

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.getByRole("heading", { name: "Raporlar" })).toBeVisible();
    await page.getByRole("button", { name: /Raporu .*al.*/i }).click();
    await expect(page.getByText(/Toplam Kayit|Toplam Kayıt/i)).toBeVisible();
  });

  test("birim amiri gunluk durum bildirir ama puantaj ve kapanis tarafinda read-only kalir", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");

    await login(page, { username: "birim", password: "secret" });

    await expect(page).toHaveURL("/");
    await expect(page.getByTestId("menu-gunluk-durum")).toBeVisible();

    await page.getByTestId("menu-gunluk-durum").click();
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.getByRole("heading", { name: "Bildirimler" })).toBeVisible();

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
    await expect(page.getByRole("heading", { name: "Raporlar" })).toBeVisible();

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
    await expect(page.getByRole("heading", { name: /Surec Takibi|Süreç Takibi/i })).toBeVisible();

    await page.getByRole("button", { name: /Yeni S.*re.*/i }).click();
    const surecCreateModal = page.locator(".modal-container").last();
    await expect(surecCreateModal).toBeVisible();
    await surecCreateModal.getByLabel("Personel ID").fill("1");
    await surecCreateModal.getByLabel(/Surec Turu|Süreç Türü/i).fill("RAPOR");
    await surecCreateModal.getByLabel(/Baslangic Tarihi|Başlangıç Tarihi/i).fill("2026-04-12");
    await surecCreateModal.getByLabel(/Bitis Tarihi|Bitiş Tarihi/i).fill("2026-04-12");
    await surecCreateModal.getByLabel(/Aciklama|Açıklama/i).fill("Yeni surec kaydi");
    await surecCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText(/Rapor/i);

    await page.getByRole("button", { name: /Duzenle|Düzenle/i }).first().click();
    const surecEditModal = page.locator(".modal-container").last();
    await expect(surecEditModal).toBeVisible();
    await surecEditModal.getByLabel(/Surec Turu|Süreç Türü/i).fill("RAPOR_GUNCEL");
    await surecEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText(/Rapor Guncel/i);

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: /Iptal|İptal/i }).first().click();
    await expect(page.locator(".surecler-list")).toContainText(/Iptal|İptal/i);

    await page.goto("/bildirimler");
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.getByRole("heading", { name: "Bildirimler" })).toBeVisible();

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
    await expect(page.getByRole("heading", { name: "Finans" })).toBeVisible();

    await page.getByRole("button", { name: /Yeni Finans Kalemi/i }).click();
    const finansCreateModal = page.locator(".modal-container").last();
    await expect(finansCreateModal).toBeVisible();
    await finansCreateModal.getByLabel("Personel ID").fill("1");
    await finansCreateModal.getByLabel(/Donem|Dönem/i).fill("2026-04");
    await finansCreateModal.getByLabel(/Kalem Turu/i).fill("PRIM");
    await finansCreateModal.getByLabel("Tutar").fill("1500");
    await finansCreateModal.getByLabel(/Aciklama|Açıklama/i).fill("Yeni finans kalemi");
    await finansCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText(/Prim/i);

    await page.getByRole("button", { name: /Duzenle|Düzenle/i }).first().click();
    const finansEditModal = page.locator(".modal-container").last();
    await expect(finansEditModal).toBeVisible();
    await finansEditModal.getByLabel(/Kalem Turu/i).fill("CEZA");
    await finansEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText(/Ceza/i);

    page.once("dialog", (dialog) => void dialog.accept());
    await page.getByRole("button", { name: /Iptal|İptal/i }).first().click();
    await expect(page.locator(".finans-list")).toContainText(/Iptal|İptal/i);
  });
});
