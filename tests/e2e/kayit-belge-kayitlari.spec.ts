import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec belge kayitlari", () => {
  test("yonetici belge kaydi ekler ve personel kartinda read-only gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const uniqueAd = `E2E ISG Temel ${Date.now()}`;

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
    await expect(kayitModal.getByTestId("kayit-belge-kayitlari-section")).toBeVisible();

    await kayitModal.locator('[name="belge-kayit-tipi"]').selectOption("SERTIFIKA");
    await kayitModal.locator('[name="belge-kayit-ad"]').fill(uniqueAd);
    await kayitModal.locator('[name="belge-kayit-veren-kurum"]').fill("E2E Egitim Merkezi");
    await kayitModal.locator('[name="belge-kayit-belge-no"]').fill("E2E-2026-001");
    await kayitModal.locator('[name="belge-kayit-baslangic"]').fill("2026-01-01");
    await kayitModal.locator('[name="belge-kayit-bitis"]').fill("2028-01-01");
    await kayitModal.locator('[name="belge-kayit-ek-ref"]').fill("https://ornek.test/belge.pdf");
    await kayitModal.locator('[name="belge-kayit-aciklama"]').fill("E2E belge kaydi aciklama");

    await kayitModal.locator('button[type="submit"][form="kayit-surec-belge-kayitlari-form"]').click();
    await expect(kayitModal.getByText(/Belge kaydı eklendi/i)).toBeVisible({ timeout: 15_000 });
    await expect(kayitModal.getByTestId("kayit-belge-kayitlari-list")).toContainText(uniqueAd);

    await kayitModal.getByRole("button", { name: "Kapat" }).click();

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const belgelerPanel = page.locator("#personel-kart-panel-egitim-belgeler");
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toContainText(uniqueAd);
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toContainText("Sertifika");
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toContainText(/Aktif|Dosya eksik|Süresi/i);
    await expect(belgelerPanel.locator('input[type="radio"]')).toHaveCount(0);
    await expect(belgelerPanel.getByRole("button", { name: "Kayıt Ekle" })).toHaveCount(0);
  });

  test("yonetici belge kaydini iptal edince aktif listeden duser ve kart gecmisinde gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const uniqueAd = `E2E Iptal Sertifika ${Date.now()}`;

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
    await expect(kayitModal.getByTestId("kayit-belge-kayitlari-section")).toBeVisible();

    await kayitModal.locator('[name="belge-kayit-tipi"]').selectOption("SERTIFIKA");
    await kayitModal.locator('[name="belge-kayit-ad"]').fill(uniqueAd);
    await kayitModal.locator('[name="belge-kayit-baslangic"]').fill("2026-06-30");

    const createResponse = page.waitForResponse(
      (response) =>
        response.url().includes("/api/personeller/1/belge-kayitlari") &&
        response.request().method() === "POST"
    );
    await kayitModal.locator('button[type="submit"][form="kayit-surec-belge-kayitlari-form"]').click();
    expect((await createResponse).status()).toBe(201);
    await expect(kayitModal.getByText(/Belge kaydı eklendi/i)).toBeVisible({ timeout: 15_000 });

    const kayitList = kayitModal.getByTestId("kayit-belge-kayitlari-list");
    await expect(kayitList).toContainText(uniqueAd);
    await expect(kayitList).not.toContainText("SERTIFIKA");
    await expect(kayitList).not.toContainText("IPTAL");
    await expect(kayitList).not.toContainText("[object Object]");
    await expect(kayitList).not.toContainText('{"tip"');

    const uniqueRow = kayitList.locator("tr", { hasText: uniqueAd });
    page.once("dialog", (dialog) => dialog.accept("E2E iptal nedeni"));
    const cancelResponse = page.waitForResponse(
      (response) =>
        /\/api\/belge-kayitlari\/\d+\/iptal$/.test(new URL(response.url()).pathname) &&
        response.request().method() === "POST"
    );
    await uniqueRow.getByRole("button", { name: "İptal" }).click();
    expect((await cancelResponse).status()).toBe(200);

    await expect(kayitModal.getByText(/Belge kaydı iptal edildi/i)).toBeVisible({ timeout: 15_000 });
    await expect(kayitList).not.toContainText(uniqueAd);
    await expect(kayitList).not.toContainText("IPTAL");
    await expect(kayitList).not.toContainText("[object Object]");
    await expect(kayitList).not.toContainText('{"tip"');

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const belgelerPanel = page.locator("#personel-kart-panel-egitim-belgeler");
    const aktifListe = belgelerPanel.getByTestId("personel-belge-kayit-list");
    const iptalListe = belgelerPanel.getByTestId("personel-belge-kayit-iptal-list");

    await expect(aktifListe).toBeVisible();
    await expect(aktifListe).not.toContainText(uniqueAd);
    await expect(belgelerPanel.getByRole("heading", { name: "İptal edilen belge kayıtları" })).toBeVisible();
    await expect(iptalListe).toContainText(uniqueAd);
    await expect(iptalListe).toContainText("Sertifika");
    await expect(iptalListe).toContainText("İptal");
    await expect(iptalListe.getByRole("button")).toHaveCount(0);
    await expect(iptalListe).not.toContainText("SERTIFIKA");
    await expect(iptalListe).not.toContainText("IPTAL");
    await expect(iptalListe).not.toContainText("[object Object]");
    await expect(iptalListe).not.toContainText('{"tip"');
  });

  test("pasif personelde belge kaydi formu yazma kapalidir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Pasif");
    await kayitModal.getByRole("option", { name: /Pasif Ornek/i }).click();

    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
    await expect(kayitModal.locator(".surec-person-placeholder")).toContainText(/belge durumu güncellenmez/i);
    await expect(kayitModal.locator('[name="belge-kayit-ad"]')).toHaveCount(0);
    await expect(kayitModal.locator('button[type="submit"][form="kayit-surec-belge-kayitlari-form"]')).toHaveCount(0);
  });
});
