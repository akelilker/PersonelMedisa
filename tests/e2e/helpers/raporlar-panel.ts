import { expect, type Page } from "@playwright/test";
import { loginAsMockRole, waitForAuthSession, type MockRoleCredentials } from "./auth";
import type { MockUserRole } from "./mock-api";

export type RaporlarPanel = "donem-kapanis" | "etki-adayi";

const PANEL_TEST_ID: Record<RaporlarPanel, string> = {
  "donem-kapanis": "donem-kapanis-merkezi",
  "etki-adayi": "etki-adayi-rapor-page"
};

const PANEL_FILTER_TEST_ID: Record<RaporlarPanel, string> = {
  "donem-kapanis": "donem-kapanis-filters",
  "etki-adayi": "etki-adayi-rapor-filters"
};

export async function openRaporlarPanel(
  page: Page,
  role: MockUserRole,
  panel: RaporlarPanel,
  credentials?: MockRoleCredentials
) {
  await loginAsMockRole(page, role, credentials);
  await page.goto(`/raporlar?panel=${panel}`);
  await expect(page).toHaveURL(new RegExp(`/raporlar\\?panel=${panel}`));
  await expect(page.getByTestId("raporlar-panel-nav")).toBeVisible();
  await expect(page.getByTestId(PANEL_TEST_ID[panel])).toBeVisible({ timeout: 15_000 });
  await expect(page.getByTestId(PANEL_FILTER_TEST_ID[panel])).toBeVisible();
}

export async function expectRaporlarScopeFromSession(page: Page, expectedSubeIds: number[]) {
  const session = await page.evaluate(() => {
    const key = "medisa_auth_session";
    const raw = sessionStorage.getItem(key) ?? localStorage.getItem(key);
    if (!raw) {
      return null;
    }
    return JSON.parse(raw) as {
      user?: { rol?: string; sube_ids?: number[] };
      active_sube_id?: number | null;
      sube_list?: Array<{ id: number; ad: string }>;
    };
  });

  expect(session).not.toBeNull();
  expect(session?.user?.sube_ids ?? []).toEqual(expectedSubeIds);
  if (expectedSubeIds.length === 1) {
    expect(session?.active_sube_id).toBe(expectedSubeIds[0]);
  }
  const listedIds = (session?.sube_list ?? []).map((item) => item.id);
  for (const subeId of expectedSubeIds) {
    expect(listedIds).toContain(subeId);
  }
}

export async function expectSubeSelectScoped(page: Page, allowedSubeIds: number[]) {
  const subeSelect = page.getByLabel("Şube");
  await expect(subeSelect).toBeVisible();
  const optionValues = await subeSelect.locator("option").evaluateAll((options) =>
    options
      .map((option) => option.getAttribute("value") ?? "")
      .filter((value) => value.trim() !== "")
      .map((value) => Number.parseInt(value, 10))
      .filter((value) => Number.isFinite(value))
  );
  expect(optionValues.sort((a, b) => a - b)).toEqual([...allowedSubeIds].sort((a, b) => a - b));
}

export async function waitForPreflightRequest(page: Page, subeId: number) {
  const response = await page.waitForResponse(
    (request) =>
      request.url().includes("/api/puantaj/donem-kapanis-preflight") &&
      request.url().includes(`sube_id=${subeId}`),
    { timeout: 15_000 }
  );
  expect(response.status()).toBe(200);
}

export async function reloadRaporlarPanel(page: Page, panel: RaporlarPanel) {
  await page.goto(`/raporlar?panel=${panel}`);
  await waitForAuthSession(page);
  await expect(page.getByTestId(PANEL_TEST_ID[panel])).toBeVisible({ timeout: 15_000 });
}
