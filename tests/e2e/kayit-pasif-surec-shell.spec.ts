import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec pasif personel shell sekmeleri", () => {
  test("pasif personelde Izin Pozisyon Mali Zimmet Ceza formlari yerine placeholder", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Pasif");
    await kayitModal.getByRole("option", { name: /Pasif Ornek/i }).click();

    await kayitModal.getByRole("tab", { name: "İzin / Devamsızlık" }).click();
    const izinPlaceholder = kayitModal.locator(".surec-person-placeholder").filter({ hasText: /İzin \/ Devamsızlık/i });
    await expect(izinPlaceholder).toContainText(/izin\/devamsızlık kaydı eklenmez/i);
    await expect(kayitModal.getByRole("button", { name: /Geç Geldi/i })).toHaveCount(0);
    await expect(kayitModal.locator("[name='surec-create-bas']")).toHaveCount(0);

    await kayitModal.getByRole("tab", { name: "Pozisyon" }).click();
    await expect(
      kayitModal.locator(".surec-person-placeholder").filter({ hasText: /Pozisyon/i })
    ).toContainText(/pozisyon değişikliği yapılamaz/i);
    await expect(kayitModal.locator('[name="pozisyon-effective-date"]')).toHaveCount(0);

    await kayitModal.getByRole("tab", { name: "Mali İşlemler" }).click();
    await expect(
      kayitModal.locator(".surec-person-placeholder").filter({ hasText: /Mali İşlemler/i })
    ).toContainText(/mali kayıt eklenmez/i);
    await expect(kayitModal.locator('[name="kayit-mali-donem"]')).toHaveCount(0);

    await kayitModal.getByRole("tab", { name: "Zimmet" }).click();
    await expect(kayitModal.locator(".surec-person-placeholder").filter({ hasText: /Zimmet/i })).toContainText(
      /zimmet kaydı eklenmez/i
    );
    await expect(kayitModal.locator("[name='personel-zimmet-urun-turu']")).toHaveCount(0);

    await kayitModal.getByRole("tab", { name: "Ceza" }).click();
    await expect(kayitModal.locator(".surec-person-placeholder")).toContainText(/ceza kaydı eklenmez/i);
    await expect(kayitModal.locator('[name="kayit-ceza-donem"]')).toHaveCount(0);
  });
});
