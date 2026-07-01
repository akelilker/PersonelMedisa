import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";
import { getRaporColumns } from "../../src/features/raporlar/rapor-column-contract";
import type { RaporTipi } from "../../src/types/rapor";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yonetici", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

const ROLE_AYLIK_SECTION_VISIBLE: Record<MockUserRole, boolean> = {
  GENEL_YONETICI: true,
  BOLUM_YONETICISI: true,
  MUHASEBE: false,
  BIRIM_AMIRI: false
};

const RAPOR_ROLE_CASES: MockUserRole[] = [
  "GENEL_YONETICI",
  "BOLUM_YONETICISI",
  "MUHASEBE",
  "BIRIM_AMIRI"
];

const AYLIK_ONAY_ROLE_CASES: Array<"GENEL_YONETICI" | "BOLUM_YONETICISI"> = [
  "GENEL_YONETICI",
  "BOLUM_YONETICISI"
];

const ROLE_AYLIK_ONAY_VISIBILITY: Record<
  (typeof AYLIK_ONAY_ROLE_CASES)[number],
  { bolumOnay: boolean; ustOnay: boolean }
> = {
  GENEL_YONETICI: { bolumOnay: false, ustOnay: true },
  BOLUM_YONETICISI: { bolumOnay: true, ustOnay: false }
};

const RAPOR_SMOKE_CASES: Array<{ type: RaporTipi; rowMarker: string }> = [
  { type: "personel-ozet", rowMarker: "Ayşe Yılmaz" },
  { type: "izin", rowMarker: "Ayşe Yılmaz" },
  { type: "devamsizlik", rowMarker: "Ayşe Yılmaz" },
  { type: "tesvik", rowMarker: "Ayşe Yılmaz" },
  { type: "ceza", rowMarker: "Ayşe Yılmaz" },
  { type: "ekstra-prim", rowMarker: "Ayşe Yılmaz" },
  { type: "is-kazasi", rowMarker: "Ayşe Yılmaz" },
  { type: "bildirim", rowMarker: "Ayşe Yılmaz" }
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

  test("personel ozet raporunda ayni ay tarih araliginda snapshot degerlerini gosterir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await page.locator('[name="rapor-turu"]').selectOption("personel-ozet");
    await page.locator('[name="rapor-bas"]').fill("2026-04-01");
    await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
    await page.getByTestId("raporlar-submit-run").click();

    const resultCard = page.getByTestId("raporlar-resmi-sonuc");
    await expect(resultCard).toBeVisible();
    await expect(resultCard.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody")).toContainText("510");
    await expect(resultCard.locator("tbody")).toContainText("30");
    expect(runtimeErrors).toEqual([]);
  });

  test("devamsizlik raporunda ayni ay tarih araliginda snapshot kaynakli satirlari gosterir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await page.locator('[name="rapor-turu"]').selectOption("devamsizlik");
    await page.locator('[name="rapor-bas"]').fill("2026-04-01");
    await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
    await page.getByTestId("raporlar-submit-run").click();

    const resultCard = page.getByTestId("raporlar-resmi-sonuc");
    await expect(resultCard).toBeVisible();
    await expect(resultCard.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody")).toContainText("IZINSIZ");
    await expect(resultCard.locator("tbody")).toContainText("2026-04-10");
    await expect(resultCard).not.toContainText("UNSUPPORTED_REPORT");
    expect(runtimeErrors).toEqual([]);
  });

  test("izin raporunda ayni ay tarih araliginda snapshot kaynakli gunluk satirlari gosterir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await page.locator('[name="rapor-turu"]').selectOption("izin");
    await page.locator('[name="rapor-bas"]').fill("2026-04-01");
    await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
    await page.getByTestId("raporlar-submit-run").click();

    const resultCard = page.getByTestId("raporlar-resmi-sonuc");
    await expect(resultCard).toBeVisible();
    await expect(resultCard.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody")).toContainText("YILLIK_IZIN");
    await expect(resultCard.locator("tbody")).toContainText("2026-04-03");
    await expect(resultCard.locator("tbody")).toContainText("Evet");
    await expect(resultCard).not.toContainText("UNSUPPORTED_REPORT");
    expect(runtimeErrors).toEqual([]);
  });

  test("is-kazasi raporunda tarih araliginda surec kaynakli satirlari gosterir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await page.locator('[name="rapor-turu"]').selectOption("is-kazasi");
    await page.locator('[name="rapor-bas"]').fill("2026-04-01");
    await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
    await page.getByTestId("raporlar-submit-run").click();

    const resultCard = page.getByTestId("raporlar-resmi-sonuc");
    await expect(resultCard).toBeVisible();
    await expect(resultCard.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody")).toContainText("2026-04-12");
    await expect(resultCard.locator("tbody")).toContainText("2026-04-14");
    await expect(resultCard.locator("tbody")).toContainText("Hafif yaralanma");
    await expect(resultCard.locator("tbody")).toContainText("Aktif");
    await expect(resultCard).not.toContainText("UNSUPPORTED_REPORT");
    expect(runtimeErrors).toEqual([]);
  });

  test("bildirim raporunda ayni ay tarih araliginda snapshot kaynakli gunluk satirlari gosterir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await page.locator('[name="rapor-turu"]').selectOption("bildirim");
    await page.locator('[name="rapor-bas"]').fill("2026-04-01");
    await page.locator('[name="rapor-bitis"]').fill("2026-04-30");
    await page.getByTestId("raporlar-submit-run").click();

    const resultCard = page.getByTestId("raporlar-resmi-sonuc");
    await expect(resultCard).toBeVisible();
    await expect(resultCard.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody")).toContainText("2026-04-11");
    await expect(resultCard.locator("tbody")).toContainText("İzinsiz Gelmedi");
    await expect(resultCard.locator("tbody")).toContainText("Habersiz devamsizlik");
    await expect(resultCard.locator("tbody")).toContainText("Mühürlendi");
    await expect(resultCard).not.toContainText("UNSUPPORTED_REPORT");
    expect(runtimeErrors).toEqual([]);
  });

  test("personel ozet raporunda departman filtresi sonucu daraltir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await page.locator('[name="rapor-turu"]').selectOption("personel-ozet");
    await page.locator('[name="rapor-departman"]').fill("3");
    await page.getByTestId("raporlar-submit-run").click();

    const resultCard = page.getByTestId("raporlar-resmi-sonuc");
    await expect(resultCard).toBeVisible();
    await expect(resultCard.locator("tbody")).toContainText("Ayşe Yılmaz");
    await expect(resultCard.locator("tbody")).not.toContainText("Mehmet Kaya");
    await expect(resultCard.locator("tbody tr")).toHaveCount(1);

    const oncekiButton = page.getByRole("button", { name: "Onceki" });
    const sonrakiButton = page.getByRole("button", { name: "Sonraki" });
    const pageInfo = page.locator(".module-page-info");

    await expect(pageInfo).toContainText("Sayfa 1 / 1");
    await expect(oncekiButton).toBeDisabled();
    await expect(sonrakiButton).toBeDisabled();
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

test.describe("raporlar rol ve aylik filtre smoke", () => {
  for (const role of RAPOR_ROLE_CASES) {
    test(`raporlar section gorunurlugunu role gore kilitler - ${role}`, async ({ page }) => {
      const runtimeErrors: string[] = [];
      page.on("pageerror", (error) => {
        runtimeErrors.push(error.message);
      });

      await mockApi(page, role);
      await login(page, ROLE_LOGIN[role]);
      await page.goto("/raporlar");
      await expect(page).toHaveURL(/\/raporlar$/);
      await expect(page.locator(".modal-header h2").first()).toContainText("Raporlar");

      await expect(page.locator('[name="rapor-turu"]')).toBeVisible();
      await expect(page.getByTestId("raporlar-submit-run")).toBeVisible();

      const aylikSection = page.getByTestId("aylik-kapanis-ozeti-section");
      if (ROLE_AYLIK_SECTION_VISIBLE[role]) {
        await expect(aylikSection).toBeVisible();
      } else {
        await expect(aylikSection).toHaveCount(0);
      }

      expect(runtimeErrors).toEqual([]);
    });
  }

  test("genel yonetici aylik ozet sube filtresi csv export icerigini scopea gore kilitler", async ({
    page
  }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await mockApi(page, "GENEL_YONETICI");
    await login(page, ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const aylikSection = page.getByTestId("aylik-kapanis-ozeti-section");
    await expect(aylikSection).toBeVisible();

    const tableBody = aylikSection.locator(".raporlar-table tbody");
    const subeSelect = aylikSection.locator('[name="aylik-ozet-sube"]');
    const exportButton = aylikSection.getByRole("button", { name: "Excel'e Aktar" });

    async function exportCsvContent() {
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
      return csvContent;
    }

    const merkezOption = subeSelect.locator("option").filter({ hasText: "Merkez" }).first();
    const merkezValue = await merkezOption.getAttribute("value");
    expect(merkezValue).toBeTruthy();
    await subeSelect.selectOption(merkezValue!);
    await aylikSection.getByRole("button", { name: "Özeti Getir" }).click();

    await expect(tableBody.locator("tr")).toHaveCount(1);
    await expect(tableBody).toContainText("Ayşe Yılmaz");
    await expect(tableBody).not.toContainText("Mehmet Kaya");

    const merkezCsv = await exportCsvContent();
    expect(merkezCsv).toContain("Ayşe Yılmaz");
    expect(merkezCsv).not.toContain("Mehmet Kaya");

    const depolamaOption = subeSelect.locator("option").filter({ hasText: "Depolama" }).first();
    const depolamaValue = await depolamaOption.getAttribute("value");
    expect(depolamaValue).toBeTruthy();
    await subeSelect.selectOption(depolamaValue!);
    await aylikSection.getByRole("button", { name: "Özeti Getir" }).click();

    await expect(tableBody.locator("tr")).toHaveCount(1);
    await expect(tableBody).toContainText("Mehmet Kaya");
    await expect(tableBody).not.toContainText("Ayşe Yılmaz");

    const depolamaCsv = await exportCsvContent();
    expect(depolamaCsv).toContain("Mehmet Kaya");
    expect(depolamaCsv).not.toContain("Ayşe Yılmaz");

    expect(runtimeErrors).toEqual([]);
  });

  test("genel yonetici aylik ozet sube filtresi ile satirlari daraltir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await mockApi(page, "GENEL_YONETICI");
    await login(page, ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const aylikSection = page.getByTestId("aylik-kapanis-ozeti-section");
    await expect(aylikSection).toBeVisible();

    const tableBody = aylikSection.locator(".raporlar-table tbody");
    await expect(tableBody).toHaveCount(1);
    await expect(tableBody.locator("tr")).toHaveCount(2);
    await expect(tableBody).toContainText("Ayşe Yılmaz");
    await expect(tableBody).toContainText("Mehmet Kaya");

    const subeSelect = aylikSection.locator('[name="aylik-ozet-sube"]');
    const merkezOption = subeSelect.locator('option').filter({ hasText: "Merkez" }).first();
    const merkezValue = await merkezOption.getAttribute("value");
    expect(merkezValue).toBeTruthy();
    await subeSelect.selectOption(merkezValue!);
    await aylikSection.getByRole("button", { name: "Özeti Getir" }).click();

    await expect(tableBody.locator("tr")).toHaveCount(1);
    await expect(tableBody).toContainText("Ayşe Yılmaz");
    await expect(tableBody).not.toContainText("Mehmet Kaya");
    await expect(aylikSection.getByRole("button", { name: "Excel'e Aktar" })).toBeVisible();
    expect(runtimeErrors).toEqual([]);
  });

  test("genel yonetici aylik ozet sadece revizeli filtresi ile satirlari daraltir", async ({ page }) => {
    const runtimeErrors: string[] = [];
    page.on("pageerror", (error) => {
      runtimeErrors.push(error.message);
    });

    await mockApi(page, "GENEL_YONETICI");
    await login(page, ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/raporlar");
    await expect(page).toHaveURL(/\/raporlar$/);

    const aylikSection = page.getByTestId("aylik-kapanis-ozeti-section");
    await expect(aylikSection).toBeVisible();

    const tableBody = aylikSection.locator(".raporlar-table tbody");
    await expect(tableBody.locator("tr")).toHaveCount(2);
    await expect(tableBody).toContainText("Ayşe Yılmaz");
    await expect(tableBody).toContainText("Mehmet Kaya");

    const revizeliCheckbox = aylikSection.getByLabel("Sadece revizeli kayıtlar");
    await expect(revizeliCheckbox).toBeVisible();
    await revizeliCheckbox.check();
    await aylikSection.getByRole("button", { name: "Özeti Getir" }).click();

    await expect(tableBody.locator("tr")).toHaveCount(1);
    await expect(tableBody).toContainText("Mehmet Kaya");
    await expect(tableBody).not.toContainText("Ayşe Yılmaz");
    await expect(aylikSection.getByRole("button", { name: "Excel'e Aktar" })).toBeVisible();
    expect(runtimeErrors).toEqual([]);
  });

  for (const role of AYLIK_ONAY_ROLE_CASES) {
    test(`aylik ozet onay aksiyonlarini role gore gosterir - ${role}`, async ({ page }) => {
      const runtimeErrors: string[] = [];
      page.on("pageerror", (error) => {
        runtimeErrors.push(error.message);
      });

      await mockApi(page, role);
      await login(page, ROLE_LOGIN[role]);
      await page.goto("/raporlar");
      await expect(page).toHaveURL(/\/raporlar$/);

      const aylikSection = page.getByTestId("aylik-kapanis-ozeti-section");
      await expect(aylikSection).toBeVisible();

      const bolumOnayButton = aylikSection.getByTestId("aylik-ozet-bolum-onay");
      const ustOnayButton = aylikSection.getByTestId("aylik-ozet-ust-onay");
      const expected = ROLE_AYLIK_ONAY_VISIBILITY[role];

      if (expected.bolumOnay) {
        await expect(bolumOnayButton).toBeVisible();
      } else {
        await expect(bolumOnayButton).toHaveCount(0);
      }

      if (expected.ustOnay) {
        await expect(ustOnayButton).toBeVisible();
      } else {
        await expect(ustOnayButton).toHaveCount(0);
      }

      expect(runtimeErrors).toEqual([]);
    });
  }
});
