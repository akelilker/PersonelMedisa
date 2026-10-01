import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { installLinkedManagerSelfMeMocks } from "./helpers/linked-manager-self-me-mocks";
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
    await expect(page.getByTestId("home-self-service-gateway")).toBeVisible();
    await expect(page.getByTestId("self-qr-scan-link")).toBeVisible();
    await expect(page.getByTestId("self-qr-history-link")).toBeVisible();

    await page.getByTestId("home-self-service-gateway").click();
    await expect(page).toHaveURL(/\/self$/);

    await page.goto("/self/qr-okut");
    await expect(page).toHaveURL(/\/self\/qr-okut$/);
  });

  test("bound Beyaz Yaka BOLUM_YONETICISI: gateway without QR strip, linked /self + İzinlerim", async ({
    page
  }) => {
    const linkedName = "Deniz Kaya";
    await mockApi(page, "BOLUM_YONETICISI", {
      personelBinding: { personel_id: 173, personel_tipi_ad: "Beyaz Yaka" }
    });
    await installLinkedManagerSelfMeMocks(page, { personelAdSoyad: linkedName, personelId: 173 });
    await login(page, MOCK_ROLE_LOGIN.BOLUM_YONETICISI);
    await expect(page).toHaveURL(/\/$/);

    await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
    await expect(page.getByTestId("self-service-qr-section")).toHaveCount(0);
    await expect(page.getByTestId("home-self-service-gateway")).toBeVisible();
    await expect(page.getByTestId("self-qr-scan-link")).toHaveCount(0);
    await expect(page.getByTestId("self-qr-history-link")).toHaveCount(0);

    await page.getByTestId("home-self-service-gateway").click();
    await expect(page).toHaveURL(/\/self$/);
    await expect(page.getByText("Demo modda personel eşlemesi yok.")).toHaveCount(0);
    // /self ayrı bir "Öz Servis" paneli değil: aynı kanonik personel ekranı render edilir.
    await expect(page.getByTestId("personel-self-service-page")).toBeVisible();
    await expect(page.getByTestId("personel-self-identity")).toBeVisible();
    await expect(page.getByTestId("personel-self-identity")).toContainText(linkedName);
    // QR kapsamı dışı: QR alanı hiç mount edilmez, kapalı notu yok; kişisel menü kalır.
    await expect(page.getByTestId("personel-self-home-main")).toHaveCount(0);
    await expect(page.getByTestId("giris-scan")).toHaveCount(0);
    await expect(page.getByTestId("cikis-scan")).toHaveCount(0);
    await expect(page.getByTestId("personel-qr-closed-notice")).toHaveCount(0);
    await expect(page.getByTestId("personel-self-menu")).toBeVisible();

    await page.getByTestId("personel-menu-izinlerim").click();
    await expect(page).toHaveURL(/\/self\/izinlerim$/);
    await expect(page.getByRole("heading", { name: "İzinlerim" })).toBeVisible();

    await page.goto("/self/qr-okut");
    await expect(page).toHaveURL(/\/yetkisiz$/);
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
