import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

test.describe("S85-C1 SGK Katalog Hazirlik", () => {
  test.setTimeout(90_000);

  test.beforeEach(async ({ page }) => {
    await mockApi(page);
  });

  test("shows empty catalog, tamlik blocker, disabled approve", async ({ page }) => {
    await loginAsMockRole(page, "MUHASEBE");
    await openRaporlarPanel(page, "MUHASEBE", "bordro-hazirlik");
    await page.getByTestId("bordro-hazirlik-tab-sgk-katalog").click();
    await expect(page.getByTestId("sgk-katalog-hazirlik-panel")).toBeVisible();
    await expect(page.getByTestId("sgk-katalog-kaynak-tamlik-uyari")).toContainText("tamamlanmadı");
    await expect(page.getByTestId("sgk-katalog-blocker-SGK_KATALOG_TAMLIK_KANITI_EKSIK")).toBeVisible();
    await page.getByTestId("sgk-katalog-subtab-kaynaklar").click();
    await expect(page.getByTestId("sgk-katalog-kaynak-empty")).toBeVisible();
    await page.getByTestId("sgk-katalog-subtab-import").click();
    await page.getByTestId("sgk-katalog-import-dry-run").click();
    await expect(page.getByTestId("sgk-katalog-import-result")).toBeVisible();
    await expect(page.getByTestId("sgk-katalog-import-write")).toBeDisabled();
    await page.getByTestId("sgk-katalog-subtab-onay").click();
    await expect(page.getByTestId("sgk-katalog-approve")).toBeDisabled();
    await page.getByTestId("sgk-katalog-subtab-operasyonel").click();
    await expect(page.getByTestId("sgk-katalog-operasyonel-ayrim")).toContainText("Mevzuat kaynağı değildir");
  });
});
