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
});
