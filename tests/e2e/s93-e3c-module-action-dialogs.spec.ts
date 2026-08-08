import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

function trackNativeDialogs(page: Page) {
  const nativeDialogs: string[] = [];
  page.on("dialog", (dialog) => {
    nativeDialogs.push(`${dialog.type()}:${dialog.message()}`);
    void dialog.dismiss();
  });
  return nativeDialogs;
}

test.describe("S93-E3C Yönetim ve Kayıt action dialogs", () => {
  test("GENEL_YONETICI: şube sil dialogu native confirm oluşturmaz", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/yonetim-paneli?tab=subeler");
    await expect(page.getByTestId("yonetim-section-subeler")).toBeVisible();

    await page.getByTestId("yonetim-sube-yeni").click();
    await page.getByLabel("Şube Kodu").fill("E3C");
    await page.getByLabel("Şube Adı").fill("E3C Sube");
    await page.getByTestId("yonetim-sube-departman-panel").getByRole("button", { name: /^Depo$/i }).click();
    await page.getByTestId("yonetim-sube-kaydet").click();
    await expect(page.getByText("Şube tanımı eklendi.")).toBeVisible();

    const card = page.locator(".yonetim-entity-card--branch-preview").filter({ hasText: "E3C Sube" });
    await card.click();
    const subeModal = page.locator(".modal-container").last();
    await expect(subeModal.getByTestId("yonetim-sube-sil")).toBeVisible();
    await subeModal.getByTestId("yonetim-sube-sil").click();

    await expect(page.getByTestId("yonetim-sube-delete-dialog")).toBeVisible();
    await expect(page.getByTestId("yonetim-sube-delete-dialog-title")).toHaveText("Şubeyi Sil");
    await expect(page.getByTestId("yonetim-sube-delete-dialog-cancel")).toBeFocused();
    expect(nativeDialogs).toEqual([]);

    await page.getByTestId("yonetim-sube-delete-dialog-confirm").click();
    await expect(page.getByText("Şube tanımı silindi.")).toBeVisible();
    await expect(page.getByTestId("yonetim-sube-delete-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: mevzuat iptal dialogu destructive ve ilk odak Vazgeç", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/yonetim-paneli?tab=mevzuat");
    await expect(page.getByTestId("yonetim-section-mevzuat")).toBeVisible();

    await page.getByTestId("yonetim-mevzuat-yeni").click();
    const createModal = page
      .locator(".modal-container")
      .filter({ has: page.getByRole("heading", { name: /Yeni Mevzuat Parametresi/i }) })
      .last();
    await createModal.locator('[name="mevzuat-kod"]').fill("E3C_TEST_ORAN");
    await createModal.locator('[name="mevzuat-deger-tipi"]').selectOption("SAYISAL");
    await createModal.locator('[name="mevzuat-sayisal-deger"]').fill("0.11");
    await createModal.locator('[name="mevzuat-baslangic"]').fill("2026-01-01");
    await createModal.getByRole("button", { name: "Kaydet" }).click();

    const row = page.locator('[data-testid^="yonetim-mevzuat-satir-"]').first();
    await expect(row).toBeVisible();
    await row.getByRole("button", { name: "İptal Et" }).click();

    await expect(page.getByTestId("mevzuat-action-dialog")).toBeVisible();
    await expect(page.getByTestId("mevzuat-action-dialog-cancel")).toBeFocused();
    expect(nativeDialogs).toEqual([]);

    await page.getByTestId("mevzuat-action-dialog-cancel").click();
    await expect(page.getByTestId("mevzuat-action-dialog")).toHaveCount(0);

    await row.getByRole("button", { name: "İptal Et" }).click();
    await page.getByTestId("mevzuat-action-dialog-confirm").click();
    await expect(page.getByTestId("mevzuat-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("GENEL_YONETICI: belge kaydı iptal dialogu field ile neden alır", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "GENEL_YONETICI");

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();
    await kayitModal.getByTestId("kayit-surec-subtab-belgeler").click();

    const uniqueAd = `E3C Belge ${Date.now()}`;
    const panel = kayitModal.getByTestId("personel-belgeler-panel");
    await panel.getByTestId("personel-belge-yeni-btn").click();
    await page.getByTestId("personel-belge-ad").fill(uniqueAd);
    await page.locator("#personel-belge-tipi").selectOption("SERTIFIKA");
    await page.locator("#personel-belge-baslangic").fill("2026-01-01");
    await page.locator("#personel-belge-bitis").fill("2027-01-01");
    await page.getByTestId("personel-belge-create-submit").click();
    await expect(kayitModal.getByText(/Belge kaydı eklendi/i)).toBeVisible();

    const uniqueRow = panel.getByTestId("personel-belge-kayit-list").locator("tr", { hasText: uniqueAd });
    const rowTestId = await uniqueRow.getAttribute("data-testid");
    const kayitId = rowTestId?.replace("personel-belge-kayit-row-", "") ?? "";
    await uniqueRow.getByTestId(`personel-belge-iptal-${kayitId}`).click();

    await expect(page.getByTestId("personel-belge-action-dialog")).toBeVisible();
    await expect(page.getByTestId("personel-belge-action-dialog-cancel")).toBeFocused();
    expect(nativeDialogs).toEqual([]);

    await page.getByLabel("İptal nedeni").fill("E3C iptal nedeni");
    await page.getByTestId("personel-belge-action-dialog-confirm").click();
    await expect(kayitModal.getByText(/Belge kaydı iptal edildi/i)).toBeVisible();
    await expect(page.getByTestId("personel-belge-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });
});
