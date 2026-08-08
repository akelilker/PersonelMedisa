import { expect, test, type Locator, type Page } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

async function openSurecBelgeler(page: Page, personelSearch: string, personelOption: RegExp) {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container").last();
  await kayitModal.getByRole("button", { name: "Süreç" }).click();
  await kayitModal.getByRole("combobox", { name: "Personel" }).click();
  await kayitModal.getByPlaceholder("Personel ara").fill(personelSearch);
  await kayitModal.getByRole("option", { name: personelOption }).click();
  await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
  return kayitModal;
}

async function createBelgeInSurec(
  page: Page,
  kayitModal: Locator,
  uniqueAd: string,
  extras?: {
    verenKurum?: string;
    belgeNo?: string;
    baslangic?: string;
    bitis?: string;
    aciklama?: string;
  }
) {
  const panel = kayitModal.getByTestId("personel-belgeler-panel");
  await panel.getByTestId("personel-belge-yeni-btn").click();
  await page.getByTestId("personel-belge-ad").fill(uniqueAd);
  await page.locator("#personel-belge-tipi").selectOption("SERTIFIKA");
  if (extras?.verenKurum) {
    await page.locator("#personel-belge-veren-kurum").fill(extras.verenKurum);
  }
  if (extras?.belgeNo) {
    await page.locator("#personel-belge-belge-no").fill(extras.belgeNo);
  }
  if (extras?.baslangic) {
    await page.locator("#personel-belge-baslangic").fill(extras.baslangic);
  }
  if (extras?.bitis) {
    await page.locator("#personel-belge-bitis").fill(extras.bitis);
  }
  if (extras?.aciklama) {
    await page.locator("#personel-belge-aciklama").fill(extras.aciklama);
  }
  await page.getByTestId("personel-belge-create-submit").click();
}

test.describe("Kayit Surec belge kayitlari", () => {
  test("yonetici belge kaydi ekler ve personel kartinda read-only gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const uniqueAd = `E2E ISG Temel ${Date.now()}`;

    const kayitModal = await openSurecBelgeler(page, "Ayşe", /Ayşe Yılmaz/i);
    await expect(kayitModal.getByTestId("personel-belgeler-panel")).toBeVisible();
    await expect(kayitModal.getByTestId("personel-belge-yeni-btn")).toBeVisible();

    await createBelgeInSurec(page, kayitModal, uniqueAd, {
      verenKurum: "E2E Egitim Merkezi",
      belgeNo: "E2E-2026-001",
      baslangic: "2026-01-01",
      bitis: "2028-01-01",
      aciklama: "E2E belge kaydi aciklama"
    });
    await expect(kayitModal.getByText(/Belge kaydı eklendi/i)).toBeVisible({ timeout: 15_000 });
    await expect(kayitModal.getByTestId("personel-belge-kayit-list")).toContainText(uniqueAd);

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

    const kayitModal = await openSurecBelgeler(page, "Ayşe", /Ayşe Yılmaz/i);
    await expect(kayitModal.getByTestId("personel-belgeler-panel")).toBeVisible();

    const createResponse = page.waitForResponse(
      (response) =>
        response.url().includes("/api/personeller/1/belge-kayitlari") &&
        response.request().method() === "POST"
    );
    await createBelgeInSurec(page, kayitModal, uniqueAd, { baslangic: "2026-06-30" });
    expect((await createResponse).status()).toBe(201);
    await expect(kayitModal.getByText(/Belge kaydı eklendi/i)).toBeVisible({ timeout: 15_000 });

    const kayitList = kayitModal.getByTestId("personel-belge-kayit-list");
    await expect(kayitList).toContainText(uniqueAd);
    await expect(kayitList).not.toContainText("SERTIFIKA");
    await expect(kayitList).not.toContainText("IPTAL");
    await expect(kayitList).not.toContainText("[object Object]");
    await expect(kayitList).not.toContainText('{"tip"');

    const uniqueRow = kayitList.locator("tr", { hasText: uniqueAd });
    const rowTestId = await uniqueRow.getAttribute("data-testid");
    const kayitId = rowTestId?.replace("personel-belge-kayit-row-", "") ?? "";
    const cancelResponse = page.waitForResponse(
      (response) =>
        /\/api\/belge-kayitlari\/\d+\/iptal$/.test(new URL(response.url()).pathname) &&
        response.request().method() === "POST"
    );
    await uniqueRow.getByTestId(`personel-belge-iptal-${kayitId}`).click();
    await expect(page.getByTestId("personel-belge-action-dialog")).toBeVisible();
    await page.getByLabel("İptal nedeni").fill("E2E iptal nedeni");
    await page.getByTestId("personel-belge-action-dialog-confirm").click();
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

  test("pasif personelde belge kaydi yazma kapalidir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openSurecBelgeler(page, "Pasif", /Pasif Ornek/i);
    await expect(kayitModal.locator(".surec-person-placeholder")).toContainText(/belge durumu güncellenmez/i);
    await expect(kayitModal.getByTestId("personel-belgeler-panel")).toHaveCount(0);
    await expect(kayitModal.getByTestId("personel-belge-yeni-btn")).toHaveCount(0);
  });

  test("yetkili manage rol Süreç Belgeler panelini görür", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    const kayitModal = await openSurecBelgeler(page, "Ayşe", /Ayşe Yılmaz/i);
    await expect(kayitModal.getByTestId("kayit-surec-belgeler-panel")).toBeVisible();
    await expect(kayitModal.getByTestId("personel-belgeler-panel")).toBeVisible();
    await expect(kayitModal.getByTestId("personel-belge-yeni-btn")).toBeVisible();
    await expect(kayitModal.getByTestId("personel-belge-kayit-list")).toBeVisible();
  });

  test("BIRIM_AMIRI surec acabilirse belge listesi read-only kalir", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, MOCK_ROLE_LOGIN.BIRIM_AMIRI);

    const kayitMenu = page.getByTestId("menu-kayit-surec");
    if (await kayitMenu.isDisabled()) {
      await page.getByTestId("menu-personel-karti").click();
      await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
      await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();
      const panel = page.locator("#personel-kart-panel-egitim-belgeler").getByTestId("personel-belgeler-panel");
      await expect(panel).toBeVisible();
      await expect(panel.getByTestId("personel-belge-yeni-btn")).toHaveCount(0);
      await expect(panel.getByTestId("personel-belge-kayit-list")).toBeVisible();
      await expect(panel.locator('[data-testid^="personel-belge-duzenle-"]')).toHaveCount(0);
      await expect(panel.locator('[data-testid^="personel-belge-iptal-"]')).toHaveCount(0);
      return;
    }

    const kayitModal = await openSurecBelgeler(page, "Ayşe", /Ayşe Yılmaz/i);
    const panel = kayitModal.getByTestId("personel-belgeler-panel");
    await expect(panel).toBeVisible();
    await expect(panel.getByTestId("personel-belge-yeni-btn")).toHaveCount(0);
    await expect(panel.getByTestId("personel-belge-kayit-list")).toBeVisible();
    await expect(panel.locator('[data-testid^="personel-belge-duzenle-"]')).toHaveCount(0);
    await expect(panel.locator('[data-testid^="personel-belge-iptal-"]')).toHaveCount(0);
  });

  test("yetkisiz rol Belgeler paneline ulasamazsa belge-kayitlari fetch olmaz", async ({ page }) => {
    await mockApi(page, "PATRON");
    await login(page, MOCK_ROLE_LOGIN.PATRON);

    let belgeKayitlariFetchCount = 0;
    page.on("request", (request) => {
      if (
        request.method() === "GET" &&
        /\/api\/personeller\/\d+\/belge-kayitlari/.test(new URL(request.url()).pathname)
      ) {
        belgeKayitlariFetchCount += 1;
      }
    });

    const kayitMenu = page.getByTestId("menu-kayit-surec");
    if (await kayitMenu.isDisabled()) {
      await expect(page.getByTestId("personel-belgeler-panel")).toHaveCount(0);
      expect(belgeKayitlariFetchCount).toBe(0);
      return;
    }

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    const personelCombo = kayitModal.getByRole("combobox", { name: "Personel" });
    if ((await personelCombo.count()) === 0) {
      await expect(kayitModal.getByTestId("personel-belgeler-panel")).toHaveCount(0);
      expect(belgeKayitlariFetchCount).toBe(0);
      return;
    }

    await personelCombo.click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    const option = kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i });
    if ((await option.count()) === 0) {
      await expect(kayitModal.getByTestId("personel-belgeler-panel")).toHaveCount(0);
      expect(belgeKayitlariFetchCount).toBe(0);
      return;
    }
    await option.click();
    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();
    await expect(kayitModal.getByText(/Bu işlem için yetkin yok/i)).toBeVisible();
    await expect(kayitModal.getByTestId("personel-belgeler-panel")).toHaveCount(0);
    expect(belgeKayitlariFetchCount).toBe(0);
  });

  test("iptal dialogu confirm oncesi write=0; iptal edince write=0; onayda tek write", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const uniqueAd = `E2E Confirm Guard ${Date.now()}`;
    const kayitModal = await openSurecBelgeler(page, "Ayşe", /Ayşe Yılmaz/i);
    await createBelgeInSurec(page, kayitModal, uniqueAd, { baslangic: "2026-06-30" });
    await expect(kayitModal.getByText(/Belge kaydı eklendi/i)).toBeVisible({ timeout: 15_000 });

    const kayitList = kayitModal.getByTestId("personel-belge-kayit-list");
    const uniqueRow = kayitList.locator("tr", { hasText: uniqueAd });
    const rowTestId = await uniqueRow.getAttribute("data-testid");
    const kayitId = rowTestId?.replace("personel-belge-kayit-row-", "") ?? "";

    let cancelWriteCount = 0;
    page.on("request", (request) => {
      if (
        request.method() === "POST" &&
        /\/api\/belge-kayitlari\/\d+\/iptal$/.test(new URL(request.url()).pathname)
      ) {
        cancelWriteCount += 1;
      }
    });

    await uniqueRow.getByTestId(`personel-belge-iptal-${kayitId}`).click();
    await expect(page.getByTestId("personel-belge-action-dialog")).toBeVisible();
    expect(cancelWriteCount).toBe(0);

    await page.getByTestId("personel-belge-action-dialog-cancel").click();
    await expect(page.getByTestId("personel-belge-action-dialog")).toHaveCount(0);
    expect(cancelWriteCount).toBe(0);

    await uniqueRow.getByTestId(`personel-belge-iptal-${kayitId}`).click();
    await page.getByLabel("İptal nedeni").fill("confirm once");
    await page.getByTestId("personel-belge-action-dialog-confirm").click();
    await expect(kayitModal.getByText(/Belge kaydı iptal edildi/i)).toBeVisible({ timeout: 15_000 });
    expect(cancelWriteCount).toBe(1);
  });

  test("belge mutation sirasinda personel picker kilitlenir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const uniqueAd = `E2E Busy Lock ${Date.now()}`;
    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container--kayit-surec").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();
    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();

    await page.route("**/api/personeller/*/belge-kayitlari", async (route) => {
      if (route.request().method() !== "POST") {
        await route.fallback();
        return;
      }
      await new Promise((resolve) => setTimeout(resolve, 2000));
      await route.fallback();
    });

    await kayitModal.getByTestId("personel-belge-yeni-btn").click();
    await page.getByTestId("personel-belge-ad").fill(uniqueAd);
    await page.locator("#personel-belge-tipi").selectOption("SERTIFIKA");
    await page.getByTestId("personel-belge-create-submit").click();

    const personelCombo = kayitModal.getByRole("combobox", { name: "Personel" });
    await expect(personelCombo).toBeDisabled({ timeout: 5_000 });
    await expect(kayitModal.getByText(/Belge kaydı eklendi/i)).toBeVisible({ timeout: 20_000 });
    await expect(personelCombo).toBeEnabled({ timeout: 10_000 });
  });
});
