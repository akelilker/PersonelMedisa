import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("BIRIM_AMIRI operational home", () => {
  test("self-service home shows own info, scoped unit list, and daily notification CTA", async ({ page }) => {
    // Bound ama Beyaz Yaka: yönetim yetkisi tam, QR/kart hakkı yok (fail-closed).
    await mockApi(page, "BIRIM_AMIRI", {
      personelBinding: { personel_id: 1, personel_tipi_ad: "Beyaz Yaka" }
    });
    await login(page, MOCK_ROLE_LOGIN.BIRIM_AMIRI);
    await expect(page).toHaveURL(/\/$/);

    await expect(page.getByTestId("birim-amiri-operational-home")).toBeVisible();
    await expect(page.getByTestId("birim-amiri-own-info")).toContainText("Mock Kullanıcı");
    await expect(page.getByTestId("birim-amiri-unit-section")).toBeVisible();
    await expect(page.getByTestId("birim-amiri-unit-summary")).toBeVisible();
    await expect(page.getByTestId("birim-person-1")).toContainText(/Ayşe Yılmaz/i);
    await expect(page.getByTestId("birim-person-4")).toContainText(/Maas Eksik/i);
    await expect(page.getByTestId("birim-amiri-person-list")).not.toContainText(/Mehmet Kaya/i);
    await expect(page.getByTestId("birim-amiri-person-list")).not.toContainText(/Ucuncu Sube/i);

    await expect(page.getByTestId("menu-kayit-surec")).toHaveCount(0);
    await expect(page.getByTestId("menu-personel-karti")).toHaveCount(0);
    await expect(page.getByTestId("menu-raporlar")).toHaveCount(0);
    // Canonical: mavi yaka değil → QR bölümü ve CTA hiç render edilmez.
    await expect(page.getByTestId("self-service-qr-section")).toHaveCount(0);
    await expect(page.getByTestId("self-qr-scan-link")).toHaveCount(0);
    await expect(page.getByTestId("giris-scan")).toHaveCount(0);

    await expect(page.getByTestId("birim-amiri-edit-daily")).toBeVisible();
    await expect(page.getByTestId("birim-amiri-complete-daily")).toBeVisible();
    await page.getByTestId("birim-amiri-edit-daily").click();
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.getByRole("dialog", { name: "Günlük Kayıt Merkezi" })).toBeVisible();
  });

  test("bound Mavi Yaka BIRIM_AMIRI reaches its own QR surface from the operational home", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI", {
      personelBinding: { personel_id: 1, personel_tipi_ad: "Mavi Yaka" }
    });
    await login(page, MOCK_ROLE_LOGIN.BIRIM_AMIRI);
    await expect(page).toHaveURL(/\/$/);

    // Rol düşürülmez: operasyonel yönetim yüzeyi aynen kalır.
    await expect(page.getByTestId("birim-amiri-operational-home")).toBeVisible();
    await expect(page.getByTestId("birim-amiri-edit-daily")).toBeVisible();

    await expect(page.getByTestId("self-service-qr-section")).toBeVisible();
    await expect(page.getByTestId("self-qr-scan-link")).toBeVisible();
    await expect(page.getByTestId("self-qr-history-link")).toBeVisible();
    await page.getByTestId("self-qr-scan-link").click();
    await expect(page).toHaveURL(/\/self\/qr-okut$/);
  });

  test("PERSONEL home stays self-service without unit panel", async ({ page }) => {
    await mockApi(page, "PERSONEL");
    await login(page, MOCK_ROLE_LOGIN.PERSONEL);
    await expect(page.getByTestId("birim-amiri-operational-home")).toHaveCount(0);
    await expect(page.getByTestId("birim-amiri-unit-section")).toHaveCount(0);
    await expect(page.locator("#main-menu")).toHaveCount(0);
  });

  test("GENEL_YONETICI keeps manager home", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await expect(page.getByTestId("menu-kayit-surec")).toBeVisible();
    await expect(page.getByTestId("birim-amiri-operational-home")).toHaveCount(0);
  });
});
