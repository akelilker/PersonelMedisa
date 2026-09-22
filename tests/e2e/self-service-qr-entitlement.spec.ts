import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

/**
 * Kanonik QR/kart okutma entitlement'ı: hak uygulama rolüyle değil, bağlı
 * personel + DB collar read model'i ("Mavi Yaka") ile verilir. Yönetici rolü
 * korunur; kullanıcı sırf QR okutacak diye PERSONEL rolüne düşürülmez.
 */
test.describe("personnel-linked self-service QR entitlement", () => {
  test("bound Mavi Yaka BOLUM_YONETICISI keeps the manager menu and reaches /self + QR", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI", {
      personelBinding: { personel_id: 173, personel_tipi_ad: "Mavi Yaka" }
    });
    await login(page, MOCK_ROLE_LOGIN.BOLUM_YONETICISI);
    await expect(page).toHaveURL(/\/$/);

    // Yönetici menüsü bozulmaz: üç menü butonu ve yönetim girişleri yerinde.
    await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
    await expect(page.getByTestId("menu-kayit-surec")).toBeVisible();
    await expect(page.getByTestId("menu-personel-karti")).toBeVisible();
    await expect(page.getByTestId("menu-raporlar")).toBeVisible();

    // Normal navigasyonla kendi self-service/QR yüzeyine ulaşır.
    await expect(page.getByTestId("self-service-home-link")).toBeVisible();
    await expect(page.getByTestId("self-qr-scan-link")).toBeVisible();
    await expect(page.getByTestId("self-qr-history-link")).toBeVisible();

    await page.getByTestId("self-service-home-link").click();
    await expect(page).toHaveURL(/\/self$/);

    await page.goto("/self/qr-okut");
    await expect(page).toHaveURL(/\/self\/qr-okut$/);
  });

  test("bound Beyaz Yaka BOLUM_YONETICISI gets no QR surface but keeps manager + non-QR self", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI", {
      personelBinding: { personel_id: 173, personel_tipi_ad: "Beyaz Yaka" }
    });
    await login(page, MOCK_ROLE_LOGIN.BOLUM_YONETICISI);
    await expect(page).toHaveURL(/\/$/);

    await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
    await expect(page.getByTestId("self-service-qr-section")).toHaveCount(0);
    await expect(page.getByTestId("self-service-home-link")).toHaveCount(0);
    await expect(page.getByTestId("self-qr-scan-link")).toHaveCount(0);
    await expect(page.getByTestId("self-qr-history-link")).toHaveCount(0);

    // QR route guard permission-based: beyaz yaka yetkisiz sayfasına düşer.
    await page.goto("/self/qr-okut");
    await expect(page).toHaveURL(/\/yetkisiz$/);

    // Non-QR self-service yüzeyi (puantaj/izin) hâlâ erişilebilir.
    await page.goto("/self");
    await expect(page).toHaveURL(/\/self$/);
  });

  test("unbound manager never gets QR, even without a role change", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI", {
      personelBinding: { personel_id: null, personel_tipi_ad: null }
    });
    await login(page, MOCK_ROLE_LOGIN.BIRIM_AMIRI);
    await expect(page).toHaveURL(/\/$/);

    await expect(page.getByTestId("birim-amiri-operational-home")).toBeVisible();
    await expect(page.getByTestId("self-service-qr-section")).toHaveCount(0);
    await expect(page.getByTestId("self-qr-scan-link")).toHaveCount(0);

    await page.goto("/self/qr-okut");
    await expect(page).toHaveURL(/\/yetkisiz$/);
  });
});
