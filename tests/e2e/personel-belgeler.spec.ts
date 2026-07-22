import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "yonetici", password: "secret" },
  muhasebe: { username: "muhasebe", password: "demo123" }
};

test.describe("personel belgeler API contract", () => {
  test("belge durumu ve kayitlari bos state 404 gostermeden yuklenir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    const response = await page.evaluate(async () => {
      const durum = await fetch("/api/personeller/4/belge-durumu");
      const durumBody = await durum.json();
      const kayitlar = await fetch("/api/personeller/4/belge-kayitlari?state=AKTIF&limit=50");
      const kayitlarBody = await kayitlar.json();

      return {
        durumStatus: durum.status,
        durumItems: durumBody.data?.items,
        kayitlarStatus: kayitlar.status,
        kayitItems: kayitlarBody.data?.items,
        pathname: window.location.pathname
      };
    });

    expect(response.durumStatus).toBe(200);
    expect(response.durumItems).toEqual([
      { belge_turu: "KIMLIK", durum: "YOK" },
      { belge_turu: "ADRES_BEYANI", durum: "YOK" },
      { belge_turu: "IS_GIRIS_EVRAKLARI", durum: "YOK" },
      { belge_turu: "BANKA_IBAN", durum: "YOK" }
    ]);
    expect(response.kayitlarStatus).toBe(200);
    expect(response.kayitItems).toEqual([]);
    expect(response.pathname).not.toBe("/yetkisiz");
  });

  test("egitim belgeler sekmesi label kalitesi ve bos durumu kilitler", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    const apiFailures: Array<{ status: number; url: string }> = [];
    page.on("response", (response) => {
      if (!response.url().includes("/api/personeller/") || !response.url().includes("/belge")) {
        return;
      }
      if ([401, 403, 404, 500].includes(response.status())) {
        apiFailures.push({ status: response.status(), url: response.url() });
      }
    });

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Maas Eksik.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const belgelerPanel = page.locator("#personel-kart-panel-egitim-belgeler");
    await expect(belgelerPanel.getByText("Belge kaydı bulunmuyor.")).toBeVisible();
    await expect(belgelerPanel).not.toContainText("SERTIFIKA");
    await expect(belgelerPanel).not.toContainText("IPTAL");
    await expect(belgelerPanel).not.toContainText("[object Object]");
    await expect(belgelerPanel).not.toContainText(/Sertf/i);
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    expect(apiFailures).toEqual([]);

    await page.goto("/personeller");
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const aysePanel = page.locator("#personel-kart-panel-egitim-belgeler");
    const kayitList = aysePanel.getByTestId("personel-belge-kayit-list");
    await expect(kayitList).toBeVisible();
    await expect(kayitList).toContainText("Sertifika");
    await expect(kayitList).toContainText("Forklift Operatör Belgesi");
    await expect(kayitList).toContainText(/Aktif|Dosya eksik|Süresi/i);
    await expect(kayitList).not.toContainText("SERTIFIKA");
    await expect(kayitList).not.toContainText(/Sertf/i);
    await expect(kayitList).not.toContainText("[object Object]");
    await expect(kayitList).not.toContainText('{"tip"');

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timeline = page.locator("#personel-kart-panel-surec-gecmisi [data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText("Belge / Sertifika");
    await expect(timeline).not.toContainText(/Sertf/i);
    expect(apiFailures).toEqual([]);
  });

  test("belge kaydi create sonrasi personel kartinda read-only gorunur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    const apiFailures: Array<{ status: number; url: string }> = [];
    page.on("response", (response) => {
      if (!response.url().includes("/api/personeller/1/belge")) {
        return;
      }
      if ([401, 403, 404, 500].includes(response.status())) {
        apiFailures.push({ status: response.status(), url: response.url() });
      }
    });

    const uniqueAd = `S28 Canli Smoke Sertifika ${Date.now()}`;
    const aciklama = "S28 canlı belge smoke";

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
    await expect(kayitModal.getByTestId("kayit-belge-kayitlari-section")).toBeVisible();
    await expect(kayitModal.getByText(/API request failed: 404/i)).toHaveCount(0);

    await kayitModal.locator('[name="belge-kayit-tipi"]').selectOption("SERTIFIKA");
    await kayitModal.locator('[name="belge-kayit-ad"]').fill(uniqueAd);
    await kayitModal.locator('[name="belge-kayit-baslangic"]').fill("2026-06-30");
    await kayitModal.locator('[name="belge-kayit-aciklama"]').fill(aciklama);

    const postResponse = page.waitForResponse(
      (response) =>
        response.url().includes("/api/personeller/1/belge-kayitlari") &&
        response.request().method() === "POST"
    );
    await kayitModal.locator('button[type="submit"][form="kayit-surec-belge-kayitlari-form"]').click();
    expect((await postResponse).status()).toBe(201);
    await expect(kayitModal.getByText(/Belge kaydı eklendi/i)).toBeVisible({ timeout: 15_000 });
    await expect(kayitModal.getByTestId("kayit-belge-kayitlari-list")).toContainText(uniqueAd);

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const belgelerPanel = page.locator("#personel-kart-panel-egitim-belgeler");
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toContainText(uniqueAd);
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toContainText("Sertifika");
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    expect(apiFailures).toEqual([]);
  });

  test("iptal edilen belge kayitlari ayri salt okunur gecmis bolumunde gorunur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    const apiFailures: Array<{ status: number; url: string }> = [];
    page.on("response", (response) => {
      if (!response.url().includes("/api/personeller/1/belge")) {
        return;
      }
      if ([401, 403, 404, 500].includes(response.status())) {
        apiFailures.push({ status: response.status(), url: response.url() });
      }
    });

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();

    const belgelerPanel = page.locator("#personel-kart-panel-egitim-belgeler");
    const aktifListe = belgelerPanel.getByTestId("personel-belge-kayit-list");
    const iptalListe = belgelerPanel.getByTestId("personel-belge-kayit-iptal-list");

    await expect(aktifListe).toBeVisible();
    await expect(aktifListe).toContainText("Forklift Operatör Belgesi");
    await expect(aktifListe).not.toContainText("S34 İptal Sertifika");

    await expect(belgelerPanel.getByRole("heading", { name: "İptal edilen belge kayıtları" })).toBeVisible();
    await expect(iptalListe).toBeVisible();
    await expect(iptalListe).toContainText("S34 İptal Sertifika");
    await expect(iptalListe).toContainText("Sertifika");
    await expect(iptalListe).toContainText("İptal");
    await expect(iptalListe).not.toContainText("IPTAL");
    await expect(iptalListe).not.toContainText("SERTFIKA");
    await expect(iptalListe).not.toContainText("SERTIFIKA");
    await expect(iptalListe).not.toContainText("[object Object]");
    await expect(iptalListe).not.toContainText('{"tip"');
    await expect(belgelerPanel).not.toContainText('{"tip"');
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    expect(apiFailures).toEqual([]);
  });

  test("scope disi belge endpointleri 403 doner ve yetkisiz redirect tetiklemez", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, users.muhasebe);

    const response = await page.evaluate(async () => {
      const result = await fetch("/api/personeller/2/belge-kayitlari?state=AKTIF&limit=50", {
        headers: { "x-active-sube-id": "1" }
      });
      const body = await result.json();

      return {
        status: result.status,
        code: body.errors?.[0]?.code,
        pathname: window.location.pathname
      };
    });

    expect(response.status).toBe(403);
    expect(response.code).toBe("FORBIDDEN");
    expect(response.pathname).not.toBe("/yetkisiz");
  });
});
