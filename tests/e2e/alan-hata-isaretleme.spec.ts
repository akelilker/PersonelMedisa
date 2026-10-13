import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

// t869 onaylı karar: doğrulama hatası alan adını yazar (Title Case), ilgili alan kırmızı
// çerçeve alır ve odaklanır; kullanıcı alanı düzenleyince işaret kalkar.
test.describe("alan hata işaretleme (t869)", () => {
  test("backend alan hatası: T.C. Kimlik No. adı, kırmızı çerçeve, odak, düzenleyince temizlenir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/1");
    await page.getByTestId("personel-eksik-bilgi-alan-bolum_id").click();
    const modal = page.locator(".modal-container").filter({
      has: page.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })
    });
    await expect(modal).toBeVisible();
    await modal.getByRole("tab", { name: "Genel" }).click();
    await modal.getByTestId("kayit-surec-personel-duzenle").click();

    await page.route(/\/api\/personeller\/1(\?.*)?$/, async (route) => {
      const method = route.request().method();
      if (method === "PUT" || method === "PATCH") {
        await route.fulfill({
          status: 422,
          contentType: "application/json",
          body: JSON.stringify({
            data: null,
            meta: {},
            errors: [{ code: "VALIDATION_ERROR", message: "tc_kimlik_no gecersiz.", field: "tc_kimlik_no" }]
          })
        });
        return;
      }
      await route.fallback();
    });

    await modal.locator('[name="edit-tc-kimlik-no"]').fill("12345678901");
    await modal.getByRole("button", { name: "Kaydet", exact: true }).click();

    await expect(modal.getByText("T.C. Kimlik No. Geçerli Değil. Lütfen Kontrol Edin.")).toBeVisible();
    const tc = modal.locator('[name="edit-tc-kimlik-no"]');
    await expect(tc).toHaveAttribute("aria-invalid", "true");
    await expect(tc).toBeFocused();
    const border = await tc.evaluate((el) => getComputedStyle(el).borderTopColor);
    const [r, g, b] = (border.match(/\d+/g) ?? []).map(Number);
    expect(r).toBeGreaterThan(180);
    expect(g).toBeLessThan(110);
    expect(b).toBeLessThan(110);

    await tc.fill("12345678902");
    await expect(tc).not.toHaveAttribute("aria-invalid", "true");
  });
});
