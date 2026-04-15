import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("personel dosyasi surec akisi", () => {
  test("yonetici surec ekler ve isten ayrilma personel durumunu pasife ceker", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kisisi kartini ac/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Puantaj" }).click();
    await expect(page.getByTestId("personel-sgk-prim-gun-card")).toContainText(/30 Gun/i);
    await expect(page.getByText(/30 gun standart/i)).toBeVisible();
    await expect(page.locator("#personel-kart-panel-puantaj")).toContainText(/Eksik Gun Nedeni/i);
    await expect(page.locator("#personel-kart-panel-puantaj")).toContainText("-");

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Surec Ekle" }).click();

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
    await page.getByRole("tab", { name: "Surec Gecmisi" }).click();
    const surecPanel = page.locator("#personel-kart-panel-surec-gecmisi");
    const timeline = surecPanel.locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toContainText(/Ise Giris/i);
    await expect(timeline).toContainText(/Kask/i);
    await expect(timeline).toContainText(/Isten Ayr[\u0131i]lma/i);
    await expect(timeline).toContainText("Is akdi sonlandirildi");
  });

  test("yonetici zimmet ekler ve zimmet tablosunda kaydi gorur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kisisi kartini ac/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Zimmet & Envanter" }).click();
    await expect(page.locator(".personel-zimmet-table")).toContainText(/Kask/i);
    await expect(page.locator(".personel-zimmet-table")).toContainText(/Iade Edildi/i);

    await page.getByRole("button", { name: "Yeni Zimmet Ekle" }).click();

    const zimmetModal = page.locator(".modal-container").last();
    await expect(zimmetModal).toBeVisible();

    await zimmetModal.locator("[name='personel-zimmet-urun-turu']").selectOption("TELEFON");
    await zimmetModal.locator("[name='personel-zimmet-teslim-tarihi']").fill("2026-04-12");
    await zimmetModal.locator("[name='personel-zimmet-teslim-eden']").fill("IK Gorevlisi");
    await zimmetModal.locator("[name='personel-zimmet-teslim-durumu']").selectOption("YENI");
    await zimmetModal.locator("[name='personel-zimmet-aciklama']").fill("Seri No: TEL-900");
    await zimmetModal.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-zimmet-table")).toContainText(/Telefon/i);
    await expect(page.locator(".personel-zimmet-table")).toContainText(/Seri No: TEL-900/i);
    await expect(page.locator(".personel-zimmet-table")).toContainText(/Aktif Zimmet/i);
  });

  test("yonetici departman ve gecerlilik tarihi ile org surecini uretir ve timeline tepesinde gosterir", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kisisi kartini ac/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Karti Duzenle" }).click();

    await page.locator('[name="edit-departman"]').selectOption("2");
    await page.locator('[name="edit-effective-date"]').fill("2026-06-01");
    await page.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-create-error")).toHaveCount(0);
    await expect(page.locator(".personel-dosya-hero")).toContainText(/Finans/i);

    await page.getByRole("tab", { name: "Surec Gecmisi" }).click();
    const timeline = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timeline.locator("li").first()).toContainText(/Org/i);
  });

  test("yonetici izlenen org alanlarina dokunmadan kaydettiginde otomatik surec olusmaz", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");

    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    await expect(page).toHaveURL(/\/personeller$/);

    await page.getByRole("link", { name: /Ayse Yilmaz.*kisisi kartini ac/i }).first().click();
    await expect(page).toHaveURL(/\/personeller\/1$/);

    await page.getByRole("tab", { name: "Surec Gecmisi" }).click();
    const timelineBefore = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    const countBefore = await timelineBefore.locator("li").count();

    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByRole("button", { name: "Karti Duzenle" }).click();
    await page.getByRole("button", { name: "Kaydet" }).click();

    await expect(page.locator(".personel-create-error")).toHaveCount(0);

    await page.getByRole("tab", { name: "Surec Gecmisi" }).click();
    const timelineAfter = page.locator("#personel-kart-panel-surec-gecmisi").locator("[data-testid='personel-surec-timeline']");
    await expect(timelineAfter.locator("li")).toHaveCount(countBefore);
    await expect(timelineAfter).not.toContainText("Mock otomatik org gecmis kaydi");
  });
});
