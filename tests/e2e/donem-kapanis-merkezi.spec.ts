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
  await page.goto("/raporlar?panel=donem-kapanis", { waitUntil: "domcontentloaded" });
}

async function submitDonemKapanisFilters(page: Page, subeId = 1) {
  await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
  const subeSelect = page.getByLabel("Şube");
  await expect(subeSelect.locator(`option[value="${subeId}"]`)).toHaveCount(1, { timeout: 15_000 });
  await subeSelect.selectOption(String(subeId));
  const preflight = page.waitForResponse((response) =>
    response.url().includes("/api/puantaj/donem-kapanis-preflight")
  );
  await page.getByTestId("donem-kapanis-submit").click();
  const response = await preflight;
  expect(response.status()).toBe(200);
  expect(response.url()).toContain(`sube_id=${subeId}`);
}

test.describe("S76 donem kapanis merkezi", () => {
  test("MUHASEBE sees blockers and salary warning context", async ({ page }) => {
    await openRaporlarPanel(page, "MUHASEBE", "donem-kapanis");
    await setActiveSube(page, 1);
    await submitDonemKapanisFilters(page);
    await expect(page.getByTestId("donem-kapanis-issue-CANDIDATE_HAZIR_PENDING")).toBeVisible();
    await expect(page.getByTestId("donem-kapanis-severity-CANDIDATE_HAZIR_PENDING")).toContainText("Engelleyici");
    await expect(page.getByTestId("donem-kapanis-muhurle")).toHaveCount(0);
  });

  test("GENEL_YONETICI sees seal action and blocked close feedback", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "donem-kapanis");
    await setActiveSube(page, 1);
    await submitDonemKapanisFilters(page);
    await expect(page.getByTestId("donem-kapanis-muhurle")).toBeVisible();
    await expect(page.getByTestId("donem-kapanis-muhurle")).toBeDisabled();
    await expect(page.getByTestId("donem-kapanis-blockers")).toBeVisible();
  });

  test("BIRIM_AMIRI can view own-scope preflight without export", async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on("console", (message) => {
      if (message.type() === "error") {
        consoleErrors.push(message.text());
      }
    });

    await openRaporlarPanel(page, "BIRIM_AMIRI", "donem-kapanis");
    await expectRaporlarScopeFromSession(page, [1]);
    await expectSubeSelectScoped(page, [1]);
    await expect(page.getByTestId("donem-kapanis-export-csv")).toHaveCount(0);
    await expect(page.getByTestId("donem-kapanis-muhurle")).toHaveCount(0);
    await expect(page.getByTestId("donem-kapanis-durum-bandi")).toBeVisible();

    await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
    const preflight = page.waitForResponse((response) =>
      response.url().includes("/api/puantaj/donem-kapanis-preflight")
    );
    await page.getByTestId("donem-kapanis-submit").click();
    const response = await preflight;
    expect(response.status()).toBe(200);
    expect(response.url()).toContain("sube_id=1");
    await expect(page.getByTestId("donem-kapanis-durum-bandi")).toBeVisible();

    await reloadRaporlarPanel(page, "donem-kapanis");
    await expectSubeSelectScoped(page, [1]);
    expect(consoleErrors.filter((line) => !ignoreBenignConsoleError(line))).toEqual([]);
  });

  test("BIRIM_AMIRI keeps panel usable when yonetim subeler returns 403", async ({ page }) => {
    const subeResponse = page.waitForResponse((response) => response.url().includes("/api/yonetim/subeler"));
    await openRaporlarPanel(page, "BIRIM_AMIRI", "donem-kapanis");
    expect((await subeResponse).status()).toBe(403);
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
    await expect(page.getByTestId("donem-kapanis-filters")).toBeVisible();
    await expectSubeSelectScoped(page, [1]);
  });

  test("issue detail modal opens from blocker list", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "donem-kapanis");
    await setActiveSube(page, 1);
    await submitDonemKapanisFilters(page);
    await page.getByTestId("donem-kapanis-issue-detail-CANDIDATE_HAZIR_PENDING").click();
    await expect(page.getByTestId("donem-kapanis-personel-detay-modal")).toBeVisible();
  });

  test("desktop layout keeps issue list visible", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await openRaporlarPanel(page, "GENEL_YONETICI", "donem-kapanis");
    await setActiveSube(page, 1);
    await submitDonemKapanisFilters(page);
    await expect(page.getByTestId("donem-kapanis-issue-listesi")).toBeVisible();
  });

  test("mobile layout keeps preflight summary readable", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openRaporlarPanel(page, "GENEL_YONETICI", "donem-kapanis");
    await setActiveSube(page, 1);
    await submitDonemKapanisFilters(page);
    await expect(page.getByTestId("donem-kapanis-durum-bandi")).toBeVisible();
    await expect(page.getByTestId("donem-kapanis-issue-CANDIDATE_HAZIR_PENDING")).toBeVisible();
  });
});
