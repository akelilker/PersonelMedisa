import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

function trackNativeDialogs(page: Page) {
  const nativeDialogs: string[] = [];
  page.on("dialog", (dialog) => {
    nativeDialogs.push(`${dialog.type()}:${dialog.message()}`);
    void dialog.dismiss();
  });
  return nativeDialogs;
}

async function openPersonelKart(page: Page, namePattern: RegExp) {
  await page.getByTestId("menu-personel-karti").click();
  await expect(page).toHaveURL(/\/personeller$/);
  await page.getByRole("link", { name: namePattern }).first().click();
  await expect(page).toHaveURL(/\/personeller\/\d+$/);
  await page.getByRole("tab", { name: "Genel" }).click();
}

async function setActiveSube(page: Page, subeId: number) {
  await page.evaluate((nextSubeId) => {
    const key = "medisa_auth_session";
    const fromSession = sessionStorage.getItem(key);
    const storage = fromSession ? sessionStorage : localStorage;
    const raw = fromSession ?? localStorage.getItem(key);
    if (!raw) {
      throw new Error("auth session missing");
    }
    const session = JSON.parse(raw) as { active_sube_id?: number | null };
    session.active_sube_id = nextSubeId;
    storage.setItem(key, JSON.stringify(session));
  }, subeId);
  await page.goto("/raporlar?panel=maas-hesaplama", { waitUntil: "domcontentloaded" });
}

async function submitMaasFilters(page: Page) {
  await page.getByLabel("Ay", { exact: true }).first().fill("2026-03");
  const subeSelect = page.getByLabel("Şube");
  await expect(subeSelect.locator('option[value="1"]')).toHaveCount(1, { timeout: 15_000 });
  await subeSelect.selectOption("1");
  const preflight = page.waitForResponse((response) =>
    response.url().includes("/api/maas-hesaplama/preflight")
  );
  await page.getByTestId("maas-hesaplama-submit").click();
  const response = await preflight;
  expect(response.status()).toBe(200);
}

test.describe("S93-E3D personel ve maaş action dialogs", () => {
  test("MUHASEBE: ücret iptal dialogu native confirm oluşturmaz (Süreç Mali)", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "MUHASEBE");

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container--kayit-surec");
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();
    await kayitModal.getByRole("tab", { name: "Mali İşlemler" }).click();

    await expect(kayitModal.getByTestId("personel-ucret-gecmisi-card")).toBeVisible();
    const cancelButton = kayitModal.locator('[data-testid^="personel-ucret-iptal-"]').first();
    if ((await cancelButton.count()) === 0) {
      await kayitModal.getByTestId("personel-ucret-yeni-donem").click();
      const modal = page
        .locator(".modal-container")
        .filter({ has: page.getByRole("heading", { name: /Yeni Ücret Dönemi Başlat/i }) })
        .last();
      await modal.locator('[name="ucret-tutar"]').fill("41000");
      await modal.locator('[name="ucret-baslangic"]').fill("2026-01-01");
      await modal.getByTestId("personel-ucret-form-kaydet").click();
      await expect(kayitModal.locator('[data-testid^="personel-ucret-iptal-"]').first()).toBeVisible();
    }

    await kayitModal.locator('[data-testid^="personel-ucret-iptal-"]').first().click();
    await expect(page.getByTestId("personel-ucret-action-dialog")).toBeVisible();
    await expect(page.getByTestId("personel-ucret-action-dialog-cancel")).toBeFocused();
    expect(nativeDialogs).toEqual([]);

    await page.getByTestId("personel-ucret-action-dialog-cancel").click();
    await expect(page.getByTestId("personel-ucret-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("MUHASEBE: bordro kapsam iptal dialogu field ile neden alır (Bordro Hazırlık)", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await loginAsMockRole(page, "MUHASEBE");
    await page.goto("/raporlar?panel=bordro-hazirlik&tab=personel-kapsam&personelId=1");
    await expect(page.getByTestId("personel-bordro-kapsam-card")).toBeVisible({ timeout: 15_000 });

    if ((await page.locator('[data-testid^="personel-bordro-kapsam-cancel-"]').count()) === 0) {
      await page.getByTestId("personel-bordro-kapsam-yeni").click();
      const modal = page
        .locator(".modal-container")
        .filter({ has: page.getByRole("heading", { name: /Bordro Kapsam Kararı/i }) })
        .last();
      await modal.locator('[name="pbk-aciklama"]').fill("E3D kapsam iptal setup");
      await modal.locator('[name="pbk-baslangic"]').fill("2026-05-01");
      await modal.locator('[name="pbk-yil"]').fill("2026");
      await modal.locator('[name="pbk-ay"]').fill("5");
      await modal.getByTestId("personel-bordro-kapsam-dry-run").click();
      await expect(modal.getByTestId("personel-bordro-kapsam-dry-run-result")).toBeVisible();
      await modal.getByTestId("personel-bordro-kapsam-kaydet").click();
      await expect(page.locator('[data-testid^="personel-bordro-kapsam-cancel-"]').first()).toBeVisible();
    }

    await page.locator('[data-testid^="personel-bordro-kapsam-cancel-"]').first().click();
    await expect(page.getByTestId("personel-bordro-kapsam-action-dialog")).toBeVisible();
    await expect(page.getByTestId("personel-bordro-kapsam-action-dialog-cancel")).toBeFocused();
    expect(nativeDialogs).toEqual([]);

    await page.getByLabel("İptal nedeni").fill("E3D iptal nedeni");
    await page.getByTestId("personel-bordro-kapsam-action-dialog-confirm").click();
    await expect(page.getByTestId("personel-bordro-kapsam-action-dialog")).toHaveCount(0);
    expect(nativeDialogs).toEqual([]);
  });

  test("MUHASEBE: snapshot oluştur dialogu native confirm oluşturmaz", async ({ page }) => {
    const nativeDialogs = trackNativeDialogs(page);
    await openRaporlarPanel(page, "MUHASEBE", "maas-hesaplama");
    await setActiveSube(page, 1);
    await submitMaasFilters(page);

    await expect(page.getByTestId("maas-hesaplama-create")).toBeEnabled();
    await page.getByTestId("maas-hesaplama-create").click();

    await expect(page.getByTestId("maas-hesaplama-create-dialog")).toBeVisible();
    await expect(page.getByTestId("maas-hesaplama-create-dialog-cancel")).toBeFocused();
    expect(nativeDialogs).toEqual([]);

    const create = page.waitForResponse(
      (response) =>
        response.url().includes("/api/maas-hesaplama/snapshotlar") && response.request().method() === "POST"
    );
    await page.getByTestId("maas-hesaplama-create-dialog-confirm").click();
    const createResponse = await create;
    expect([200, 201]).toContain(createResponse.status());
    await expect(page.getByTestId("maas-hesaplama-create-dialog")).toHaveCount(0);
    await expect(page.getByTestId("maas-hesaplama-action-success")).toBeVisible();
    expect(nativeDialogs).toEqual([]);
  });
});
