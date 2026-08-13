import { expect, test, type Page, type Response } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

async function openPozisyonForAyse(page: Page) {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container").last();
  await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  await kayitModal.getByTestId("kayit-tab-surec").click();
  await kayitModal.getByRole("combobox", { name: "Personel" }).click();
  await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
  await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();
  await kayitModal.getByRole("tab", { name: "Pozisyon" }).click();
  await expect(kayitModal.locator("form.surec-position-form")).toBeVisible();
  return kayitModal;
}

function isPersonelPut(response: Response) {
  return response.url().includes("/api/personeller/1") && response.request().method() === "PUT";
}

function isPozisyonSurecPost(response: Response) {
  if (!response.url().includes("/api/surecler") || response.request().method() !== "POST") {
    return false;
  }
  return response.request().postDataJSON()?.surec_turu === "POZISYON_DEGISTI";
}

async function assertTimelinePozisyon(page: Page) {
  await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
  const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
  await expect(timeline).toContainText(/Pozisyon Değişti|Pozisyon Degisti/i);
  await expect(timeline).not.toContainText("Mock otomatik org gecmis kaydi");
}

async function openPersonelCard(page: Page) {
  await page.getByTestId("menu-personel-karti").click();
  await expect(page).toHaveURL(/\/personeller$/);
  await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
  await expect(page).toHaveURL(/\/personeller\/1$/);
}


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

    const kayitModal = await openPozisyonForAyse(page);

    await kayitModal.getByRole("combobox", { name: "Unvan" }).click();
    await kayitModal.locator("#pozisyon-gorev-panel").getByRole("button", { name: "Üretim Müdürü" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-01");

    const pozisyonKaydet = kayitModal.getByTestId("kayit-modal-footer-primary");
    await expect(pozisyonKaydet).toBeEnabled({ timeout: 5000 });
    await expect(pozisyonKaydet).toHaveAttribute("form", "kayit-surec-pozisyon-form");

    const putPromise = page.waitForResponse(isPersonelPut);
    const postSurecPromise = page.waitForResponse(isPozisyonSurecPost);
    const [putResp, postResp] = await Promise.all([putPromise, postSurecPromise, pozisyonKaydet.click()]);

    expect(putResp.ok()).toBe(true);
    expect(postResp.ok()).toBe(true);

    const putBody = putResp.request().postDataJSON() as Record<string, unknown>;
    expect(putBody.gorev_id).toBe(2);
    expect(putBody.effective_date).toBe("2026-08-01");
    expect(putBody).not.toHaveProperty("departman_id");
    expect(putBody).not.toHaveProperty("bagli_amir_id");
    expect(putBody).not.toHaveProperty("personel_tipi_id");
    expect(putBody).not.toHaveProperty("surec_turu");

    const postBody = postResp.request().postDataJSON() as Record<string, unknown>;
    expect(postBody.surec_turu).toBe("POZISYON_DEGISTI");
    expect(postBody.personel_id).toBe(1);
    expect(postBody.baslangic_tarihi).toBe("2026-08-01");

    await expect(kayitModal.getByRole("combobox", { name: "Unvan" })).toContainText("Üretim Müdürü");

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await expect(kayitModal).toHaveCount(0);

    await openPersonelCard(page);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Üretim Müdürü|Uretim Müdürü|Uretim Muduru/i);
    await assertTimelinePozisyon(page);
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    expect(pageErrors).toEqual([]);
    expect(console500).toEqual([]);
  });

  test("departman degisikligi personel karti ve timeline ile parity saglar", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openPozisyonForAyse(page);

    await kayitModal.getByRole("combobox", { name: "Departman" }).click();
    await kayitModal.locator("#pozisyon-departman-panel").getByRole("button", { name: "Finans" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-03");

    const putPromise = page.waitForResponse(isPersonelPut);
    const postPromise = page.waitForResponse(isPozisyonSurecPost);
    const [putResp, postResp] = await Promise.all([
      putPromise,
      postPromise,
      kayitModal.getByTestId("kayit-modal-footer-primary").click()
    ]);

    const putBody = putResp.request().postDataJSON() as Record<string, unknown>;
    expect(putBody).toEqual({ departman_id: 2, effective_date: "2026-08-03" });
    expect(postResp.request().postDataJSON()?.surec_turu).toBe("POZISYON_DEGISTI");

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await openPersonelCard(page);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Finans/i);
    await assertTimelinePozisyon(page);
  });

  test("bagli amir degisikligi personel karti ve timeline ile parity saglar", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openPozisyonForAyse(page);

    await kayitModal.getByRole("combobox", { name: "Bağlı Amir" }).click();
    await kayitModal.locator("#pozisyon-bagli-amir-panel").getByRole("button", { name: "İkinci Amir" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-04");

    const putPromise = page.waitForResponse(isPersonelPut);
    const postPromise = page.waitForResponse(isPozisyonSurecPost);
    const [putResp] = await Promise.all([
      putPromise,
      postPromise,
      kayitModal.getByTestId("kayit-modal-footer-primary").click()
    ]);

    expect(putResp.request().postDataJSON()).toEqual({
      bagli_amir_id: 10,
      effective_date: "2026-08-04"
    });

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await openPersonelCard(page);
    await page.getByRole("tab", { name: "Genel" }).click();
    await expect(page.locator("#personel-kart-panel-genel-bilgiler")).toContainText(/İkinci Amir|Ikinci Amir/i);
    await assertTimelinePozisyon(page);
  });

  test("personel tipi degisikligi personel karti ve timeline ile parity saglar", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openPozisyonForAyse(page);

    await kayitModal.getByRole("combobox", { name: "Çalışma Tipi" }).click();
    await kayitModal.locator("#pozisyon-personel-tipi-panel").getByRole("button", { name: "Yarı Zamanlı" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-05");

    const putPromise = page.waitForResponse(isPersonelPut);
    const postPromise = page.waitForResponse(isPozisyonSurecPost);
    const [putResp] = await Promise.all([
      putPromise,
      postPromise,
      kayitModal.getByTestId("kayit-modal-footer-primary").click()
    ]);

    expect(putResp.request().postDataJSON()).toEqual({
      personel_tipi_id: 2,
      effective_date: "2026-08-05"
    });

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await openPersonelCard(page);
    await page.getByRole("tab", { name: "Genel" }).click();
    await expect(page.locator("#personel-kart-panel-genel-bilgiler")).toContainText(/Yarı Zamanlı|Yari Zamanli/i);
    await assertTimelinePozisyon(page);
  });

  test("coklu alan degisikligi tek PUT ve tek POZISYON_DEGISTI uretir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openPozisyonForAyse(page);

    await kayitModal.getByRole("combobox", { name: "Departman" }).click();
    await kayitModal.locator("#pozisyon-departman-panel").getByRole("button", { name: "Finans" }).click();
    await kayitModal.getByRole("combobox", { name: "Unvan" }).click();
    await kayitModal.locator("#pozisyon-gorev-panel").getByRole("button", { name: "Üretim Müdürü" }).click();
    await kayitModal.getByRole("combobox", { name: "Bağlı Amir" }).click();
    await kayitModal.locator("#pozisyon-bagli-amir-panel").getByRole("button", { name: "İkinci Amir" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-06");

    const putCalls: string[] = [];
    const surecCalls: string[] = [];
    page.on("request", (request) => {
      if (request.method() === "PUT" && request.url().includes("/api/personeller/1")) {
        putCalls.push(request.postData() ?? "");
      }
      if (request.method() === "POST" && request.url().includes("/api/surecler")) {
        const body = request.postDataJSON() as { surec_turu?: string } | null;
        if (body?.surec_turu === "POZISYON_DEGISTI") {
          surecCalls.push(request.postData() ?? "");
        }
      }
    });

    const putPromise = page.waitForResponse(isPersonelPut);
    const postPromise = page.waitForResponse(isPozisyonSurecPost);
    const [putResp] = await Promise.all([
      putPromise,
      postPromise,
      kayitModal.getByTestId("kayit-modal-footer-primary").click()
    ]);

    expect(putCalls).toHaveLength(1);
    expect(surecCalls).toHaveLength(1);
    expect(putResp.request().postDataJSON()).toEqual({
      departman_id: 2,
      gorev_id: 2,
      bagli_amir_id: 10,
      effective_date: "2026-08-06"
    });
  });

  test("no-op pozisyon kaydi write uretmez", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openPozisyonForAyse(page);

    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-07");
    const pozisyonKaydet = kayitModal.getByTestId("kayit-modal-footer-primary");
    await expect(pozisyonKaydet).toBeDisabled();

    let putCount = 0;
    let surecCount = 0;
    page.on("request", (request) => {
      if (request.method() === "PUT" && request.url().includes("/api/personeller/1")) putCount += 1;
      if (
        request.method() === "POST" &&
        request.url().includes("/api/surecler") &&
        request.postDataJSON()?.surec_turu === "POZISYON_DEGISTI"
      ) {
        surecCount += 1;
      }
    });

    await kayitModal.locator("form.surec-position-form").evaluate((form: HTMLFormElement) => {
      form.requestSubmit();
    });
    await expect(kayitModal.getByText("Pozisyon bilgisi değişmedi.")).toBeVisible();
    expect(putCount).toBe(0);
    expect(surecCount).toBe(0);
  });

  test("birim amiri kayit merkezine giremez; pozisyon submit bypass yok", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, { username: "birim", password: "secret" });

    const kayitMenu = page.getByTestId("menu-kayit-surec");
    await expect(kayitMenu).toBeDisabled();
    await expect(kayitMenu).toHaveAttribute("title", /yetkiniz bulunmuyor/i);
  });

  test("I3 gateway ile gelen personelde pozisyon current values prefill olur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Süreçte İşlem Yap" }).click();

    const kayitModal = page.locator(".modal-container--kayit-surec").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç/i })).toBeVisible();
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toContainText(/Ayşe Yılmaz/i);
    await kayitModal.getByRole("tab", { name: "Pozisyon" }).click();
    await expect(kayitModal.locator("form.surec-position-form")).toBeVisible();
    await expect(kayitModal.getByRole("combobox", { name: "Departman" })).toContainText(/Döşeme|Doseme/i);
    await expect(kayitModal.getByRole("combobox", { name: "Unvan" })).toContainText(/Genel Müdür|Genel Mudur/i);
    await expect(kayitModal.getByRole("combobox", { name: "Bağlı Amir" })).toContainText(/Demo Amir/i);
    await expect(kayitModal.getByRole("combobox", { name: "Çalışma Tipi" })).toContainText(/Tam Zamanlı|Tam Zamanli/i);
  });

  test("pozisyon submit inflight iken personel picker kilitli kalir ve context sabit kalir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openPozisyonForAyse(page);
    await kayitModal.getByRole("combobox", { name: "Unvan" }).click();
    await kayitModal.locator("#pozisyon-gorev-panel").getByRole("button", { name: "Üretim Müdürü" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-01");

    let putCount = 0;
    let postCount = 0;
    await page.route(/\/api\/personeller\/1$/, async (route) => {
      if (route.request().method() === "PUT") {
        putCount += 1;
        await new Promise((resolve) => setTimeout(resolve, 2500));
      }
      await route.fallback();
    });
    page.on("request", (request) => {
      if (!request.url().includes("/api/surecler") || request.method() !== "POST") {
        return;
      }
      try {
        if (request.postDataJSON()?.surec_turu === "POZISYON_DEGISTI") {
          postCount += 1;
        }
      } catch {
        /* ignore malformed bodies */
      }
    });

    const personelCombo = kayitModal.getByRole("combobox", { name: "Personel" });
    await expect(personelCombo).toContainText(/Ayşe Yılmaz/i);
    await expect(personelCombo).toBeEnabled();

    const pozisyonKaydet = kayitModal.getByTestId("kayit-modal-footer-primary");
    await expect(pozisyonKaydet).toBeEnabled({ timeout: 5000 });
    await expect(pozisyonKaydet).toHaveAttribute("form", "kayit-surec-pozisyon-form");

    const putPromise = page.waitForResponse(isPersonelPut);
    const postSurecPromise = page.waitForResponse(isPozisyonSurecPost);
    await pozisyonKaydet.click();

    await expect(personelCombo).toBeDisabled();
    await expect(pozisyonKaydet).toBeDisabled();
    await expect(personelCombo).toContainText(/Ayşe Yılmaz/i);
    await personelCombo.click({ force: true });
    await expect(kayitModal.getByRole("listbox", { name: "Personel listesi" })).toHaveCount(0);
    await expect(kayitModal.getByRole("option", { name: /Mehmet Kaya/i })).toHaveCount(0);

    const [putResp, postResp] = await Promise.all([putPromise, postSurecPromise]);
    expect(putResp.ok()).toBe(true);
    expect(postResp.ok()).toBe(true);

    await expect(personelCombo).toBeEnabled({ timeout: 5000 });
    await expect(personelCombo).toContainText(/Ayşe Yılmaz/i);
    await expect(kayitModal.getByRole("combobox", { name: "Unvan" })).toContainText("Üretim Müdürü");
    expect(putCount).toBe(1);
    expect(postCount).toBe(1);
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

    const kayitModal = await openPozisyonForAyse(page);
    await kayitModal.getByRole("combobox", { name: "Unvan" }).click();
    await kayitModal.locator("#pozisyon-gorev-panel").getByRole("button", { name: "Üretim Müdürü" }).click();
    await kayitModal.getByLabel("Geçerlilik Tarihi").fill("2026-08-01");

    const pozisyonKaydet = kayitModal.getByTestId("kayit-modal-footer-primary");
    await expect(pozisyonKaydet).toBeEnabled({ timeout: 5000 });
    await expect(pozisyonKaydet).toHaveAttribute("form", "kayit-surec-pozisyon-form");
    await pozisyonKaydet.click();

    await expect(kayitModal.getByRole("combobox", { name: "Unvan" })).toContainText("Üretim Müdürü");
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
