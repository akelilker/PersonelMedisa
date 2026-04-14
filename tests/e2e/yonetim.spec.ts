import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("yonetim paneli ve aylik ozet", () => {
  test("genel yonetici ayarlar menusunden yonetim paneline gider, kullanici ekler ve sube tanimlar", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.getByTestId("header-settings-toggle").click();
    await expect(page.getByTestId("settings-yonetim-paneli")).toBeVisible();
    await expect(page.getByTestId("settings-aylik-ozet")).toHaveCount(0);

    await page.getByTestId("settings-yonetim-paneli").click();
    await expect(page).toHaveURL(/\/yonetim-paneli$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Yönetim Paneli");
    await expect(page.locator(".yonetim-toolbar-back")).toContainText("Ayarlar");
    await expect(page.locator(".yonetim-tabs")).toContainText("Kullanıcılar");

    await page.getByTestId("yonetim-kullanici-yeni").click();
    await expect(page.locator(".modal-header h2").last()).toContainText("Yeni Kullanıcı");
    await page.getByLabel("Kullanıcı Tipi").selectOption("HARICI");
    await page.getByLabel("Rol").selectOption("GENEL_YONETICI");
    await page.getByLabel("Ad Soyad").fill("Danışman Kullanıcı");
    await page.getByLabel("Telefon").fill("05559998877");
    await page.getByLabel("Notlar").fill("Dışarıdan danışman erişimi");
    await page.getByTestId("yonetim-kullanici-kaydet").click();

    await expect(page.getByText("Kullanıcı kaydı oluşturuldu.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid")).toContainText("Danışman Kullanıcı");
    await expect(page.locator(".yonetim-card-grid")).toContainText("Harici");

    await page.getByTestId("yonetim-tab-subeler").click();
    await expect(page.getByRole("button", { name: /\+ Yeni Şube/i })).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Merkez");

    await page.getByTestId("yonetim-sube-yeni").click();
    await page.getByLabel("Şube Kodu").fill("ANK");
    await page.getByLabel("Şube Adı").fill("Ankara");
    await page.locator(".yonetim-selection-grid").getByRole("button", { name: "Depo", exact: true }).click();
    await page.getByPlaceholder("Yeni departman adı").fill("Kalite");
    await page.getByRole("button", { name: "Departman ekle" }).click();
    await expect(page.locator(".yonetim-selection-grid")).toContainText("Kalite");
    await page.getByTestId("yonetim-sube-kaydet").click();

    await expect(page.getByText("Şube tanımı eklendi.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Ankara");
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Kalite");
  });

  test("bolum yoneticisi raporlardan aylik ozeti gorur, bolum onayi verir ve yonetim paneline giremez", async ({
    page
  }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, { username: "bolum_yonetici", password: "demo123" });

    await page.getByTestId("header-settings-toggle").click();
    await expect(page.getByTestId("settings-yonetim-paneli")).toHaveCount(0);
    await page.keyboard.press("Escape");

    await page.getByTestId("menu-raporlar").click();
    await expect(page).toHaveURL(/\/raporlar$/);
    await page.getByTestId("link-aylik-ozet").click();

    await expect(page).toHaveURL(/\/aylik-kapanis-ozeti$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Aylık Kapanış Özeti");
    await expect(page.locator(".raporlar-table tbody tr")).toHaveCount(2);

    await page.getByTestId("aylik-ozet-bolum-onay").click();
    await expect(page.getByText("Seçili ay için bölüm onayı verildi.")).toBeVisible();
    await expect(page.locator(".yonetim-summary-card").first()).toContainText(/Operasyonel Tamamlandı/i);

    await page.goto("/yonetim-paneli");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: /Yetkisiz/i })).toBeVisible();
  });
});
