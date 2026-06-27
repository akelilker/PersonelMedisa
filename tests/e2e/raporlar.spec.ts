import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";
import { getRaporColumns } from "../../src/features/raporlar/rapor-column-contract";
import type { RaporTipi } from "../../src/types/rapor";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const RAPOR_SMOKE_CASES: Array<{ type: RaporTipi; rowMarker: string }> = [
  { type: "personel-ozet", rowMarker: "Ayşe Yılmaz" },
  { type: "izin", rowMarker: "Ayşe Yılmaz" },
  { type: "devamsizlik", rowMarker: "Ayşe Yılmaz" },
  { type: "tesvik", rowMarker: "Ayşe Yılmaz" },
  { type: "ceza", rowMarker: "Ayşe Yılmaz" },
  { type: "ekstra-prim", rowMarker: "Ayşe Yılmaz" },
  { type: "is-kazasi", rowMarker: "Ayşe Yılmaz" },
  { type: "bildirim", rowMarker: "Mehmet Kaya" }
];

test.describe("raporlar detayli liste smoke", () => {
  test.beforeEach(async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);
    await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");
  });

  for (const { type, rowMarker } of RAPOR_SMOKE_CASES) {
    test(`${type} raporunu calistirir ve kolon contract basliklarini gosterir`, async ({ page }) => {
      const columns = getRaporColumns(type);

      await page.locator('[name="rapor-turu"]').selectOption(type);
      await page.getByTestId("raporlar-submit-run").click();

      const resultCard = page.getByTestId("raporlar-resmi-sonuc");
      await expect(resultCard).toBeVisible();
      await expect(resultCard).toContainText("1");
      await expect(resultCard.locator("tbody tr")).toHaveCount(1);
      await expect(resultCard.locator("tbody")).toContainText(rowMarker);

      const headerTexts = await resultCard.locator("thead th").allTextContents();
      expect(headerTexts.map((text) => text.trim())).toEqual(columns.map((column) => column.label));
    });
  }

  test("personel ozet raporunda sayfalama ile ikinci sayfaya gecer ve geri doner", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await page.locator('[name="rapor-turu"]').selectOption("personel-ozet");
    await page.getByTestId("raporlar-submit-run").click();

    const resultCard = page.getByTestId("raporlar-resmi-sonuc");
    await expect(resultCard).toBeVisible();
    await expect(resultCard.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody tr")).toHaveCount(1);

    const oncekiButton = page.getByRole("button", { name: "Onceki" });
    const sonrakiButton = page.getByRole("button", { name: "Sonraki" });
    const pageInfo = page.locator(".module-page-info");

    await expect(sonrakiButton).toBeEnabled();
    await expect(oncekiButton).toBeDisabled();
    await expect(pageInfo).toContainText("Sayfa 1 / 2");

    await sonrakiButton.click();

    await expect(resultCard.locator("tbody")).toContainText("Mehmet Kaya");
    await expect(resultCard.locator("tbody")).not.toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody tr")).toHaveCount(1);
    await expect(oncekiButton).toBeEnabled();
    await expect(sonrakiButton).toBeDisabled();
    await expect(pageInfo).toContainText("Sayfa 2 / 2");

    await oncekiButton.click();

    await expect(resultCard.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody")).not.toContainText("Mehmet Kaya");
    await expect(resultCard.locator("tbody tr")).toHaveCount(1);
    await expect(oncekiButton).toBeDisabled();
    await expect(sonrakiButton).toBeEnabled();
    await expect(pageInfo).toContainText("Sayfa 1 / 2");
    expect(runtimeErrors).toEqual([]);
  });

  test("aylik kapanis ozeti csv export dosyasini indirir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    const aylikSection = page.getByTestId("aylik-kapanis-ozeti-section");
    await expect(aylikSection).toBeVisible();
    await expect(aylikSection.locator("h2")).toContainText("Aylık Kapanış Özeti");
    await expect(aylikSection.locator(".raporlar-table tbody tr")).toHaveCount(2);

    const exportButton = aylikSection.getByRole("button", { name: "Excel'e Aktar" });
    await expect(exportButton).toBeVisible();

    const downloadPromise = page.waitForEvent("download");
    await exportButton.click();
    const download = await downloadPromise;

    const filename = download.suggestedFilename();
    expect(filename).toContain("aylik-kapanis-ozeti");
    expect(filename.endsWith(".csv")).toBe(true);

    const downloadPath = await download.path();
    expect(downloadPath).not.toBeNull();

    const csvContent = readFileSync(downloadPath!, "utf-8");
    expect(csvContent.trim().length).toBeGreaterThan(0);
    expect(csvContent).toContain("Ad Soyad");
    expect(csvContent).toContain("Ayşe Yılmaz");
    expect(csvContent).toContain("Mehmet Kaya");
    expect(runtimeErrors).toEqual([]);
  });
});
