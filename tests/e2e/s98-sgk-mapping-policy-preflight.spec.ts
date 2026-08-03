import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

async function openSgkKatalogTab(page: import("@playwright/test").Page) {
  await openRaporlarPanel(page, "MUHASEBE", "bordro-hazirlik");
  await page.getByTestId("bordro-hazirlik-tab-sgk-katalog").click();
  await expect(page.getByTestId("sgk-katalog-hazirlik-panel")).toBeVisible();
}

test.describe("S98 SGK mapping + policy preflight", () => {
  test.setTimeout(90_000);

  test("mapping template download + dry-run visible, write buttons gated", async ({ page }) => {
    await loginAsMockRole(page, "MUHASEBE");
    await openSgkKatalogTab(page);
    await page.getByTestId("sgk-katalog-subtab-esleme").click();

    const downloadPromise = page.waitForEvent("download");
    await page.getByTestId("sgk-esleme-sablon-download").click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toContain("sgk-surec-esleme-sablon");

    await page.getByTestId("sgk-esleme-dry-run").click();
    await expect(page.getByTestId("sgk-esleme-dry-run-result")).toBeVisible();
    await expect(page.getByTestId("sgk-esleme-hatali-summary")).toBeVisible();

    await expect(page.getByTestId("sgk-esleme-draft")).toBeDisabled();
    await expect(page.getByTestId("sgk-esleme-submit")).toBeDisabled();
    await expect(page.getByTestId("sgk-esleme-approve")).toBeDisabled();
  });

  test("mapping draft+submit flow returns ONAY_BEKLIYOR", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "bordro-hazirlik", undefined, {
      sgkMappingPolicyFlow: "writable"
    });
    await page.getByTestId("bordro-hazirlik-tab-sgk-katalog").click();
    await page.getByTestId("sgk-katalog-subtab-esleme").click();

    await page.getByTestId("sgk-esleme-package-input").fill(
      JSON.stringify(
        {
          parent_surum_kodu: "DEMO-KATALOG-2026",
          successor_surum_kodu: "E2E-ESLEME-SUCCESSOR",
          rows: [{ surec_turu: "RAPOR", alt_tur: "Raporlu_Hastalik", eksik_gun_kodu: "01" }]
        },
        null,
        2
      )
    );

    await page.getByTestId("sgk-esleme-dry-run").click();
    await expect(page.getByTestId("sgk-esleme-dry-run-result")).toContainText("apply_yapilabilir_mi: true");
    await expect(page.getByTestId("sgk-esleme-draft")).toBeEnabled();

    await page.getByTestId("sgk-esleme-draft").click();
    await page.getByTestId("sgk-esleme-draft-confirm-field").locator("textarea").fill("SUREC_ESLEME_DRAFT_ONAY");
    await page.getByTestId("sgk-esleme-draft-dialog-confirm").click();
    await expect(page.getByTestId("sgk-esleme-successor-state")).toContainText("TASLAK");

    await page.getByTestId("sgk-esleme-submit").click();
    await page.getByTestId("sgk-esleme-submit-dialog-confirm").click();
    await expect(page.getByTestId("sgk-esleme-successor-state")).toContainText("ONAY_BEKLIYOR");
    await expect(page.getByTestId("sgk-esleme-action-result")).toContainText("ONAY_BEKLIYOR");
  });

  test("dual-control messaging visible on mapping tab", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await openRaporlarPanel(page, "GENEL_YONETICI", "bordro-hazirlik");
    await page.getByTestId("bordro-hazirlik-tab-sgk-katalog").click();
    await page.getByTestId("sgk-katalog-subtab-esleme").click();

    await expect(page.getByTestId("sgk-esleme-dual-control-note")).toContainText("hazırlayan farklı olmalı");
    await expect(page.getByTestId("sgk-esleme-immutable-note")).toContainText("değiştirilemez");
  });

  test("policy tab template + dry-run + draft flow", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "bordro-hazirlik", undefined, {
      sgkMappingPolicyFlow: "writable"
    });
    await page.getByTestId("bordro-hazirlik-tab-sgk-katalog").click();
    await page.getByTestId("sgk-katalog-subtab-politika").click();

    await expect(page.getByTestId("sgk-politika-scope-note")).toContainText("otomatik yazma yapılmaz");

    const downloadPromise = page.waitForEvent("download");
    await page.getByTestId("sgk-politika-sablon-download").click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toContain("sgk-sirket-politikasi-sablon");

    await page.getByTestId("sgk-politika-package-input").fill(
      JSON.stringify(
        {
          sube_id: 1,
          surum_kodu: "E2E-SGK-POLITIKA",
          gecerlilik_baslangic: "2026-01-01",
          gecerlilik_bitis: null,
          bildirim_donem_tipi: "AY_15_SONRAKI_AY_14",
          degerler: []
        },
        null,
        2
      )
    );

    await page.getByTestId("sgk-politika-dry-run").click();
    await expect(page.getByTestId("sgk-politika-dry-run-result")).toContainText("import_yapilabilir_mi: true");

    await page.getByTestId("sgk-politika-draft").click();
    await page.getByTestId("sgk-politika-draft-confirm-field").locator("textarea").fill("SGK_POLITIKA_DRAFT_ONAY");
    await page.getByTestId("sgk-politika-draft-dialog-confirm").click();
    await expect(page.getByTestId("sgk-politika-surum-state")).toContainText("TASLAK");
  });

  test("preflight transition messaging when mapping empty", async ({ page }) => {
    await loginAsMockRole(page, "MUHASEBE");
    await openSgkKatalogTab(page);
    await page.getByTestId("sgk-katalog-subtab-esleme").click();

    await expect(page.getByTestId("sgk-esleme-preflight-note")).toContainText("Surec→SGK kod eslemesi bulunamadi");
    await page.getByTestId("sgk-esleme-dry-run").click();
    await expect(page.getByTestId("sgk-esleme-dry-run-result")).toContainText("apply_yapilabilir_mi: false");
  });

  test("MUHASEBE views dry-run; GENEL_YONETICI sees write buttons when ready", async ({ page }) => {
    await loginAsMockRole(page, "MUHASEBE");
    await openSgkKatalogTab(page);
    await page.getByTestId("sgk-katalog-subtab-esleme").click();
    await page.getByTestId("sgk-esleme-dry-run").click();
    await expect(page.getByTestId("sgk-esleme-dry-run-result")).toBeVisible();
    await expect(page.getByTestId("sgk-esleme-draft")).toBeDisabled();

    await page.goto("/");
    await openRaporlarPanel(page, "GENEL_YONETICI", "bordro-hazirlik", undefined, {
      sgkMappingPolicyFlow: "writable"
    });
    await page.getByTestId("bordro-hazirlik-tab-sgk-katalog").click();
    await page.getByTestId("sgk-katalog-subtab-esleme").click();
    await page.getByTestId("sgk-esleme-package-input").fill(
      JSON.stringify(
        {
          parent_surum_kodu: "DEMO-KATALOG-2026",
          successor_surum_kodu: "E2E-ESLEME-SUCCESSOR",
          rows: [{ surec_turu: "RAPOR", alt_tur: "Raporlu_Hastalik", eksik_gun_kodu: "01" }]
        },
        null,
        2
      )
    );
    await page.getByTestId("sgk-esleme-dry-run").click();
    await expect(page.getByTestId("sgk-esleme-draft")).toBeEnabled();
  });
});
