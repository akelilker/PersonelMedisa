import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("personel dosyasi surec akisi", () => {
  test("yonetici surec ekler ve isten ayrilma personel durumunu pasife ceker", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kisisi kartini ac/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Surec Ekle" }).click();

    const surecModal = page.locator(".modal-container").last();
    await expect(surecModal).toBeVisible();

    if (await surecModal.locator("[name='personel-surec-turu']").count()) {
      await surecModal.locator("[name='personel-surec-turu']").selectOption("ISTEN_AYRILMA");
    } else {
      await surecModal.locator("[name='personel-surec-turu-text']").fill("ISTEN_AYRILMA");
    }

    await surecModal.locator("[name='personel-surec-baslangic']").fill("2026-04-12");
    await surecModal.locator("[name='personel-surec-aciklama']").fill("Is akdi sonlandirildi");
    await surecModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-dosya-hero")).toContainText(/Isten Ayrildi|Pasif/i);
    await expect(page.locator(".personel-surec-list")).toContainText(/Isten Ayr[\u0131i]lma/i);
    await expect(page.locator(".personel-surec-list")).toContainText("Is akdi sonlandirildi");
  });
});
