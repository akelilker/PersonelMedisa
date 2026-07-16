import { expect, test, type Page } from "@playwright/test";
import {
  expectRaporlarScopeFromSession,
  expectSubeSelectScoped,
  openRaporlarPanel,
  reloadRaporlarPanel
} from "./helpers/raporlar-panel";

const PANEL_AY = "2026-06";

function ignoreBenignConsoleError(line: string): boolean {
  return line.includes("favicon") || line.includes("403 (Forbidden)");
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
  await page.goto("/raporlar?panel=etki-adayi", { waitUntil: "domcontentloaded" });
}

async function submitEtkiRaporFilters(page: Page, subeId = 1) {
  await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
  const subeSelect = page.getByLabel("Şube");
  await expect(subeSelect.locator(`option[value="${subeId}"]`)).toHaveCount(1, { timeout: 15_000 });
  await subeSelect.selectOption(String(subeId));
  await page.getByTestId("etki-adayi-rapor-submit").click();
}

test.describe("S76 etki adayi raporu", () => {
  test("MUHASEBE loads report rows and summary", async ({ page }) => {
    await openRaporlarPanel(page, "MUHASEBE", "etki-adayi");
    await setActiveSube(page, 1);
    await submitEtkiRaporFilters(page);
    await expect(page.getByTestId("etki-adayi-rapor-table")).toBeVisible();
    await expect(page.getByTestId("etki-adayi-rapor-summary")).toBeVisible();
    await expect(page.getByTestId("etki-adayi-rapor-row-3")).toBeVisible();
  });

  test("GENEL_YONETICI can export CSV", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "etki-adayi");
    await setActiveSube(page, 1);
    await submitEtkiRaporFilters(page);
    await expect(page.getByTestId("etki-adayi-rapor-export-csv")).toBeVisible();
  });

  test("BIRIM_AMIRI can view report without export", async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on("console", (message) => {
      if (message.type() === "error") {
        consoleErrors.push(message.text());
      }
    });

    await openRaporlarPanel(page, "BIRIM_AMIRI", "etki-adayi");
    await expectRaporlarScopeFromSession(page, [1]);
    await expectSubeSelectScoped(page, [1]);
    await expect(page.getByTestId("etki-adayi-rapor-export-csv")).toHaveCount(0);

    await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
    const reportResponse = page.waitForResponse((response) =>
      response.url().includes("/api/puantaj/bildirim-etki-adaylari/rapor")
    );
    await page.getByTestId("etki-adayi-rapor-submit").click();
    const response = await reportResponse;
    expect(response.status()).toBe(200);
    expect(response.url()).toContain("sube_id=1");

    await reloadRaporlarPanel(page, "etki-adayi");
    await expectSubeSelectScoped(page, [1]);
    expect(consoleErrors.filter((line) => !ignoreBenignConsoleError(line))).toEqual([]);
  });

  test("BIRIM_AMIRI keeps report usable when yonetim subeler returns 403", async ({ page }) => {
    const subeResponse = page.waitForResponse((response) => response.url().includes("/api/yonetim/subeler"));
    await openRaporlarPanel(page, "BIRIM_AMIRI", "etki-adayi");
    expect((await subeResponse).status()).toBe(403);
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    await expect(page.getByTestId("etki-adayi-rapor-filters")).toBeVisible();
    await expectSubeSelectScoped(page, [1]);
  });

  test("state filter submits scoped query", async ({ page }) => {
    const requests: string[] = [];
    page.on("request", (request) => {
      if (request.url().includes("/api/puantaj/bildirim-etki-adaylari/rapor")) {
        requests.push(request.url());
      }
    });

    await openRaporlarPanel(page, "MUHASEBE", "etki-adayi");
    await setActiveSube(page, 1);
    await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
    await page.getByLabel("Şube").selectOption("1");
    await page.getByLabel("Durum").selectOption("HAZIR");
    await page.getByTestId("etki-adayi-rapor-submit").click();
    await expect(page.getByTestId("etki-adayi-rapor-table")).toBeVisible();
    expect(requests.some((url) => url.includes("state=HAZIR"))).toBe(true);
  });

  test("desktop table remains usable", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await openRaporlarPanel(page, "MUHASEBE", "etki-adayi");
    await setActiveSube(page, 1);
    await submitEtkiRaporFilters(page);
    await expect(page.getByTestId("etki-adayi-rapor-table")).toBeVisible();
    await expect(page.getByTestId("etki-adayi-rapor-puantaj-link")).toBeVisible();
  });

  test("mobile layout keeps filters accessible", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openRaporlarPanel(page, "MUHASEBE", "etki-adayi");
    await setActiveSube(page, 1);
    await expect(page.getByTestId("etki-adayi-rapor-filters")).toBeVisible();
    await submitEtkiRaporFilters(page);
    await expect(page.getByTestId("etki-adayi-rapor-summary")).toBeVisible();
  });
});
