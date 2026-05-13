import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec Pozisyon", () => {
  test("yonetici Pozisyon sekmesinde gorev degisikligi PUT ve POZISYON_DEGISTI POST tetikler", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "Pozisyon" }).click();
    await expect(kayitModal.locator(".surec-person-placeholder")).toHaveCount(0);
    await expect(kayitModal.locator("form.surec-position-form")).toBeVisible();

    await kayitModal.getByRole("combobox", { name: "Görev / Unvan" }).click();
    await kayitModal.locator("#pozisyon-gorev-panel").getByRole("button", { name: "Üretim Müdürü" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-01");

    const pozisyonKaydet = kayitModal.locator("form.surec-position-form").getByRole("button", { name: "Kaydet" });
    await expect(pozisyonKaydet).toBeEnabled({ timeout: 5000 });

    const putPromise = page.waitForResponse(
      (r) => r.url().includes("/api/personeller/1") && r.request().method() === "PUT"
    );
    const postSurecPromise = page.waitForResponse(
      (r) => r.url().includes("/api/surecler") && r.request().method() === "POST" && r.request().postDataJSON()?.surec_turu === "POZISYON_DEGISTI"
    );
    const [putResp, postResp] = await Promise.all([putPromise, postSurecPromise, pozisyonKaydet.click()]);

    expect(putResp.ok()).toBe(true);
    expect(postResp.ok()).toBe(true);
  });
});
