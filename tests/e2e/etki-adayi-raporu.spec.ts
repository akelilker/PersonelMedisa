import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const ROLE_LOGIN: Record<MockUserRole, { username: string; password: string }> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yoneticisi", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

const PANEL_AY = "2026-06";

async function setActiveSube(page: Page, subeId: number, panel: "donem-kapanis" | "etki-adayi") {
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
  await page.goto(`/raporlar?panel=${panel}`, { waitUntil: "domcontentloaded" });
}

async function submitEtkiRaporFilters(page: Page, subeId = 1) {
  await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
  const subeSelect = page.getByLabel("Şube");
  await expect(subeSelect.locator(`option[value="${subeId}"]`)).toHaveCount(1, { timeout: 15_000 });
  await subeSelect.selectOption(String(subeId));
  await page.getByTestId("etki-adayi-rapor-submit").click();
}

async function openEtkiRaporPanel(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, ROLE_LOGIN[role]);
  await page.goto("/raporlar?panel=etki-adayi");
  await expect(page.getByTestId("etki-adayi-rapor-page")).toBeVisible({ timeout: 15_000 });
}

test.describe.configure({ retries: 1 });

test.describe("S76 etki adayi raporu", () => {
  test("MUHASEBE loads report rows and summary", async ({ page }) => {
    await openEtkiRaporPanel(page, "MUHASEBE");
    await setActiveSube(page, 1, "etki-adayi");
    await submitEtkiRaporFilters(page);
    await expect(page.getByTestId("etki-adayi-rapor-table")).toBeVisible();
    await expect(page.getByTestId("etki-adayi-rapor-summary")).toBeVisible();
    await expect(page.getByTestId("etki-adayi-rapor-row-3")).toBeVisible();
  });

  test("GENEL_YONETICI can export CSV", async ({ page }) => {
    await openEtkiRaporPanel(page, "GENEL_YONETICI");
    await setActiveSube(page, 1, "etki-adayi");
    await submitEtkiRaporFilters(page);
    await expect(page.getByTestId("etki-adayi-rapor-export-csv")).toBeVisible();
  });

  test("BIRIM_AMIRI can view report without export", async ({ page }) => {
    await openEtkiRaporPanel(page, "BIRIM_AMIRI");
    await expect(page.getByTestId("etki-adayi-rapor-filters")).toBeVisible();
    await expect(page.getByTestId("etki-adayi-rapor-export-csv")).toHaveCount(0);
  });

  test("state filter submits scoped query", async ({ page }) => {
    const requests: string[] = [];
    page.on("request", (request) => {
      if (request.url().includes("/api/puantaj/bildirim-etki-adaylari/rapor")) {
        requests.push(request.url());
      }
    });

    await openEtkiRaporPanel(page, "MUHASEBE");
    await setActiveSube(page, 1, "etki-adayi");
    await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
    await page.getByLabel("Şube").selectOption("1");
    await page.getByLabel("Durum").selectOption("HAZIR");
    await page.getByTestId("etki-adayi-rapor-submit").click();
    await expect(page.getByTestId("etki-adayi-rapor-table")).toBeVisible();
    expect(requests.some((url) => url.includes("state=HAZIR"))).toBe(true);
  });

  test("desktop table remains usable", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await openEtkiRaporPanel(page, "MUHASEBE");
    await setActiveSube(page, 1, "etki-adayi");
    await submitEtkiRaporFilters(page);
    await expect(page.getByTestId("etki-adayi-rapor-table")).toBeVisible();
    await expect(page.getByTestId("etki-adayi-rapor-puantaj-link")).toBeVisible();
  });

  test("mobile layout keeps filters accessible", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openEtkiRaporPanel(page, "MUHASEBE");
    await setActiveSube(page, 1, "etki-adayi");
    await expect(page.getByTestId("etki-adayi-rapor-filters")).toBeVisible();
    await submitEtkiRaporFilters(page);
    await expect(page.getByTestId("etki-adayi-rapor-summary")).toBeVisible();
  });
});
