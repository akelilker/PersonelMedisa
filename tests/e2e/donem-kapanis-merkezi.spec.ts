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

async function submitDonemKapanisFilters(page: Page, subeId = 1) {
  await page.getByLabel("Ay", { exact: true }).first().fill(PANEL_AY);
  const subeSelect = page.getByLabel("Şube");
  await expect(subeSelect.locator(`option[value="${subeId}"]`)).toHaveCount(1, { timeout: 15_000 });
  await subeSelect.selectOption(String(subeId));
  await page.getByTestId("donem-kapanis-submit").click();
}

async function openDonemKapanisPanel(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, ROLE_LOGIN[role]);
  await page.goto("/raporlar?panel=donem-kapanis");
  await expect(page.getByTestId("donem-kapanis-merkezi")).toBeVisible({ timeout: 15_000 });
}

test.describe.configure({ retries: 1 });

test.describe("S76 donem kapanis merkezi", () => {
  test("MUHASEBE sees blockers and salary warning context", async ({ page }) => {
    await openDonemKapanisPanel(page, "MUHASEBE");
    await setActiveSube(page, 1, "donem-kapanis");
    await submitDonemKapanisFilters(page);
    await expect(page.getByTestId("donem-kapanis-issue-CANDIDATE_HAZIR_PENDING")).toBeVisible();
    await expect(page.getByTestId("donem-kapanis-severity-CANDIDATE_HAZIR_PENDING")).toContainText("Engelleyici");
    await expect(page.getByTestId("donem-kapanis-muhurle")).toHaveCount(0);
  });

  test("GENEL_YONETICI sees seal action and blocked close feedback", async ({ page }) => {
    await openDonemKapanisPanel(page, "GENEL_YONETICI");
    await setActiveSube(page, 1, "donem-kapanis");
    await submitDonemKapanisFilters(page);
    await expect(page.getByTestId("donem-kapanis-muhurle")).toBeVisible();
    await expect(page.getByTestId("donem-kapanis-muhurle")).toBeDisabled();
    await expect(page.getByTestId("donem-kapanis-blockers")).toBeVisible();
  });

  test("BIRIM_AMIRI can view own-scope preflight without export", async ({ page }) => {
    await openDonemKapanisPanel(page, "BIRIM_AMIRI");
    await expect(page.getByTestId("donem-kapanis-filters")).toBeVisible();
    await expect(page.getByTestId("donem-kapanis-export-csv")).toHaveCount(0);
    await expect(page.getByTestId("donem-kapanis-muhurle")).toHaveCount(0);
  });

  test("issue detail modal opens from blocker list", async ({ page }) => {
    await openDonemKapanisPanel(page, "GENEL_YONETICI");
    await setActiveSube(page, 1, "donem-kapanis");
    await submitDonemKapanisFilters(page);
    await page.getByTestId("donem-kapanis-issue-detail-CANDIDATE_HAZIR_PENDING").click();
    await expect(page.getByTestId("donem-kapanis-personel-detay-modal")).toBeVisible();
  });

  test("desktop layout keeps issue list visible", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await openDonemKapanisPanel(page, "GENEL_YONETICI");
    await setActiveSube(page, 1, "donem-kapanis");
    await submitDonemKapanisFilters(page);
    await expect(page.getByTestId("donem-kapanis-issue-listesi")).toBeVisible();
  });

  test("mobile layout keeps preflight summary readable", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openDonemKapanisPanel(page, "GENEL_YONETICI");
    await setActiveSube(page, 1, "donem-kapanis");
    await submitDonemKapanisFilters(page);
    await expect(page.getByTestId("donem-kapanis-durum-bandi")).toBeVisible();
    await expect(page.getByTestId("donem-kapanis-issue-CANDIDATE_HAZIR_PENDING")).toBeVisible();
  });
});
