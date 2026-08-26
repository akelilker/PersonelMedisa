import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { expectThreeButtonMainMenu } from "./helpers/main-menu";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec selected-person process navigation", () => {
  test.beforeEach(async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
  });

  test("home shows three cards without modules surfaces", async ({ page }) => {
    await expectThreeButtonMainMenu(page, true);
    await expect(page.getByTestId("menu-moduller")).toHaveCount(0);
  });

  test("hides process navigation until person is selected", async ({ page }) => {
    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await expect(kayitModal.getByTestId("kayit-surec-person-process-nav")).toHaveCount(0);
  });

  test("shows canonical process families for IC personel", async ({ page }) => {
    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByRole("option").first().click();

    const nav = kayitModal.getByTestId("kayit-surec-person-process-nav");
    await expect(nav).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Genel" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Puantaj" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Haftalık Kapanış" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Belge Takip" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Finans" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Pozisyon" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Belgeler" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Zimmet" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Ceza" })).toBeVisible();
    await expect(nav.getByRole("tab", { name: "Ayrılma" })).toBeVisible();

    await expect(nav.getByRole("tab", { name: "İzin / Devamsızlık" })).toHaveCount(0);
    await expect(nav.getByRole("tab", { name: "Günlük Kayıt" })).toHaveCount(0);
    await expect(nav.getByRole("tab", { name: "Revizyon Merkezi" })).toHaveCount(0);
  });

  test("puantaj exposes gunluk hareketler entry without peer gunluk kayit tab", async ({ page }) => {
    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByRole("option").first().click();
    await kayitModal.getByRole("tab", { name: "Puantaj" }).click();

    await expect(kayitModal.getByTestId("kayit-surec-puantaj-sub-gunluk-hareketler")).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-surec-puantaj-sub-izin")).toBeVisible();
  });

  test("person switch clears stale puantaj inline form", async ({ page }) => {
    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    const firstOption = kayitModal.getByRole("option").first();
    const firstLabel = (await firstOption.textContent()) ?? "";
    await firstOption.click();

    await kayitModal.getByRole("tab", { name: "Puantaj" }).click();
    await kayitModal.getByTestId("kayit-surec-puantaj-sub-izin").click();
    await expect(kayitModal.locator("[name='surec-create-bas']")).toBeVisible();

    await kayitModal.getByTestId("kayit-surec-personel-degistir").click();
    const secondOption = kayitModal
      .getByRole("option")
      .filter({ hasNotText: firstLabel.trim() })
      .first();
    await secondOption.click();

    await expect(kayitModal.locator("[name='surec-create-bas']")).toHaveCount(0);
    await expect(kayitModal.getByTestId("kayit-surec-person-process-nav")).toBeVisible();
  });
});
