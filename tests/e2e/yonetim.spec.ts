import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("yonetim paneli ve aylik ozet", () => {
  test("genel yonetici ayarlar menusu uzerinden yonetim paneline gider ve harici kullanici ekler", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.getByTestId("header-settings-toggle").click();
    await expect(page.getByTestId("settings-yonetim-paneli")).toBeVisible();
    await expect(page.getByTestId("settings-aylik-ozet")).toBeVisible();

    await page.getByTestId("settings-yonetim-paneli").click();
    await expect(page).toHaveURL(/\/yonetim-paneli$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Yonetim Paneli");
    await expect(page.locator(".yonetim-page .yonetim-header-row h2")).toHaveText("Yonetim Paneli");

    await page.getByLabel("Kullanici Tipi").selectOption("HARICI");
    await page.getByLabel("Rol").selectOption("GENEL_YONETICI");
    await page.getByLabel("Ad Soyad").fill("Danisman Kullanici");
    await page.getByLabel("Telefon").fill("05559998877");
    await page.getByLabel("Notlar").fill("Disaridan danisman erisimi");
    await page.getByTestId("yonetim-kullanici-kaydet").click();

    await expect(page.getByText("Kullanici kaydi olusturuldu.")).toBeVisible();
    await expect(page.locator(".yonetim-entity-list")).toContainText("Danisman Kullanici");
    await expect(page.locator(".yonetim-entity-list")).toContainText("Harici");
  });

  test("bolum yoneticisi aylik ozeti gorur, bolum onayi verebilir ve yonetim paneline giremez", async ({
    page
  }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, { username: "bolum_yonetici", password: "demo123" });

    await page.getByTestId("header-settings-toggle").click();
    await expect(page.getByTestId("settings-aylik-ozet")).toBeVisible();
    await expect(page.getByTestId("settings-yonetim-paneli")).toHaveCount(0);

    await page.getByTestId("settings-aylik-ozet").click();
    await expect(page).toHaveURL(/\/aylik-kapanis-ozeti$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Aylik Kapanis Ozeti");
    await expect(page.locator(".yonetim-page .yonetim-header-row h2")).toHaveText("Aylik Kapanis Ozeti");
    await expect(page.locator(".raporlar-table tbody tr")).toHaveCount(2);

    await page.getByTestId("aylik-ozet-bolum-onay").click();
    await expect(page.getByText("Secili ay icin bolum onayi verildi.")).toBeVisible();
    await expect(page.locator(".yonetim-summary-card").first()).toContainText(/BOLUM_ONAYLANDI|Bolum Onaylandi/i);

    await page.goto("/yonetim-paneli");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: /Yetkisiz/i })).toBeVisible();
  });
});
