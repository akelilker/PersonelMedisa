import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";
import type { Page } from "@playwright/test";

function kayitSurecModal(page: Page) {
  return page.locator(".modal-container--kayit-surec, .modal-container").filter({
    has: page.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })
  });
}

async function assertGatewayStateCleared(page: Page) {
  await expect(kayitSurecModal(page)).toHaveCount(0);
  await expect(page.getByText(/Kart düzenleme işlemi merkez ekrana taşınıyor/i)).toHaveCount(0);
  await expect(page.getByText(/Zimmet işlemi merkez ekrana taşınıyor/i)).toHaveCount(0);
}

async function openKartDuzenleFromActions(page: Page) {
  await page.getByRole("button", { name: "Islemler" }).click();
  await page.getByRole("button", { name: "Kartı Düzenle" }).click();

  const kayitModal = kayitSurecModal(page);
  await expect(kayitModal.getByText(/Kart düzenleme işlemi merkez ekrana taşınıyor/i)).toBeVisible({
    timeout: 5000
  });

  const gatewayButton = page.getByRole("button", {
    name: /Personel Kart[iı]na D[oö]n ve D[uü]zenle|Personel Kartina Don ve Duzenle/i
  }).first();

  await gatewayButton.click();

  await expect(page).toHaveURL(/\/personeller\/\d+$/);
  await assertGatewayStateCleared(page);
  await expect(page.locator('[name="edit-departman"]')).toBeVisible({ timeout: 10000 });
}

async function openZimmetCreateFromPersonelDosya(page: Page) {
  await page.getByRole("button", { name: "Islemler" }).click();
  await page
    .locator(".personel-dosya-action-menu")
    .getByRole("button", { name: "Yeni Zimmet Ekle" })
    .click();

  const kayitModal = kayitSurecModal(page);
  await expect(kayitModal.getByText(/Zimmet işlemi merkez ekrana taşınıyor/i)).toBeVisible({
    timeout: 5000
  });

  const gatewayButton = page.getByRole("button", {
    name: /Personel Kart[iı]na D[oö]n ve Zimmet Ekle|Personel Kartina Don ve Zimmet Ekle/i
  }).first();

  await gatewayButton.click();

  await expect(page).toHaveURL(/\/personeller\/\d+$/);
  await assertGatewayStateCleared(page);
  await expect(page.getByRole("tab", { name: "Zimmet" })).toHaveAttribute("aria-selected", "true");
  await expect(page.getByRole("tab", { name: "Genel" })).not.toHaveAttribute("aria-selected", "true");

  const zimmetModal = page
    .locator(".modal-container")
    .filter({ has: page.getByRole("heading", { name: /Yeni Zimmet Ekle/i }) })
    .last();
  await expect(zimmetModal).toBeVisible({ timeout: 10000 });
  await expect(zimmetModal.locator("[name='personel-zimmet-urun-turu']")).toBeVisible();
  return zimmetModal;
}

const PERSONEL_KART_TAB_NAMES = ["Genel", "Eğitim / Belgeler", "Disiplin", "Zimmet", "Süreç Geçmişi"] as const;

function trackPageErrors(page: Page) {
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => {
    pageErrors.push(error.message);
  });
  return pageErrors;
}

async function assertPersonelKartTabsVisible(page: Page) {
  for (const tabName of PERSONEL_KART_TAB_NAMES) {
    await expect(page.getByRole("tab", { name: tabName })).toBeVisible();
  }
}

async function seedDevamPrimiHastalikCacheForPersonelOne(page: Page) {
  await page.evaluate(() => {
    const fetchedAt = new Date().toISOString();
    const appData = {
      schemaVersion: 4,
      revision: 1,
      updatedAt: fetchedAt,
      cache: {
        "puantaj:sall:1|2026-04-10": {
          fetchedAt,
          data: {
            personel_id: 1,
            tarih: "2026-04-10",
            gun_tipi: "Normal_Is_Gunu",
            hareket_durumu: "Gelmedi",
            dayanak: "Raporlu_Hastalik",
            hesap_etkisi: "Kesinti_Yap",
            hafta_tatili_hak_kazandi_mi: true,
            state: "HESAPLANDI",
            compliance_uyarilari: []
          }
        }
      }
    };
    window.appData = appData;
    window.localStorage.setItem("medisa_app_data", JSON.stringify(appData));
  });
}

test.describe("personel dosyasi surec akisi", () => {
  test("personel karti bes sekme modelini ve temel panelleri gosterir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await assertPersonelKartTabsVisible(page);

    await expect(page.getByRole("tab", { name: "Genel" })).toHaveAttribute("aria-selected", "true");
    const genelPanel = page.locator("#personel-kart-panel-genel-bilgiler");
    await expect(genelPanel).toBeVisible();
    await expect(genelPanel.getByText("Aylık Puantaj Özeti")).toBeVisible();
    await expect(genelPanel.getByText("İzin Özeti")).toBeVisible();
    await expect(page.getByTestId("personel-maas-eksik-uyari")).toHaveCount(0);

    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();
    await expect(page.getByRole("tab", { name: "Eğitim / Belgeler" })).toHaveAttribute("aria-selected", "true");
    const belgelerPanel = page.locator("#personel-kart-panel-egitim-belgeler");
    await expect(belgelerPanel).toBeVisible();
    await expect(belgelerPanel.getByTestId("personel-belgeler-panel")).toBeVisible();
    await expect(belgelerPanel).toContainText(/Belge Durumu|Eğitim/i);

    await page.getByRole("tab", { name: "Disiplin" }).click();
    await expect(page.getByRole("tab", { name: "Disiplin" })).toHaveAttribute("aria-selected", "true");
    const disiplinPanel = page.locator("#personel-kart-panel-disiplin");
    await expect(disiplinPanel).toBeVisible();
    await expect(disiplinPanel.getByTestId("personel-disiplin-panel")).toBeVisible();
    await expect(disiplinPanel.getByTestId("personel-disiplin-ceza-section")).toBeVisible();

    await page.getByRole("tab", { name: "Zimmet" }).click();
    await expect(page.getByRole("tab", { name: "Zimmet" })).toHaveAttribute("aria-selected", "true");
    const zimmetPanel = page.locator("#personel-kart-panel-zimmet-envanter");
    await expect(zimmetPanel).toBeVisible();
    await expect(zimmetPanel.locator(".personel-zimmet-panel")).toBeVisible();

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    await expect(page.getByRole("tab", { name: "Süreç Geçmişi" })).toHaveAttribute("aria-selected", "true");
    const surecPanel = page.locator("#personel-kart-panel-surec-gecmisi");
    await expect(surecPanel).toBeVisible();
    await expect(surecPanel.getByTestId("personel-surec-timeline")).toBeVisible();

    await assertGatewayStateCleared(page);
  });

  test("birim amiri personel kartinda finans aday yetki fallback gosterir", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");

    await login(page, { username: "birim_amiri", password: "demo123" });

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Ayşe Yılmaz/i);

    const finansCard = page.getByTestId("personel-finans-adaylari-card");
    await expect(finansCard).toBeVisible();
    await expect(page.getByTestId("personel-finans-adaylari-yetki-yok")).toBeVisible();
    await expect(page.getByTestId("personel-finans-kayit-901")).toHaveCount(0);
    await expect(page.getByTestId("personel-finans-kayit-903")).toHaveCount(0);
    await expect(page.getByTestId("personel-finans-kayit-904")).toHaveCount(0);
    await expect(page.getByTestId("personel-bordro-aday-finans-toplamlari")).toHaveCount(0);
    await expect(page.getByTestId("personel-finans-adaylari-yukleniyor")).toHaveCount(0, { timeout: 10_000 });
  });

  test("personel karti donem bilgisi yoksa finans aday fallback gosterir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/4");
    await expect(page).toHaveURL(/\/personeller\/4$/);

    const finansCard = page.getByTestId("personel-finans-adaylari-card");
    await expect(finansCard).toBeVisible();
    await expect(page.getByTestId("personel-finans-adaylari-donem-yok")).toBeVisible();
    await expect(page.getByTestId("personel-finans-adaylari-bos")).toHaveCount(0);
    await expect(page.getByTestId("personel-finans-adaylari-list")).toHaveCount(0);
    await expect(page.getByTestId("personel-finans-kayit-901")).toHaveCount(0);
  });

  test("personel karti finans adaylarini donem ve state filtresine gore gosterir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);

    const finansCard = page.getByTestId("personel-finans-adaylari-card");
    await expect(finansCard).toBeVisible();
    await expect(page.getByTestId("personel-finans-adaylari-yukleniyor")).toHaveCount(0, { timeout: 10_000 });

    await expect(page.getByTestId("personel-finans-kayit-901")).toBeVisible();
    await expect(finansCard).toContainText("Mevcut finans kalemi");
    await expect(finansCard).not.toContainText("Farkli donem finans kaydi");
    await expect(finansCard).not.toContainText("Iptal finans kaydi");
    await expect(page.getByTestId("personel-finans-kayit-903")).toHaveCount(0);
    await expect(page.getByTestId("personel-finans-kayit-904")).toHaveCount(0);
  });

  test("bordro aday ozet karti finans yuklenirken bos mesaj gostermez", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    let releaseFinansFetch!: () => void;
    const finansFetchGate = new Promise<void>((resolve) => {
      releaseFinansFetch = resolve;
    });

    await page.route("**/api/ek-odeme-kesinti**", async (route) => {
      if (route.request().method() === "GET") {
        await finansFetchGate;
      }
      await route.fallback();
    });

    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);

    const bordroCard = page.getByTestId("personel-bordro-aday-ozet-card");
    await expect(bordroCard).toBeVisible();
    await expect(page.getByTestId("personel-bordro-aday-ozet-yukleniyor")).toBeVisible();
    await expect(page.getByTestId("personel-bordro-aday-ozet-bos")).toHaveCount(0);

    releaseFinansFetch();

    await expect(page.getByTestId("personel-bordro-aday-ozet-yukleniyor")).toHaveCount(0, { timeout: 10_000 });
    await expect(page.getByTestId("personel-bordro-aday-finans-toplamlari")).toBeVisible();
  });

  test("devam primi readonly karti personel gecisinde eski kesinti sonucunu tasimaz", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });
    await seedDevamPrimiHastalikCacheForPersonelOne(page);

    await page.goto("/personeller");
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.getByTestId("personel-devam-primi-card")).toBeVisible();
    await expect(page.getByTestId("personel-devam-primi-durum")).toContainText("Kesildi");

    await page.goto("/personeller");
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Mehmet Kaya.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/2$/);
    await expect(page.getByTestId("personel-devam-primi-card")).toBeVisible();
    await expect(page.getByTestId("personel-devam-primi-durum")).not.toContainText("Kesildi");
  });

  test("personel kartindan surec ekle izin kaydini timelinea yansitir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Ayşe Yılmaz/i);

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Süreç Ekle" }).click();

    const surecModal = kayitSurecModal(page);
    await expect(surecModal).toBeVisible();
    await expect(page).toHaveURL(/\/$/);
    await expect(surecModal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");
    await expect(surecModal.locator("[name='surec-create-personel']")).toHaveValue("1");
    await expect(surecModal.locator(".workspace-personel-preview--compact strong")).toContainText(/Ayşe Yılmaz/i, {
      timeout: 15_000
    });

    if (
      (await surecModal.locator("[name='surec-create-turu']").count()) === 0 &&
      (await surecModal.locator("[name='surec-create-turu-text']").count()) === 0
    ) {
      await surecModal.locator(".surec-shell-action-tile").click();
      await expect(
        surecModal.locator("[name='surec-create-turu'], [name='surec-create-turu-text']").first()
      ).toBeVisible({ timeout: 10_000 });
    }

    const izinBaslangic = "2026-04-15";
    const izinAciklama = "E2E gateway izin sureci";

    if (await surecModal.locator("[name='surec-create-turu']").count()) {
      await surecModal.locator("[name='surec-create-turu']").selectOption("IZIN");
    } else {
      await surecModal.locator("[name='surec-create-turu-text']").fill("IZIN");
    }

    if (await surecModal.locator("[name='surec-create-alt']").count()) {
      const altField = surecModal.locator("[name='surec-create-alt']");
      if ((await altField.evaluate((el) => el.tagName)) === "SELECT") {
        await altField.selectOption("YILLIK_IZIN");
      } else {
        await altField.fill("YILLIK_IZIN");
      }
    }

    await surecModal.locator("[name='surec-create-bas']").fill(izinBaslangic);
    await surecModal.locator("[name='surec-create-bitis']").fill(izinBaslangic);
    await surecModal.locator("[name='surec-create-aciklama']").fill(izinAciklama);

    await surecModal.getByRole("button", { name: "Süreci Kaydet" }).click();
    await expect(surecModal.locator(".workspace-success")).toContainText(/eklendi/i, { timeout: 15_000 });
    await surecModal.locator(".universal-btn-cancel").click();
    await expect(surecModal).toHaveCount(0);

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timeline = page
      .locator("#personel-kart-panel-surec-gecmisi")
      .locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toBeVisible();
    await expect(timeline).toContainText(/İzin/i);
    await expect(timeline).toContainText(/Yıllık|YILLIK/i);
    await expect(timeline).toContainText(izinBaslangic);
    await expect(timeline).toContainText(izinAciklama);

    expect(pageErrors).toEqual([]);
  });

  test("yonetici surec ekler ve isten ayrilma personel durumunu pasife ceker", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await expect(page.getByTestId("personel-sgk-prim-gun-card")).toContainText(/30 Gün/i);
    await expect(page.getByText(/30 gün standart/i)).toBeVisible();
    await expect(page.locator("#personel-kart-panel-genel-bilgiler")).toContainText(/Eksik Gün Nedeni/i);
    await expect(page.locator("#personel-kart-panel-genel-bilgiler")).toContainText("-");
    await expect(page.getByTestId("izin-bakiye-infobox")).toBeVisible();

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Süreç Ekle" }).click();

    const surecModal = page.locator(".modal-container").last();
    await expect(surecModal).toBeVisible();
    await expect(page).toHaveURL(/\/$/);
    await expect(surecModal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");
    await expect(surecModal.locator("[name='surec-create-personel']")).toHaveValue("1");

    if (
      (await surecModal.locator("[name='surec-create-turu']").count()) === 0 &&
      (await surecModal.locator("[name='surec-create-turu-text']").count()) === 0
    ) {
      await surecModal.locator(".surec-shell-action-tile").click();
      await expect(
        surecModal.locator("[name='surec-create-turu'], [name='surec-create-turu-text']").first()
      ).toBeVisible({ timeout: 10000 });
    }

    if (await surecModal.locator("[name='surec-create-turu']").count()) {
      await surecModal.locator("[name='surec-create-turu']").selectOption("ISTEN_AYRILMA");
    } else {
      await surecModal.locator("[name='surec-create-turu-text']").fill("ISTEN_AYRILMA");
    }

    await surecModal.locator("[name='surec-create-bas']").fill("2026-04-12");
    await surecModal.locator("[name='surec-create-bitis']").fill("2026-04-12");
    await surecModal.locator("[name='surec-create-aciklama']").fill("Is akdi sonlandirildi");
    await surecModal.getByRole("button", { name: "Süreci Kaydet" }).click();
    await expect(surecModal.locator(".workspace-success")).toContainText(/eklendi/i);
    await surecModal.locator(".universal-btn-cancel").click();

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await expect(page.locator(".personel-dosya-hero")).toContainText(/İşten Ayrıldı|Pasif/i);
    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const surecPanel = page.locator("#personel-kart-panel-surec-gecmisi");
    const timeline = surecPanel.locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText(/İşe Giriş/i);
    await expect(timeline).toContainText(/Kask/i);
    await expect(timeline).toContainText(/İsten ayrılma|Isten ayrilma|Isten Ayrilma/i);
    await expect(timeline).toContainText("Is akdi sonlandirildi");
  });

  test("yonetici zimmet ekler ve zimmet tablosunda kaydi gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Zimmet" }).click();

    const zimmetRow = (product: RegExp) =>
      page.locator(".personel-zimmet-table tbody tr").filter({ has: page.locator("td", { hasText: product }) });

    const kaskRow = zimmetRow(/Kask/i);
    await expect(kaskRow).toHaveCount(1);
    await expect(kaskRow.getByTestId("zimmet-durum")).toContainText(/Aktif/);

    const iadeRow = zimmetRow(/Kulak/i);
    await expect(iadeRow).toHaveCount(1);
    await expect(iadeRow.getByTestId("zimmet-durum")).toContainText(/Edildi/);

    const zimmetModal = await openZimmetCreateFromPersonelDosya(page);

    await zimmetModal.locator("[name='personel-zimmet-urun-turu']").selectOption("TELEFON");
    await zimmetModal.locator("[name='personel-zimmet-teslim-tarihi']").fill("2026-04-12");
    await zimmetModal.locator("[name='personel-zimmet-teslim-eden']").fill("IK Gorevlisi");
    await zimmetModal.locator("[name='personel-zimmet-teslim-durumu']").selectOption("YENI");
    await zimmetModal.locator("[name='personel-zimmet-aciklama']").fill("Seri No: TEL-900");
    await zimmetModal.getByRole("button", { name: "Kaydet" }).click();

    const telefonRow = zimmetRow(/Telefon/i);
    await expect(telefonRow).toHaveCount(1);
    await expect(telefonRow.getByTestId("zimmet-durum")).toContainText(/Aktif/);
    await expect(telefonRow.locator(".personel-zimmet-note-cell")).toContainText(/TEL-900/);
  });

  test("zimmet gateway donusu zimmet sekmesini acar kayit modalini kapatir ve F5 sonrasi tekrar acmaz", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Mehmet Kaya.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/2$/);

    await openZimmetCreateFromPersonelDosya(page);

    await page.reload({ waitUntil: "domcontentloaded" });
    await expect(page).toHaveURL(/\/personeller\/2$/);
    await assertGatewayStateCleared(page);
    await expect(
      page.locator(".modal-container").filter({ has: page.getByRole("heading", { name: /Yeni Zimmet Ekle/i }) })
    ).toHaveCount(0);
  });

  test("kart duzenle gateway donusu kayit modalini kapatir ve genel duzenleme modunu acar", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await openKartDuzenleFromActions(page);
  });

  test("kart duzenle gateway reload sonrasi kayit modalini ve gateway state tekrar acmaz", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await openKartDuzenleFromActions(page);

    await page.reload({ waitUntil: "domcontentloaded" });
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await assertGatewayStateCleared(page);
    await expect(page.locator('[name="edit-departman"]')).toHaveCount(0);
  });

  test("personel karti maas eksik uyarisini gosterir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/4");
    await expect(page).toHaveURL(/\/personeller\/4$/);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Maas Eksik/i);

    await expect(page.getByTestId("personel-maas-eksik-uyari")).toBeVisible();
    await expect(page.getByTestId("personel-maas-eksik-uyari")).toHaveText("Maaş bilgisi eksik.");

    await assertPersonelKartTabsVisible(page);
    expect(pageErrors).toEqual([]);
  });

  test("birim amiri personel kartinda yetkisiz aksiyonlari gormez", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    await mockApi(page, "BIRIM_AMIRI");

    await login(page, { username: "birim_amiri", password: "demo123" });

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Ayşe Yılmaz/i);

    await assertPersonelKartTabsVisible(page);

    await expect(page.getByRole("button", { name: "Kartı Düzenle" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Yeni Zimmet Ekle" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Süreç Ekle" })).toHaveCount(0);

    await page.getByRole("tab", { name: "Disiplin" }).click();
    const disiplinPanel = page.locator("#personel-kart-panel-disiplin");
    await expect(disiplinPanel.getByTestId("personel-disiplin-panel")).toBeVisible();
    await expect(disiplinPanel.getByTestId("personel-disiplin-ceza-section")).toContainText(
      "Finans ceza kayıtlarını görüntüleme yetkiniz yok."
    );
    await expect(disiplinPanel.getByTestId("personel-disiplin-ceza-list")).toHaveCount(0);
    await expect(disiplinPanel.getByRole("button", { name: "Kaydet" })).toHaveCount(0);

    await page.getByRole("tab", { name: "Zimmet" }).click();
    const zimmetPanel = page.locator("#personel-kart-panel-zimmet-envanter");
    await expect(zimmetPanel.locator(".personel-zimmet-panel")).toBeVisible();
    await expect(zimmetPanel.getByRole("button", { name: "Yeni Zimmet Ekle" })).toHaveCount(0);
    await expect(zimmetPanel.locator(".personel-zimmet-table tbody tr")).not.toHaveCount(0);

    expect(pageErrors).toEqual([]);
  });

  test("personel karti surec gecmisi mevcut eventleri sirali gosterir", async ({ page }) => {
    const pageErrors = trackPageErrors(page);
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const surecPanel = page.locator("#personel-kart-panel-surec-gecmisi");
    const timeline = surecPanel.locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toBeVisible();

    await expect(timeline).toContainText(/İşe Giriş/i);
    await expect(timeline).toContainText(/İzin|Izin/i);
    await expect(timeline).toContainText(/Devamsızlık|Devamsizlik/i);
    await expect(timeline).toContainText(/Zimmet teslim/i);
    await expect(timeline).toContainText(/Kask/i);

    const firstItem = timeline.locator("li").first();
    await expect(firstItem).toContainText(/İzin|Izin/i);
    await expect(firstItem).toContainText(/2026-04-10/);

    expect(pageErrors).toEqual([]);
  });

  test("birim amiri personel kartinda gateway baslatan aksiyonlari goremez", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");

    await login(page, { username: "birim_amiri", password: "demo123" });

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await expect(page.getByRole("button", { name: "Kartı Düzenle" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Yeni Zimmet Ekle" })).toHaveCount(0);
    await expect(page.getByText(/Kart düzenleme işlemi merkez ekrana taşınıyor/i)).toHaveCount(0);
    await expect(page.getByText(/Zimmet işlemi merkez ekrana taşınıyor/i)).toHaveCount(0);
    await expect(kayitSurecModal(page)).toHaveCount(0);
  });

  test("yonetici surec modalinda zimmet sekmesinden zimmet ekler kartta liste ve timeline gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toBeVisible();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();
    await expect(kayitModal.getByRole("tab", { name: "Genel" })).toHaveAttribute("aria-selected", "true");

    await kayitModal.getByRole("tab", { name: "Zimmet" }).click();
    await kayitModal.locator("[name='personel-zimmet-urun-turu']").selectOption("MASKE");
    await kayitModal.locator("[name='personel-zimmet-teslim-tarihi']").fill("2026-05-10");
    await kayitModal.locator("[name='personel-zimmet-teslim-eden']").fill("IK Surec E2E");
    await kayitModal.locator("[name='personel-zimmet-teslim-durumu']").selectOption("YENI");
    await kayitModal.locator("[name='personel-zimmet-aciklama']").fill("SUREC-ZIM-E2E-MASKE");

    await kayitModal.locator('button[type="submit"][form="kayit-surec-zimmet-form"]').click();

    await kayitModal.getByRole("button", { name: "Kapat" }).click();
    await expect(page).toHaveURL("/");

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Zimmet" }).click();
    const maskeRow = page
      .locator(".personel-zimmet-table tbody tr")
      .filter({ has: page.locator("td", { hasText: /Maske/i }) })
      .filter({ has: page.locator(".personel-zimmet-note-cell", { hasText: /SUREC-ZIM-E2E-MASKE/ }) });
    await expect(maskeRow).toHaveCount(1);
    await expect(maskeRow.getByTestId("zimmet-durum")).toContainText(/Aktif/i);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timeline = page
      .locator("#personel-kart-panel-surec-gecmisi")
      .locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText(/Zimmet teslim/i);
    await expect(timeline).toContainText(/Maske/i);
    await expect(timeline).toContainText(/SUREC-ZIM-E2E-MASKE/i);
  });

  test("yonetici departman ve gecerlilik tarihi ile org surecini uretir ve timeline tepesinde gosterir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await openKartDuzenleFromActions(page);

    await page.locator('[name="edit-departman"]').selectOption("2");
    const effectiveDateInput = page.locator('[name="edit-effective-date"]');
    if (await effectiveDateInput.count()) {
      await effectiveDateInput.fill("2026-06-01");
    }
    await page.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-create-error")).toHaveCount(0);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Finans/i);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timeline.locator("li").first()).toContainText(/Org/i);
  });

  test("yonetici bagli amiri degistirdiginde ozel timeline olayi uretir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.locator('a[href="/personeller/1"]').first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await openKartDuzenleFromActions(page);

    await page.locator('[name="edit-bagli-amir"]').selectOption("10");
    const effectiveDateInput = page.locator('[name="edit-effective-date"]');
    if (await effectiveDateInput.count()) {
      await effectiveDateInput.fill("2026-06-15");
    }
    await page.getByRole("button", { name: "Kaydet" }).click();

    await page.locator("#personel-kart-tab-surec-gecmisi").click();
    const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText(/Amir/i);
    await expect(timeline).toContainText(/Demo Amir/i);
    await expect(timeline).toContainText(/İkinci Amir|Ikinci Amir/i);
  });

  test("yonetici izlenen org alanlarina dokunmadan kaydettiginde otomatik surec olusmaz", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timelineBefore = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    const countBefore = await timelineBefore.locator("li").count();

    await openKartDuzenleFromActions(page);
    await page.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-create-error")).toHaveCount(0);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timelineAfter = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timelineAfter.locator("li")).toHaveCount(countBefore);
    await expect(timelineAfter).not.toContainText("Mock otomatik org gecmis kaydi");
  });

  test("yonetici egitim belgeler sekmesinde read-only belge durumunu gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();
    const belgelerPanel = page.locator("#personel-kart-panel-egitim-belgeler");
    await expect(belgelerPanel).toBeVisible();
    await expect(belgelerPanel.getByTestId("personel-belgeler-panel")).toBeVisible();
    await expect(belgelerPanel).toContainText(/Belge Durumu/i);
    await expect(belgelerPanel).toContainText(/Kimlik/i);
    await expect(belgelerPanel).toContainText(/Yok/i);
    await expect(belgelerPanel).toContainText(/Eğitim & Sertifikalar/i);
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toBeVisible();
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toContainText(/Forklift Operatör Belgesi/i);
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toContainText(/B Sınıfı Ehliyet/i);
    await expect(belgelerPanel.getByTestId("personel-belge-kayit-list")).toContainText(/Yakında doluyor/i);
    await expect(belgelerPanel.locator('input[type="radio"]')).toHaveCount(0);
    await expect(belgelerPanel.getByRole("button", { name: "Kaydet" })).toHaveCount(0);
  });

  test("yonetici disiplin sekmesinde read-only ceza ve surec sinyallerini gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "Ceza" }).click();

    const uniqueDonem = "2031-09";
    const uniqueTutar = "4200.50";
    const uniqueAciklama = "E2E Disiplin kart ceza";

    await kayitModal.locator('[name="kayit-ceza-donem"]').fill(uniqueDonem);
    await kayitModal.locator('[name="kayit-ceza-tutar"]').fill(uniqueTutar);
    await kayitModal.locator('[name="kayit-ceza-aciklama"]').fill(uniqueAciklama);
    await kayitModal.locator('button[type="submit"][form="kayit-surec-ceza-form"]').click();
    await expect(kayitModal.locator('[name="kayit-ceza-tutar"]')).toHaveValue("", { timeout: 15_000 });
    await kayitModal.getByRole("button", { name: "Kapat" }).click();

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Disiplin" }).click();
    const disiplinPanel = page.locator("#personel-kart-panel-disiplin");
    await expect(disiplinPanel).toBeVisible();
    await expect(disiplinPanel.getByTestId("personel-disiplin-panel")).toBeVisible();
    await expect(disiplinPanel.getByTestId("personel-disiplin-ceza-section")).toBeVisible();
    await expect(disiplinPanel.getByTestId("personel-disiplin-ceza-list")).toContainText(uniqueDonem);
    await expect(disiplinPanel.getByTestId("personel-disiplin-ceza-list")).toContainText(/4\.200,50/);
    await expect(disiplinPanel.getByTestId("personel-disiplin-ceza-list")).toContainText(uniqueAciklama);
    await expect(disiplinPanel.getByTestId("personel-disiplin-surec-signals")).toContainText(/Devamsızlık/i);
    await expect(disiplinPanel.getByTestId("personel-disiplin-surec-list")).toContainText(/Demo devamsizlik sinyali/i);
    await expect(disiplinPanel.getByRole("button", { name: "Kaydet" })).toHaveCount(0);
    await expect(disiplinPanel.getByRole("button", { name: "Süreç Ekle" })).toHaveCount(0);

    await disiplinPanel.getByRole("button", { name: "Süreç Geçmişi'nde gör" }).click();
    await expect(page.locator("#personel-kart-panel-surec-gecmisi")).toBeVisible();
    await expect(page.locator("#personel-kart-tab-surec-gecmisi")).toHaveAttribute("aria-selected", "true");
  });
});
