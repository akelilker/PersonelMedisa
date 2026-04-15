import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("personel dosyasi surec akisi", () => {
  test("yonetici surec ekler ve isten ayrilma personel durumunu pasife ceker", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Puantaj" }).click();
    await expect(page.getByTestId("personel-sgk-prim-gun-card")).toContainText(/30 Gün/i);
    await expect(page.getByText(/30 gün standart/i)).toBeVisible();
    await expect(page.locator("#personel-kart-panel-puantaj")).toContainText(/Eksik Gün Nedeni/i);
    await expect(page.locator("#personel-kart-panel-puantaj")).toContainText("-");

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Süreç Ekle" }).click();

    const surecModal = page.locator(".modal-container").last();
    await expect(surecModal).toBeVisible();

    if (await surecModal.locator("[name='personel-surec-turu']").count()) {
      await surecModal.locator("[name='personel-surec-turu']").selectOption("ISTEN_AYRILMA");
    } else {
      await surecModal.locator("[name='personel-surec-turu-text']").fill("ISTEN_AYRILMA");
    }

    await surecModal.locator("[name='personel-surec-baslangic']").fill("2026-04-12");
    await surecModal.locator("[name='personel-surec-aciklama']").fill("Is akdi sonlandirildi");
    await surecModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-dosya-hero")).toContainText(/Isten Ayrildi|Pasif/i);
    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const surecPanel = page.locator("#personel-kart-panel-surec-gecmisi");
    const timeline = surecPanel.locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText(/İşe Giriş/i);
    await expect(timeline).toContainText(/Kask/i);
    await expect(timeline).toContainText(/Isten Ayr[\u0131i]lma/i);
    await expect(timeline).toContainText("Is akdi sonlandirildi");
  });

  test("yonetici zimmet ekler ve zimmet tablosunda kaydi gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Zimmet & Envanter" }).click();

    const zimmetRow = (product: RegExp) =>
      page.locator(".personel-zimmet-table tbody tr").filter({ has: page.locator("td", { hasText: product }) });

    const kaskRow = zimmetRow(/Kask/i);
    await expect(kaskRow).toHaveCount(1);
    await expect(kaskRow.getByTestId("zimmet-durum")).toContainText(/Edildi/);

    await page.getByRole("button", { name: "Yeni Zimmet Ekle" }).click();

    const zimmetModal = page.locator(".modal-container").last();
    await expect(zimmetModal).toBeVisible();

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

  test("yonetici departman ve gecerlilik tarihi ile org surecini uretir ve timeline tepesinde gosterir", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Kartı Düzenle" }).click();

    await page.locator('[name="edit-departman"]').selectOption("2");
    await page.locator('[name="edit-effective-date"]').fill("2026-06-01");
    await page.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-create-error")).toHaveCount(0);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Finans/i);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timeline.locator("li").first()).toContainText(/Org/i);
  });

  test("yonetici izlenen org alanlarina dokunmadan kaydettiginde otomatik surec olusmaz", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kişisinin kartını aç/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timelineBefore = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    const countBefore = await timelineBefore.locator("li").count();

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Kartı Düzenle" }).click();
    await page.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-create-error")).toHaveCount(0);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const timelineAfter = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timelineAfter.locator("li")).toHaveCount(countBefore);
    await expect(timelineAfter).not.toContainText("Mock otomatik org gecmis kaydi");
  });
});
