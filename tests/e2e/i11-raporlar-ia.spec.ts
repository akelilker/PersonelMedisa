import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

test.describe("I11 raporlar information architecture", () => {
  test("Scenario A — default /raporlar is Liste Raporları with grouped nav", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "standart");
    await expect(page.getByTestId("raporlar-nav-group-raporlar")).toBeVisible();
    await expect(page.getByTestId("raporlar-nav-group-kapanis")).toBeVisible();
    await expect(page.getByTestId("raporlar-nav-group-bordro")).toBeVisible();
    await expect(page.getByTestId("raporlar-panel-liste")).toHaveAttribute("aria-current", "page");
    await expect(page.getByTestId("raporlar-liste-panel")).toBeVisible();
    await expect(page.getByTestId("aylik-kapanis-ozeti-section")).toHaveCount(0);
    await expect(page.getByText("Liste ve aylık özet")).toHaveCount(0);
    await expect(page.locator('[name="rapor-turu"]')).toBeVisible();
    await expect(page.getByTestId("raporlar-submit-run")).toBeVisible();
  });

  test("Scenario B — grouped navigation lands on panel owners", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "standart");

    await page.getByTestId("raporlar-panel-etki-adayi").click();
    await expect(page).toHaveURL(/panel=etki-adayi/);
    await expect(page.getByTestId("etki-adayi-rapor-page")).toBeVisible();

    await page.getByTestId("raporlar-panel-donem-kapanis").click();
    await expect(page).toHaveURL(/panel=donem-kapanis/);
    await expect(page.getByTestId("donem-kapanis-merkezi")).toBeVisible();

    await page.getByTestId("raporlar-panel-maas-hesaplama").click();
    await expect(page).toHaveURL(/panel=maas-hesaplama/);
    await expect(page.getByTestId("maas-hesaplama-merkezi")).toBeVisible();

    await page.getByTestId("raporlar-panel-bordro-hazirlik").click();
    await expect(page).toHaveURL(/panel=bordro-hazirlik/);
    await expect(page.getByTestId("bordro-hazirlik-merkezi")).toBeVisible();
  });

  test("Scenario C — browser history restores surfaces", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "standart");
    await page.getByTestId("raporlar-panel-bordro-hazirlik").click();
    await expect(page.getByTestId("bordro-hazirlik-merkezi")).toBeVisible();
    await page.getByTestId("raporlar-panel-maas-hesaplama").click();
    await expect(page.getByTestId("maas-hesaplama-merkezi")).toBeVisible();

    await page.goBack();
    await expect(page).toHaveURL(/panel=bordro-hazirlik/);
    await expect(page.getByTestId("bordro-hazirlik-merkezi")).toBeVisible();

    await page.goForward();
    await expect(page).toHaveURL(/panel=maas-hesaplama/);
    await expect(page.getByTestId("maas-hesaplama-merkezi")).toBeVisible();
  });

  test("Scenario D — Bordro Kapsam deep link preserves tab and person", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/raporlar?panel=bordro-hazirlik&tab=personel-kapsam&personelId=1");
    await expect(page).toHaveURL(/panel=bordro-hazirlik/);
    await expect(page.getByTestId("bordro-hazirlik-merkezi")).toBeVisible();
    await expect(page.getByTestId("bordro-hazirlik-tab-personel-kapsam")).toHaveClass(/is-active/);
    await expect(page.getByLabel("Personel ID")).toHaveValue("1");
  });

  test("Scenario E — role without payroll permissions hides Bordro group", async ({ page }) => {
    const payrollRequests: string[] = [];
    page.on("request", (request) => {
      const url = request.url();
      if (url.includes("/api/maas-hesaplama/") || url.includes("/api/bordro-hazirlik/")) {
        payrollRequests.push(url);
      }
    });

    await openRaporlarPanel(page, "BIRIM_AMIRI", "standart");
    await expect(page.getByTestId("raporlar-nav-group-bordro")).toHaveCount(0);
    await expect(page.getByTestId("raporlar-panel-maas-hesaplama")).toHaveCount(0);
    await expect(page.getByTestId("raporlar-panel-bordro-hazirlik")).toHaveCount(0);
    expect(payrollRequests).toEqual([]);
  });

  test("Scenario F — Aylık Kapanış Özeti URL state", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/raporlar?view=aylik-kapanis");
    await expect(page.getByTestId("raporlar-panel-aylik-kapanis")).toHaveAttribute("aria-current", "page");
    await expect(page.getByTestId("aylik-kapanis-ozeti-section")).toBeVisible();
    await expect(page.getByTestId("raporlar-liste-panel")).toHaveCount(0);
    await expect(page.locator('[name="aylik-ozet-ay"]')).toBeVisible();
    await expect(page.getByTestId("aylik-ozet-ust-onay")).toBeVisible();
  });

  test("Scenario G — grouped nav has no horizontal page overflow on mobile", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openRaporlarPanel(page, "GENEL_YONETICI", "standart");
    const overflow = await page.evaluate(() => {
      const doc = document.documentElement;
      return {
        scrollWidth: doc.scrollWidth,
        clientWidth: doc.clientWidth
      };
    });
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1);
    await expect(page.getByTestId("raporlar-panel-nav")).toBeVisible();
  });

  test("invalid panel falls back to Liste Raporları", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/raporlar?panel=garbage");
    await expect(page.getByTestId("raporlar-panel-liste")).toHaveAttribute("aria-current", "page");
    await expect(page.getByTestId("raporlar-liste-panel")).toBeVisible();
  });
});
