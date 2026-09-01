import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";
import { SUBE_DELETE_BLOCKED_MESSAGE } from "../../src/lib/yonetim/sube-delete";

test.describe("yonetim paneli ve aylik ozet", () => {
  test("genel yonetici ayarlar menusunden yonetim paneline gider, kullanici ekler ve sube tanimlar", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByTestId("header-settings-toggle").click();
    await expect(page.getByTestId("settings-yonetim-paneli")).toBeVisible();
    await expect(page.getByTestId("settings-yonetim-paneli")).toHaveText("Kullanýcý Yönetimi");
    await expect(page.getByTestId("settings-sube-yonetimi")).toBeVisible();
    await expect(page.getByTestId("settings-aylik-ozet")).toHaveCount(0);

    const settingsGeometry = await page.locator("#settings-menu").evaluate((panel) => {
      const bounds = panel.getBoundingClientRect();
      return {
        left: bounds.left,
        right: bounds.right,
        width: bounds.width,
        viewportWidth: window.innerWidth,
        maxWidth: getComputedStyle(panel).maxWidth
      };
    });
    expect(settingsGeometry.left).toBeGreaterThanOrEqual(-1);
    expect(settingsGeometry.right).toBeLessThanOrEqual(settingsGeometry.viewportWidth + 1);
    expect(settingsGeometry.width).toBeGreaterThanOrEqual(220);
    expect(settingsGeometry.maxWidth).not.toMatch(/^calc\(100%/);

    await page.getByTestId("settings-yonetim-paneli").click();
    await expect(page).toHaveURL(/\/yonetim-paneli\?tab=kullanicilar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("KULLANICI YÖNETÝMÝ");
    await expect(page.locator(".modal-header").getByTestId("yonetim-back-ayarlar")).toHaveCount(0);
    await expect(page.locator(".modal-header .modal-back-btn")).toHaveCount(0);
    await expect(page.getByTestId("yonetim-back-ayarlar")).toBeVisible();
    await expect(page.getByTestId("yonetim-back-ayarlar")).toContainText("Ayarlar");
    await expect(page.locator(".modal-body .yonetim-content-back")).toHaveCount(0);
    await expect(page.getByTestId("yonetim-section-kullanicilar")).toBeVisible();

    const yonetimGeometry = await page.locator(".modal-container--yonetim").evaluate((modal) => {
      const body = modal.querySelector(".modal-body");
      const pageRoot = modal.querySelector(".yonetim-page");
      const card = modal.querySelector(".yonetim-card-grid--users .yonetim-entity-card");
      const footer = document.querySelector("#app-footer");
      if (!(body instanceof HTMLElement) || !(pageRoot instanceof HTMLElement) || !(footer instanceof HTMLElement)) {
        throw new Error("Missing yönetim modal geometry owners");
      }
      const modalBounds = modal.getBoundingClientRect();
      const bodyBounds = body.getBoundingClientRect();
      const pageBounds = pageRoot.getBoundingClientRect();
      const cardBounds = card?.getBoundingClientRect();
      const footerBounds = footer.getBoundingClientRect();
      const bodyStyle = getComputedStyle(body);
      return {
        modalLeft: modalBounds.left,
        modalRight: modalBounds.right,
        bodyLeft: bodyBounds.left,
        bodyRight: bodyBounds.right,
        pageLeft: pageBounds.left,
        pageRight: pageBounds.right,
        cardLeft: cardBounds?.left ?? null,
        cardRight: cardBounds?.right ?? null,
        footerGap: footerBounds.top - modalBounds.bottom,
        overflowY: bodyStyle.overflowY,
        overflowX: bodyStyle.overflowX,
        viewportWidth: window.innerWidth
      };
    });
    expect(yonetimGeometry.modalLeft).toBeGreaterThanOrEqual(-1);
    expect(yonetimGeometry.modalRight).toBeLessThanOrEqual(yonetimGeometry.viewportWidth + 1);
    expect(yonetimGeometry.pageLeft).toBeGreaterThanOrEqual(yonetimGeometry.bodyLeft - 1);
    expect(yonetimGeometry.pageRight).toBeLessThanOrEqual(yonetimGeometry.bodyRight + 1);
    if (yonetimGeometry.cardLeft != null && yonetimGeometry.cardRight != null) {
      expect(yonetimGeometry.cardLeft).toBeGreaterThanOrEqual(yonetimGeometry.bodyLeft - 1);
      expect(yonetimGeometry.cardRight).toBeLessThanOrEqual(yonetimGeometry.bodyRight + 1);
    }
    expect(yonetimGeometry.footerGap).toBeGreaterThan(0);
    expect(["auto", "scroll", "overlay"]).toContain(yonetimGeometry.overflowY);
    expect(yonetimGeometry.overflowX).toBe("hidden");

    await page.setViewportSize({ width: 1280, height: 800 });

    await page.getByTestId("yonetim-kullanici-yeni").click();
    await expect(page.locator(".modal-header h2").last()).toContainText("Yeni Kullanýcý");
    await page.getByLabel("Kullanýcý Tipi").selectOption("HARICI");
    await page.getByLabel("Rol").selectOption("GENEL_YONETICI");
    await page.getByLabel("Kullanýcý Adý").fill("danisman_kullanici");
    await page.getByLabel("Ad Soyad").fill("Danýþman Kullanýcý");
    await page.getByLabel("Telefon").fill("05559998877");
    await page.getByLabel("Notlar").fill("Dýþarýdan danýþman eriþimi");
    await page.getByTestId("yonetim-kullanici-kaydet").click();

    await expect(page.getByText("Kullanýcý kaydý oluþturuldu.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--users")).toContainText(/KULLANICI/i);
    await expect(page.locator(".yonetim-card-grid--users")).toContainText("Tüm Þubeler");

    await page.goto("/yonetim-paneli?tab=subeler&sirket=1");
    await expect(page.locator(".modal-header h2").first()).toContainText("ÞÝRKET VE ÞUBE YÖNETÝMÝ");
    await expect(page.getByTestId("yonetim-section-subeler")).toBeVisible();
    await expect(page.getByRole("button", { name: /\+ Yeni Þube/i })).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Merkez");

    await page.getByTestId("yonetim-sube-yeni").click();
    await page.getByLabel("Þube Kodu").fill("ANK");
    await page.getByLabel(/Þube kýsa adý/i).fill("Ankara");
    await page.getByTestId("yonetim-sube-departman-panel").getByRole("button", { name: /\+ Yeni Departman/i }).click();
    await page.getByTestId("yonetim-sube-departman-panel").getByRole("button", { name: /^Depo$/i }).click();
    await page.getByPlaceholder("Yeni departman adý").fill("Kalite");
    await page.getByRole("button", { name: "Ekle" }).click();
    await expect(page.getByTestId("yonetim-sube-departman-panel")).toContainText("Kalite");
    await page.getByTestId("yonetim-sube-kaydet").click();

    await expect(page.getByText("Þube tanýmý eklendi.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Ankara");
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Kalite");
  });

  test("bolum yoneticisi raporlardan aylik ozeti gorur, bolum onayi verir ve yonetim paneline giremez", async ({
    page
  }) => {
    await mockApi(page, "BOLUM_YONETICISI");
    await login(page, { username: "bolum_yoneticisi", password: "demo123" });

    await page.getByTestId("header-settings-toggle").click();
    await expect(page.getByTestId("settings-yonetim-paneli")).toHaveCount(0);
    await expect(page.getByTestId("settings-sube-yonetimi")).toHaveCount(0);
    await page.keyboard.press("Escape");

    await page.getByTestId("menu-raporlar").click();
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");
    await page.getByRole("link", { name: "Aylýk Kapanýþ Özeti" }).click();
    await expect(page).toHaveURL(/view=aylik-kapanis/);

    await expect(page.getByTestId("aylik-kapanis-ozeti-section")).toBeVisible();
    await expect(page.getByTestId("aylik-kapanis-ozeti-section").locator("h2")).toContainText("Aylýk Kapanýþ Özeti");
    const aylikSection = page.getByTestId("aylik-kapanis-ozeti-section");
    const aylikOzetTable = aylikSection.locator(".raporlar-table tbody");
    await expect(aylikOzetTable.locator("tr")).toHaveCount(1);
    await expect(aylikOzetTable).toContainText("Mehmet Kaya");
    await expect(aylikOzetTable).toContainText("Depolama");
    await expect(aylikOzetTable).not.toContainText("Ayþe Yýlmaz");
    await expect(aylikOzetTable).not.toContainText("Merkez");

    const subeSelect = aylikSection.locator('[name="aylik-ozet-sube"]');
    const depolamaOption = subeSelect.locator("option").filter({ hasText: "Depolama" }).first();
    const depolamaValue = await depolamaOption.getAttribute("value");
    await subeSelect.selectOption(depolamaValue!);
    await aylikSection.getByRole("button", { name: "Özeti Getir" }).click();
    await expect(aylikOzetTable.locator("tr")).toHaveCount(1);
    await expect(aylikOzetTable).toContainText("Mehmet Kaya");
    await expect(aylikOzetTable).toContainText("Depolama");

    await page.getByTestId("aylik-ozet-bolum-onay").click();
    await expect(page.getByText("Seçili ay için bölüm onayý verildi.")).toBeVisible();
    await expect(page.locator(".yonetim-summary-card").first()).toContainText(/Operasyonel Tamamlandý/i);

    await page.goto("/yonetim-paneli");
    await expect(page).toHaveURL(/\/yetkisiz$/);
    await expect(page.getByRole("heading", { name: /Yetkisiz/i })).toBeVisible();
  });

  test("genel yonetici birim amiri rol ve sube degisikliklerini personel timeline'ina dusurur", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.getByTestId("header-settings-toggle").click();
    await page.getByTestId("settings-yonetim-paneli").click();
    await expect(page).toHaveURL(/\/yonetim-paneli\?tab=kullanicilar$/);

    await page.locator(".yonetim-entity-card").filter({ hasText: /Ayþe/i }).click();
    const kullaniciModal = page.locator(".modal-container").last();
    await expect(kullaniciModal).toBeVisible();
    await expect(kullaniciModal.locator(".modal-header h2")).toContainText("Kullanýcý Düzenle");

    await kullaniciModal.getByRole("button", { name: /Depolama/i }).click();
    await kullaniciModal.locator('[name="yonetim-kullanici-varsayilan-sube"]').selectOption("2");
    await kullaniciModal.getByTestId("yonetim-kullanici-kaydet").click();

    await expect(page.getByText("Kullanýcý yetkileri güncellendi.")).toBeVisible();

    await page.locator(".yonetim-entity-card").filter({ hasText: /Ayþe/i }).click();
    await expect(kullaniciModal).toBeVisible();
    await kullaniciModal.locator('[name="yonetim-kullanici-rol"]').selectOption("MUHASEBE");
    await kullaniciModal.getByTestId("yonetim-kullanici-kaydet").click();

    await expect(page.getByText("Kullanýcý yetkileri güncellendi.")).toBeVisible();

    await page.locator(".yonetim-entity-card").filter({ hasText: /Adnan/i }).click();
    await expect(kullaniciModal).toBeVisible();
    await kullaniciModal.locator('[name="yonetim-kullanici-tipi"]').selectOption("IC_PERSONEL");
    await kullaniciModal.locator('[name="yonetim-kullanici-personel"]').selectOption("2");
    await kullaniciModal.locator('[name="yonetim-kullanici-rol"]').selectOption("BIRIM_AMIRI");
    await kullaniciModal.locator('[name="yonetim-kullanici-varsayilan-sube"]').selectOption("2");
    await kullaniciModal.getByTestId("yonetim-kullanici-kaydet").click();

    await expect(page.getByText("Kullanýcý yetkileri güncellendi.")).toBeVisible();

    await page.goto("/personeller/1");
    await expect(page).toHaveURL(/\/personeller\/1$/);
    await page.getByRole("tab", { name: "Süreç Geçmiþi" }).click();
    const personelBirTimeline = page
      .locator("#personel-kart-panel-surec-gecmisi")
      .locator("[data-testid='personel-surec-timeline']");
    await expect(personelBirTimeline).toContainText(/Baðlý Bölüm \/ Þube Yetkisi Deðiþti/i);
    await expect(personelBirTimeline).toContainText(/Birim Amiri Atamasý Kaldýrýldý/i);

    await page.goto("/personeller/2");
    await expect(page).toHaveURL(/\/personeller\/2$/);
    await page.getByRole("tab", { name: "Süreç Geçmiþi" }).click();
    const personelIkiTimeline = page
      .locator("#personel-kart-panel-surec-gecmisi")
      .locator("[data-testid='personel-surec-timeline']");
    await expect(personelIkiTimeline).toContainText(/Birim Amiri Olarak Atandý/i);
  });

  test("genel yonetici ayarlar menusunden sube yonetimine gider, bos subeyi siler ve personelli subeyi silemez", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.getByTestId("header-settings-toggle").click();
    await page.getByTestId("settings-sube-yonetimi").click();
    await expect(page).toHaveURL(/\/yonetim-paneli\?tab=subeler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("ÞÝRKET VE ÞUBE YÖNETÝMÝ");
    await expect(page.getByTestId("yonetim-section-subeler")).toBeVisible();

    await page.getByTestId("yonetim-sirket-card-1").click();
    await expect(page.getByTestId("yonetim-sirket-breadcrumb")).toContainText("Medisa");

    await page.getByTestId("yonetim-sube-yeni").click();
    await page.getByLabel("Þube Kodu").fill("BOS");
    await page.getByLabel(/Þube kýsa adý/i).fill("Bos Sube");
    await page.getByTestId("yonetim-sube-departman-panel").getByRole("button", { name: /^Depo$/i }).click();
    await page.getByTestId("yonetim-sube-kaydet").click();
    await expect(page.getByText("Þube tanýmý eklendi.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Bos Sube");

    const bosSubeCard = page.locator(".yonetim-entity-card--branch-preview").filter({ hasText: "Bos Sube" });
    await bosSubeCard.click();
    const subeModal = page.locator(".modal-container").last();
    await expect(subeModal).toBeVisible();
    await expect(subeModal.locator(".modal-header h2")).toContainText("Þube Düzenle");
    await expect(subeModal.getByTestId("yonetim-sube-sil")).toBeVisible();

    await subeModal.getByTestId("yonetim-sube-sil").click();
    await expect(page.getByTestId("yonetim-sube-delete-dialog")).toBeVisible();
    await page.getByTestId("yonetim-sube-delete-dialog-confirm").click();

    await expect(page.getByText("Þube tanýmý silindi.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).not.toContainText("Bos Sube");

    const merkezCard = page.locator(".yonetim-entity-card--branch-preview").filter({ hasText: "Merkez" });
    await merkezCard.click();
    const merkezModal = page.locator(".modal-container").last();
    await expect(merkezModal).toBeVisible();

    await merkezModal.getByTestId("yonetim-sube-sil").click();
    await expect(page.getByTestId("yonetim-sube-delete-dialog")).toBeVisible();
    await page.getByTestId("yonetim-sube-delete-dialog-confirm").click();

    await expect(page.getByTestId("yonetim-sube-delete-dialog").getByRole("alert")).toContainText(
      SUBE_DELETE_BLOCKED_MESSAGE
    );
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Merkez");
  });

  test("genel yonetici departman olusturur, listede secer ve duplicate hatasi alir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.goto("/yonetim-paneli?tab=subeler&sirket=1");
    await expect(page.getByTestId("yonetim-section-subeler")).toBeVisible();

    await page.getByTestId("yonetim-sube-yeni").click();
    const panel = page.getByTestId("yonetim-sube-departman-panel");
    await panel.getByRole("button", { name: /\+ Yeni Departman/i }).click();
    await panel.getByPlaceholder("Yeni departman adý").fill("  Kalite Kontrol  ");
    await page.getByRole("button", { name: "Ekle" }).click();

    await expect(page.getByText(/"Kalite Kontrol" departmaný seçeneklere eklendi/i)).toBeVisible();
    await expect(panel).toContainText("Kalite Kontrol");
    await expect(panel.getByRole("button", { name: /^Kalite Kontrol$/i })).toBeVisible();

    await panel.getByRole("button", { name: /\+ Yeni Departman/i }).click();
    await panel.getByPlaceholder("Yeni departman adý").fill("kalite kontrol");
    await page.getByRole("button", { name: "Ekle" }).click();

    await expect(page.getByText("Bu departman adý zaten kayýtlý.")).toBeVisible();
  });

  test("yonetim paneli tab query param ile dogru bolum acilir ve url senkron kalir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });

    await page.goto("/yonetim-paneli?tab=subeler");
    await expect(page).toHaveURL(/\/yonetim-paneli\?tab=subeler$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("ÞÝRKET VE ÞUBE YÖNETÝMÝ");
    await expect(page.getByTestId("yonetim-section-subeler")).toBeVisible();
    await expect(page.getByTestId("yonetim-section-kullanicilar")).toHaveCount(0);
    await expect(page.locator(".yonetim-card-grid--branches")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--users")).toHaveCount(0);

    await page.goto("/yonetim-paneli?tab=kullanicilar");
    await expect(page).toHaveURL(/\/yonetim-paneli\?tab=kullanicilar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("KULLANICI YÖNETÝMÝ");
    await expect(page.getByTestId("yonetim-section-kullanicilar")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--users")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toHaveCount(0);

    await page.goto("/yonetim-paneli");
    await expect(page).toHaveURL(/\/yonetim-paneli$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("KULLANICI YÖNETÝMÝ");
    await expect(page.getByTestId("yonetim-section-kullanicilar")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--users")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toHaveCount(0);
  });
});
