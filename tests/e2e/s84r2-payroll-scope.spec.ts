import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";

test.describe("S84-R2 payroll scope", () => {
  test.setTimeout(120_000);

  async function openBordroHazirlikPersonelKapsam(page: Page, personelId: number) {
    await page.goto(`/raporlar?panel=bordro-hazirlik&tab=personel-kapsam&personelId=${personelId}`);
    await expect(page.getByTestId("bordro-hazirlik-tab-personel-kapsam")).toHaveClass(/is-active/);
    await expect(page.getByTestId("bordro-personel-kapsam-panel")).toBeVisible();
    await expect(page.getByTestId("personel-bordro-kapsam-card")).toBeVisible({ timeout: 15_000 });
  }

  test("IK_SORUMLUSU: kapsam kartı, dry-run ve taslak oluşturma (Bordro Hazırlık)", async ({ page }) => {
    await loginAsMockRole(page, "IK_SORUMLUSU");
    await openBordroHazirlikPersonelKapsam(page, 1);

    await expect(page.getByTestId("personel-bordro-kapsam-bos")).toBeVisible();

    await page.getByTestId("personel-bordro-kapsam-yeni").click();
    const modal = page.locator(".modal-container").filter({
      has: page.getByRole("heading", { name: /Bordro Kapsam Kararı/i })
    }).last();
    await expect(modal).toBeVisible();
    await expect(modal.getByTestId("personel-bordro-kapsam-direkt-onay")).toHaveCount(0);

    await modal.locator('[name="pbk-aciklama"]').fill("E2E dry-run kapsam karari");
    await modal.locator('[name="pbk-baslangic"]').fill("2026-03-01");
    await modal.locator('[name="pbk-yil"]').fill("2026");
    await modal.locator('[name="pbk-ay"]').fill("3");
    await modal.getByTestId("personel-bordro-kapsam-dry-run").click();

    await expect(modal.getByTestId("personel-bordro-kapsam-dry-run-result")).toBeVisible();
    await expect(modal.getByTestId("personel-bordro-kapsam-revision-uyari")).toBeVisible();
    await expect(modal.getByTestId("personel-bordro-kapsam-dry-run-result")).toContainText(
      /Mevcut snapshot değişmez/i
    );

    await modal.getByTestId("personel-bordro-kapsam-kaydet").click();
    await expect(page.getByTestId("personel-bordro-kapsam-liste")).toBeVisible();
    await expect(page.getByTestId("personel-bordro-kapsam-liste").locator("li")).toHaveCount(1);
    await expect(page.getByTestId("personel-bordro-kapsam-liste")).toContainText(/TASLAK/);
  });

  test("GENEL_YONETICI: onay yolu (Bordro Hazırlık)", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await openBordroHazirlikPersonelKapsam(page, 1);

    await page.getByTestId("personel-bordro-kapsam-yeni").click();
    const modal = page.locator(".modal-container").filter({
      has: page.getByRole("heading", { name: /Bordro Kapsam Kararı/i })
    }).last();
    await expect(modal.getByTestId("personel-bordro-kapsam-direkt-onay")).toBeVisible();

    await modal.locator('[name="pbk-aciklama"]').fill("GY onayli kapsam karari");
    await modal.locator('[name="pbk-baslangic"]').fill("2026-04-01");
    await modal.locator('[name="pbk-yil"]').fill("2026");
    await modal.locator('[name="pbk-ay"]').fill("4");
    await modal.getByTestId("personel-bordro-kapsam-direkt-onay").check();
    await modal.getByTestId("personel-bordro-kapsam-dry-run").click();
    await expect(modal.getByTestId("personel-bordro-kapsam-dry-run-result")).toBeVisible();
    await modal.getByTestId("personel-bordro-kapsam-kaydet").click();

    await expect(page.getByTestId("personel-bordro-kapsam-liste")).toBeVisible();
    await expect(page.getByTestId("personel-bordro-kapsam-liste")).toContainText(/ONAYLANDI/);
  });

  test("Personel Kartı bordro kapsam RO + Bordro Hazırlıkta Gör linki", async ({ page }) => {
    await loginAsMockRole(page, "MUHASEBE");
    await page.getByTestId("menu-personel-karti").click();
    await page.getByRole("link", { name: /Ayşe Yılmaz.*kişisinin kartını aç/i }).first().click();
    await page.getByRole("tab", { name: "Genel" }).click();

    await expect(page.getByTestId("personel-bordro-kapsam-card")).toBeVisible();
    await expect(page.getByTestId("personel-bordro-kapsam-yeni")).toHaveCount(0);
    await expect(page.getByTestId("personel-bordro-kapsam-hazirlik-link")).toBeVisible();
  });
});
