import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec Pozisyon", () => {
  test("yonetici Pozisyon sekmesinde gorev degisikligi PUT ve POZISYON_DEGISTI POST tetikler", async ({ page }) => {
    const pageErrors: string[] = [];
    const console500: string[] = [];
    page.on("pageerror", (error) => pageErrors.push(error.message));
    page.on("console", (message) => {
      const text = message.text();
      if (text.includes("500")) {
        console500.push(text);
      }
    });

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

    const putBody = putResp.request().postDataJSON() as Record<string, unknown>;
    expect(putBody.gorev_id).toBe(2);
    expect(putBody.effective_date).toBe("2026-08-01");
    expect(putBody).not.toHaveProperty("surec_turu");

    const postBody = postResp.request().postDataJSON() as Record<string, unknown>;
    expect(postBody.surec_turu).toBe("POZISYON_DEGISTI");
    expect(postBody.personel_id).toBe(1);
    expect(postBody.baslangic_tarihi).toBe("2026-08-01");

    await expect(kayitModal.getByRole("combobox", { name: "Görev / Unvan" })).toContainText("Üretim Müdürü");

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await expect(kayitModal).toHaveCount(0);

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Üretim Müdürü|Uretim Müdürü|Uretim Muduru/i);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText(/Pozisyon Değişti|Pozisyon Degisti/i);
    await expect(timeline).not.toContainText("Mock otomatik org gecmis kaydi");
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    expect(pageErrors).toEqual([]);
    expect(console500).toEqual([]);
  });

  test("Personel Karti acikken pozisyon update sonrasi liste cache guncellenir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first()).toBeVisible({
      timeout: 15_000
    });

    await page.goto("/");

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "Pozisyon" }).click();
    await kayitModal.getByRole("combobox", { name: "Görev / Unvan" }).click();
    await kayitModal.locator("#pozisyon-gorev-panel").getByRole("button", { name: "Üretim Müdürü" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-01");

    const pozisyonKaydet = kayitModal.locator("form.surec-position-form").getByRole("button", { name: "Kaydet" });
    await expect(pozisyonKaydet).toBeEnabled({ timeout: 5000 });
    await pozisyonKaydet.click();

    await expect(kayitModal.getByRole("combobox", { name: "Görev / Unvan" })).toContainText("Üretim Müdürü");
    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await expect(kayitModal).toHaveCount(0);

    let delayPersonelList = true;
    await page.route("**/api/personeller**", async (route) => {
      const request = route.request();
      if (request.method() === "GET" && delayPersonelList) {
        await new Promise((resolve) => setTimeout(resolve, 4_000));
      }
      await route.continue();
    });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    const ayseListLink = page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first();
    await expect(ayseListLink).toBeVisible({ timeout: 2_000 });
    await expect(ayseListLink).toContainText(/Üretim Müdürü|Uretim Müdürü|Uretim Muduru/i, { timeout: 2_000 });
  });
});
