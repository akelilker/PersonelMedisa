import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { expectThreeButtonMainMenu } from "./helpers/main-menu";
import { mockApi } from "./helpers/mock-api";

function readonlyFieldByLabel(page: Parameters<typeof test>[0]["page"], label: string) {
  return page.locator(".form-section").filter({ hasText: label });
}

function readonlyFieldInCardByLabel(
  container: ReturnType<Parameters<typeof test>[0]["page"]["locator"]>,
  label: string
) {
  return container.locator(".form-section").filter({ hasText: label });
}

test.describe("e2e smoke", () => {
  test("puantaj ana detay karti beklenen giris ve cikis snapshot saatlerini gosterir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);

    await page.getByLabel("Personel ID").fill("1");
    await page.getByLabel("Tarih").fill("2026-04-14");
    await page.getByRole("button", { name: /Kayd.*Getir/i }).click();

    await page.getByRole("group", { name: "Hareket Durumu" }).getByRole("button", { name: "Geç Geldi" }).click();
    await page.locator("[name='puantaj-beklenen-giris']").fill("08:00");
    await page.locator("[name='puantaj-beklenen-cikis']").fill("17:00");
    await page.locator("[name='puantaj-giris']").fill("08:01");
    await page.locator("[name='puantaj-cikis']").fill("17:00");
    await page.locator("[name='puantaj-mola']").fill("60");
    await page.getByRole("button", { name: "Kaydet" }).click();

    const gunlukDetayKarti = page.getByTestId("puantaj-ana-detay");
    await expect(readonlyFieldInCardByLabel(gunlukDetayKarti, "Beklenen Giriş")).toContainText("08:00");
    await expect(readonlyFieldInCardByLabel(gunlukDetayKarti, "Beklenen Çıkış")).toContainText("17:00");

    const kesintiOnIzlemeKarti = page
      .locator(".puantaj-detail-card")
      .filter({ has: page.getByRole("heading", { name: "Kesinti Adayı Ön İzleme" }) });
    await expect(kesintiOnIzlemeKarti).toBeVisible();
    await expect(
      readonlyFieldInCardByLabel(kesintiOnIzlemeKarti, "Gerçek Eksik Süre (dk)").getByText(/^1$/)
    ).toBeVisible();
    await expect(
      readonlyFieldInCardByLabel(kesintiOnIzlemeKarti, "Kesintiye Esas Süre (dk)").getByText(/^30$/)
    ).toBeVisible();
  });

  test("management user completes login to kapanis flow", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await expect(page).toHaveURL("/");
    await expectThreeButtonMainMenu(page, true);

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("heading", { name: "Personeller" })).toBeVisible();
    await expect(page.getByText("Ayşe Yılmaz")).toBeVisible();

    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Personel Kartı");

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Puantaj");

    await page.getByLabel("Personel ID").fill("1");
    await page.getByLabel("Tarih").fill("2026-04-12");
    await page.getByRole("button", { name: /Kayd.*Getir/i }).click();

    const gunlukDetayKarti = page.getByTestId("puantaj-ana-detay");
    await expect(readonlyFieldInCardByLabel(gunlukDetayKarti, "Kayıt Durumu")).toContainText(/Hesapland/i);
    await expect(readonlyFieldInCardByLabel(gunlukDetayKarti, "Net Çalışma (dk)").getByText(/^510$/)).toBeVisible();

    await page.locator("[name='puantaj-giris']").fill("08:30");
    await page.locator("[name='puantaj-cikis']").fill("18:00");
    await page.locator("[name='puantaj-mola']").fill("60");
    await page.getByRole("button", { name: "Kaydet" }).click();
    await expect(readonlyFieldInCardByLabel(gunlukDetayKarti, "Günlük Brüt Süre (dk)").getByText(/^570$/)).toBeVisible();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/$/);

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");
    await expect(page.getByTestId("aylik-kapanis-ozeti-section")).toBeVisible();
    await page.getByTestId("raporlar-submit-run").click();
    await expect(page.getByTestId("raporlar-resmi-sonuc")).toContainText("1");
    await expect(page.getByTestId("raporlar-resmi-sonuc")).toContainText("SGK Prim Gün");
  });

  test("birim amiri gunluk kayit girer ama puantaj ve kapanis tarafinda read-only kalir", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");

    await login(page, { username: "birim", password: "secret" });

    await expect(page).toHaveURL("/");
    await expectThreeButtonMainMenu(page, false);

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);
    await page.getByRole("link", { name: "Günlük Kayıt" }).click();
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Kayıt Merkezi");
    await page.getByRole("button", { name: /Günlük Kayıt Gir|Yeni Günlük Kayıt/i }).click();

    const amirBildirimModal = page.locator(".modal-container").last();
    await expect(amirBildirimModal).toBeVisible();
    await amirBildirimModal.getByLabel("Tarih").fill("2026-04-11");
    await amirBildirimModal.getByLabel("Personel").selectOption("1");
    await amirBildirimModal
      .getByRole("group", { name: "Kayıt Senaryosu" })
      .getByRole("button", { name: "İzinsiz Gelmedi", exact: true })
      .click();
    await amirBildirimModal.getByLabel("Not / Açıklama").fill("Habersiz devamsızlık");
    await amirBildirimModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".bildirimler-list")).toContainText(/Gelmedi/i);
    await expect(page.locator(".bildirimler-list")).toContainText("Ayşe Yılmaz");
    await expect(page.locator(".bildirimler-list")).toContainText(/Kayıt Durumu: .*Taslak/i);

    const createdRow = page.locator(".bildirimler-item").first();
    await expect(createdRow.getByRole("button", { name: "Gönder" })).toBeVisible();
    await expect(createdRow.getByRole("button", { name: /Düzenle|Duzenle/i })).toBeVisible();
    await createdRow.getByRole("button", { name: "Gönder" }).click();
    await expect(createdRow).toContainText(/Kayıt Durumu: .*Gönderildi/i);
    await expect(createdRow.getByRole("button", { name: "Gönder" })).toHaveCount(0);
    await expect(createdRow.getByRole("button", { name: /Düzenle|Duzenle/i })).toHaveCount(0);
    await expect(createdRow.getByRole("button", { name: /İptal|Iptal/i })).toHaveCount(0);

    await page.goto("/personeller");
    await expect(page).toHaveURL(/\/personeller$/);
    await expect(page.getByRole("button", { name: "Yeni Personel" })).toHaveCount(0);

    await page.goto("/puantaj");
    await expect(page).toHaveURL(/\/puantaj$/);
    await expect(page.getByRole("button", { name: "Kaydet" })).toBeDisabled();

    await page.goto("/haftalik-kapanis");
    await expect(page).toHaveURL(/\/$/);

    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: /Yetkisiz/i })).toBeVisible();
  });

  test("management user can create update and cancel surec bildirim and finans", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await expect(page).toHaveURL("/");

    await page.goto("/surecler");
    await expect(page).toHaveURL(/\/surecler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Süreç Takibi");

    await page.getByRole("button", { name: /Yeni S.*re.*/i }).click();
    const surecCreateModal = page.locator(".modal-container").last();
    await expect(surecCreateModal).toBeVisible();
    await surecCreateModal.locator("[name='surec-create-personel']").fill("1");
    if (await surecCreateModal.getByRole("group", { name: "Süreç Türü" }).count()) {
      await surecCreateModal.getByRole("group", { name: "Süreç Türü" }).getByRole("button", { name: "Rapor" }).click();
    } else {
      await surecCreateModal.locator("[name='surec-create-turu-text']").fill("RAPOR");
    }
    await surecCreateModal.locator("[name='surec-create-bas']").fill("2026-04-12");
    await surecCreateModal.locator("[name='surec-create-bitis']").fill("2026-04-12");
    await surecCreateModal.locator("[name='surec-create-aciklama']").fill("Yeni surec kaydi");
    await surecCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText(/Rapor/i);

    await page.locator(".surecler-list .module-item-actions button").first().click();
    const surecEditModal = page.locator(".modal-container").last();
    await expect(surecEditModal).toBeVisible();
    if (await surecEditModal.getByRole("group", { name: "Süreç Türü" }).count()) {
      await surecEditModal.getByRole("group", { name: "Süreç Türü" }).getByRole("button", { name: "Rapor" }).click();
    } else {
      await surecEditModal.locator("[name='surec-edit-turu-text']").fill("RAPOR_GUNCEL");
    }
    await surecEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".surecler-list")).toContainText(/Rapor/i);

    page.once("dialog", (dialog) => void dialog.accept());
    await page.locator(".surecler-list .module-item-actions button").nth(1).click();
    await expect(page.locator(".surecler-list")).toContainText(/İptal|Iptal/i);

    await page.goto("/bildirimler");
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Günlük Kayıt Merkezi");
    await expect(
      page.getByRole("button", { name: /Yeni Günlük Kayıt|Günlük Kayıt Gir/i })
    ).toHaveCount(0);

    await page.goto("/finans");
    await expect(page).toHaveURL(/\/finans$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Finans");

    await page.getByRole("button", { name: /Yeni Finans Kalemi/i }).click();
    const finansCreateModal = page.locator(".modal-container").last();
    await expect(finansCreateModal).toBeVisible();
    await finansCreateModal.locator("[name='finans-create-personel']").fill("1");
    await finansCreateModal.locator("[name='finans-create-donem']").fill("2026-04");
    await finansCreateModal.locator("[name='finans-create-kalem']").fill("PRIM");
    await finansCreateModal.locator("[name='finans-create-tutar']").fill("1500");
    await finansCreateModal.locator("[name='finans-create-aciklama']").fill("Yeni finans kalemi");
    await finansCreateModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText(/Prim/i);

    await page.locator(".finans-list .module-item-actions button").first().click();
    const finansEditModal = page.locator(".modal-container").last();
    await expect(finansEditModal).toBeVisible();
    await finansEditModal.locator("[name='finans-edit-kalem']").fill("CEZA");
    await finansEditModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".finans-list")).toContainText(/Ceza/i);

    page.once("dialog", (dialog) => void dialog.accept());
    await page.locator(".finans-list .module-item-actions button").nth(1).click();
    await expect(page.locator(".finans-list")).toContainText(/İptal|Iptal/i);
  });

  test("bolum yoneticisi gonderilmis gunluk kayitta duzeltme isteyebilir", async ({ page }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, { username: "bolum_yoneticisi", password: "demo123" });

    await page.goto("/bildirimler");
    await expect(page).toHaveURL(/\/bildirimler$/);
    await expect(
      page.getByRole("button", { name: /Yeni Günlük Kayıt|Günlük Kayıt Gir/i })
    ).toHaveCount(0);

    const sentRow = page.locator(".bildirimler-item").first();
    await expect(sentRow).toContainText(/Kayıt Durumu: .*Gönderildi/i);
    await expect(sentRow.getByRole("button", { name: /Düzenle|Duzenle/i })).toHaveCount(0);
    await expect(sentRow.getByRole("button", { name: /İptal|Iptal/i })).toHaveCount(0);
    await sentRow.getByRole("button", { name: /Düzeltme iste/i }).click();

    const correctionModal = page.locator(".modal-container").last();
    await expect(correctionModal).toBeVisible();
    await correctionModal.getByLabel("Düzeltme Nedeni").fill("Saat bilgisi hatali");
    await correctionModal.getByRole("button", { name: "Gönder" }).click();

    await expect(sentRow).toContainText(/Kayıt Durumu: .*Düzeltme İstendi/i);
    await expect(sentRow.getByRole("button", { name: /Düzeltme iste/i })).toHaveCount(0);
  });

  test("birim amiri iptal edilen gunluk kayitta write aksiyonlarini gormez", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, { username: "birim", password: "secret" });

    await page.goto("/bildirimler");
    await page.getByRole("button", { name: /Günlük Kayıt Gir|Yeni Günlük Kayıt/i }).click();

    const createModal = page.locator(".modal-container").last();
    await createModal.getByLabel("Tarih").fill("2026-04-20");
    await createModal.getByLabel("Personel").selectOption("1");
    await createModal
      .getByRole("group", { name: "Kayıt Senaryosu" })
      .getByRole("button", { name: /Geç Geldi/i })
      .click();
    await createModal.getByRole("button", { name: "Kaydet" }).click();

    const createdRow = page.locator(".bildirimler-item").first();
    page.once("dialog", (dialog) => void dialog.accept());
    await createdRow.getByRole("button", { name: /İptal|Iptal/i }).click();

    await expect(createdRow).toContainText(/Kayıt Durumu: .*ptal/i);
    await expect(createdRow.getByRole("button", { name: /Düzenle|Duzenle/i })).toHaveCount(0);
    await expect(createdRow.getByRole("button", { name: "Gönder" })).toHaveCount(0);
    await expect(createdRow.getByRole("button", { name: /İptal|Iptal/i })).toHaveCount(0);
  });

  test("birim amiri haftalik mutabakat panelinde haftayi onaylar", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, { username: "birim", password: "secret" });

    await page.goto("/bildirimler");
    await expect(page.getByTestId("haftalik-mutabakat-panel")).toBeVisible();

    await page.locator("[name='haftalik-mutabakat-hafta-baslangic']").fill("2026-04-06");
    await expect(page.getByTestId("haftalik-mutabakat-count-toplam")).toContainText("1");
    await expect(page.getByTestId("haftalik-mutabakat-count-gonderildi")).toContainText("1");
    await expect(page.getByTestId("haftalik-mutabakat-status")).toContainText(/onaylanabilir/i);

    const approveButton = page.getByTestId("haftalik-mutabakat-approve");
    await expect(approveButton).toBeEnabled();
    await approveButton.click();

    await expect(page.getByTestId("haftalik-mutabakat-count-haftalik_mutabakata_alindi")).toContainText("1");
    await expect(page.getByTestId("haftalik-mutabakat-status")).toContainText(/mutabakata alinmis/i);
    await expect(page.getByTestId("haftalik-mutabakat-id")).toContainText("Mutabakat ID: 1");
    await expect(approveButton).toBeDisabled();
  });

  test("genel yonetici haftalik mutabakat panelini read-only gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/bildirimler");
    await expect(page.getByTestId("haftalik-mutabakat-panel")).toBeVisible();
    await page.getByLabel("Şube").selectOption("1");
    await expect(page.getByLabel("Birim Amiri")).toHaveValue("1");
    await page.locator("[name='haftalik-mutabakat-hafta-baslangic']").fill("2026-04-06");
    await expect(page.getByTestId("haftalik-mutabakat-count-toplam")).toBeVisible();
    await expect(page.getByTestId("haftalik-mutabakat-approve")).toHaveCount(0);
  });

  test("birim amiri aylik bildirim onay panelinde mevcut onayi gorur", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, { username: "birim", password: "secret" });

    await page.goto("/bildirimler");
    await expect(page.getByTestId("aylik-bildirim-onay-panel")).toBeVisible();
    await expect(page.locator("[name='aylik-bildirim-onay-ay']")).toBeVisible();

    await page.locator("[name='aylik-bildirim-onay-ay']").fill("2026-07");
    await expect(page.getByTestId("aylik-bildirim-onay-status")).toContainText(/aylık bildirim onayına gönderilmiş/i);
    await expect(page.getByTestId("aylik-bildirim-onay-id")).toContainText("Aylık Onay ID: 1");

    const approveButton = page.getByTestId("aylik-bildirim-onay-approve");
    await expect(approveButton).toBeDisabled();
    await expect(page.getByTestId("haftalik-mutabakat-panel")).toBeVisible();
  });

  test("genel yonetici aylik bildirim onay panelini read-only gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/bildirimler");
    await expect(page.getByTestId("aylik-bildirim-onay-panel")).toBeVisible();
    await page.getByLabel("Şube").selectOption("1");
    await expect(page.getByLabel("Birim Amiri")).toHaveValue("1");
    await page.locator("[name='aylik-bildirim-onay-ay']").fill("2026-07");
    await expect(page.getByTestId("aylik-bildirim-onay-counts")).toBeVisible();
    await expect(page.getByTestId("aylik-bildirim-onay-approve")).toHaveCount(0);
    await expect(page.getByTestId("haftalik-mutabakat-panel")).toBeVisible();
  });

  test("muhasebe aylik bildirim onay panelini read-only gorur", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, { username: "muhasebe", password: "secret" });

    await page.goto("/bildirimler");
    await expect(page.getByTestId("aylik-bildirim-onay-panel")).toBeVisible();
    await page.locator("[name='aylik-bildirim-onay-ay']").fill("2026-07");
    await expect(page.getByTestId("aylik-bildirim-onay-count-toplam_bildirim")).toBeVisible();
    await expect(page.getByTestId("aylik-bildirim-onay-approve")).toHaveCount(0);
  });
});
