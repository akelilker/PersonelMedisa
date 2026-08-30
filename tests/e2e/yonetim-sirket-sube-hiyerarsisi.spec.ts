import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("yonetim paneli sirket -> sube hiyerarsisi", () => {
  test.beforeEach(async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "genel_yonetici", password: "demo123" });
  });

  test("sirket kartlarindan sirket detayina inip yalnizca o sirketin subelerini yonetir", async ({ page }) => {
    await page.goto("/yonetim-paneli?tab=subeler");
    await expect(page.locator(".modal-header h2").first()).toContainText("ŞİRKET VE ŞUBE YÖNETİMİ");

    const section = page.getByTestId("yonetim-section-subeler");
    await expect(section).toHaveAttribute("data-mode", "sirketler");
    await expect(page.getByTestId("yonetim-sirket-card-1")).toContainText("Medisa");
    await expect(page.getByTestId("yonetim-sirket-card-2")).toContainText("Karyapı");
    await expect(page.getByTestId("yonetim-sirket-yeni")).toBeVisible();
    // Branch creation is only reachable inside a company, so a branch can never
    // be created without a parent.
    await expect(page.getByTestId("yonetim-sube-yeni")).toHaveCount(0);

    await page.getByTestId("yonetim-sirket-card-1").click();
    await expect(page).toHaveURL(/tab=subeler&sirket=1/);
    await expect(section).toHaveAttribute("data-mode", "sirket-detay");
    await expect(page.getByTestId("yonetim-sirket-breadcrumb")).toContainText("Şirketler");
    await expect(page.getByTestId("yonetim-sirket-breadcrumb")).toContainText("Medisa");

    const branches = page.locator(".yonetim-card-grid--branches");
    // Company detail shows the short name only, and only its own branches.
    await expect(branches).toContainText("Merkez");
    await expect(branches).not.toContainText("Medisa Merkez");
    await expect(branches).not.toContainText("Pasif Şube");

    // The deep link survives a reload.
    await page.reload();
    await expect(page.getByTestId("yonetim-sirket-breadcrumb")).toContainText("Medisa");
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Merkez");

    await page.getByTestId("yonetim-sirket-breadcrumb").getByRole("button", { name: "Şirketler" }).click();
    await expect(page).toHaveURL(/tab=subeler$/);
    await expect(section).toHaveAttribute("data-mode", "sirketler");
  });

  test("sirket detayinda sube olusturur, form sirket sormaz ve kod editte kilitlidir", async ({ page }) => {
    await page.goto("/yonetim-paneli?tab=subeler&sirket=2");
    await expect(page.getByTestId("yonetim-sirket-breadcrumb")).toContainText("Karyapı");

    await page.getByTestId("yonetim-sube-yeni").click();
    const subeModal = page.locator(".modal-container").last();
    // The parent company comes from the route: the form must not offer it.
    await expect(subeModal.locator('[name="yonetim-sube-sirket"]')).toHaveCount(0);
    await expect(subeModal.getByLabel(/Şube kısa adı/i)).toBeVisible();

    await subeModal.getByLabel("Şube Kodu").fill("KAR-ANK");
    await subeModal.getByLabel(/Şube kısa adı/i).fill("Ankara");
    await subeModal.getByTestId("yonetim-sube-departman-panel").getByRole("button", { name: /^Depo$/i }).click();
    await subeModal.getByTestId("yonetim-sube-kaydet").click();

    await expect(page.getByText("Şube tanımı eklendi.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Ankara");

    await page.locator(".yonetim-entity-card--branch-preview").filter({ hasText: "Ankara" }).first().click();
    const editModal = page.locator(".modal-container").last();
    await expect(editModal.locator(".modal-header h2")).toContainText("Şube Düzenle");
    await expect(editModal.getByLabel("Şube Kodu")).toBeDisabled();
  });

  test("ayni kisa ad ayni sirkette reddedilir, farkli sirkette kabul edilir", async ({ page }) => {
    await page.goto("/yonetim-paneli?tab=subeler&sirket=1");
    await page.getByTestId("yonetim-sube-yeni").click();
    let subeModal = page.locator(".modal-container").last();
    await subeModal.getByLabel("Şube Kodu").fill("MED-MRK2");
    await subeModal.getByLabel(/Şube kısa adı/i).fill("merkez");
    await subeModal.getByTestId("yonetim-sube-departman-panel").getByRole("button", { name: /^Depo$/i }).click();
    await subeModal.getByTestId("yonetim-sube-kaydet").click();

    await expect(page.getByTestId("yonetim-sube-form-error")).toContainText(
      /ayni kisa ada sahip|aynı kısa ada sahip/i
    );

    await page.goto("/yonetim-paneli?tab=subeler&sirket=2");
    await page.getByTestId("yonetim-sube-yeni").click();
    subeModal = page.locator(".modal-container").last();
    await subeModal.getByLabel("Şube Kodu").fill("KAR-MRK");
    await subeModal.getByLabel(/Şube kısa adı/i).fill("Merkez");
    await subeModal.getByTestId("yonetim-sube-departman-panel").getByRole("button", { name: /^Depo$/i }).click();
    await subeModal.getByTestId("yonetim-sube-kaydet").click();

    await expect(page.getByText("Şube tanımı eklendi.")).toBeVisible();
    await expect(page.locator(".yonetim-card-grid--branches")).toContainText("Merkez");
  });

  test("bagli subesi olan sirket silinemez ve mobil gorunumde akis calisir", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto("/yonetim-paneli?tab=subeler");
    await expect(page.getByTestId("yonetim-sirket-card-1")).toBeVisible();

    await page.getByTestId("yonetim-sirket-duzenle-1").click();
    const sirketModal = page.locator(".modal-container").last();
    await expect(sirketModal.getByLabel("Şirket Kodu")).toBeDisabled();
    await sirketModal.getByTestId("yonetim-sirket-sil").click();
    await page.getByTestId("yonetim-sirket-delete-dialog-confirm").click();

    await expect(page.getByTestId("yonetim-sirket-delete-dialog-error")).toContainText(/bagli sube|bağlı şube/i);
  });
});
