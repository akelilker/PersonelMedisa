import { expect, test, type Page } from "@playwright/test";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

const PANEL_AY = "2026-03";

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
  await page.goto("/raporlar?panel=maas-hesaplama", { waitUntil: "domcontentloaded" });
}

async function submitMaasFilters(page: Page, ay = PANEL_AY, subeId = 1) {
  await page.getByLabel("Ay", { exact: true }).first().fill(ay);
  const subeSelect = page.getByLabel("Şube");
  await expect(subeSelect.locator(`option[value="${subeId}"]`)).toHaveCount(1, { timeout: 15_000 });
  await subeSelect.selectOption(String(subeId));
  const preflight = page.waitForResponse((response) =>
    response.url().includes("/api/maas-hesaplama/preflight")
  );
  await page.getByTestId("maas-hesaplama-submit").click();
  const response = await preflight;
  expect(response.status()).toBe(200);
}

test.describe("S77-C maas hesaplama merkezi", () => {
  test("MUHASEBE opens center, sees sealed preflight and creates snapshot", async ({ page }) => {
    page.on("dialog", (dialog) => void dialog.accept());
    await openRaporlarPanel(page, "MUHASEBE", "maas-hesaplama");
    await setActiveSube(page, 1);
    await submitMaasFilters(page);

    await expect(page.getByTestId("maas-hesaplama-merkezi")).toBeVisible();
    await expect(page.getByTestId("maas-hesaplama-issue-LEGAL_PARAMETER_SET_EMPTY")).toBeVisible();
    await expect(page.getByTestId("maas-hesaplama-personel-7")).toBeVisible();
    await expect(page.getByTestId("maas-hesaplama-create")).toBeEnabled();

    const create = page.waitForResponse(
      (response) =>
        response.url().includes("/api/maas-hesaplama/snapshotlar") && response.request().method() === "POST"
    );
    await page.getByTestId("maas-hesaplama-create").click();
    const createResponse = await create;
    expect([200, 201]).toContain(createResponse.status());
    await expect(page.getByTestId("maas-hesaplama-action-success")).toBeVisible();
    await expect(page.getByTestId(/^maas-hesaplama-snapshot-\d+$/).first()).toBeVisible();
    await page.getByTestId(/^maas-hesaplama-snapshot-\d+$/).first().click();
    await expect(page.getByTestId("maas-hesaplama-snapshot-detail")).toBeVisible();
    await expect(page.getByTestId("maas-hesaplama-hash-dogrulama")).toContainText("OK");

    const idempotent = page.waitForResponse(
      (response) =>
        response.url().includes("/api/maas-hesaplama/snapshotlar") && response.request().method() === "POST"
    );
    await page.getByTestId("maas-hesaplama-create").click();
    const idempotentResponse = await idempotent;
    expect(idempotentResponse.status()).toBe(200);
  });

  test("unsealed period shows PERIOD_NOT_SEALED and disables create", async ({ page }) => {
    await openRaporlarPanel(page, "MUHASEBE", "maas-hesaplama");
    await setActiveSube(page, 1);
    await submitMaasFilters(page, "2026-06", 1);
    await expect(page.getByTestId("maas-hesaplama-issue-PERIOD_NOT_SEALED")).toBeVisible();
    await expect(page.getByTestId("maas-hesaplama-create")).toBeDisabled();
  });

  test("BIRIM_AMIRI does not see panel and does not fetch", async ({ page }) => {
    const requests: string[] = [];
    page.on("request", (request) => {
      if (request.url().includes("/api/maas-hesaplama/")) {
        requests.push(request.url());
      }
    });
    await openRaporlarPanel(page, "BIRIM_AMIRI", "standart");
    await expect(page.getByTestId("raporlar-panel-maas-hesaplama")).toHaveCount(0);
    expect(requests).toEqual([]);
  });

  test("cancel and revision flow", async ({ page }) => {
    page.on("dialog", (dialog) => void dialog.accept());
    await openRaporlarPanel(page, "MUHASEBE", "maas-hesaplama");
    await setActiveSube(page, 1);
    await submitMaasFilters(page);
    await page.getByTestId("maas-hesaplama-create").click();
    await expect(page.getByTestId("maas-hesaplama-action-success")).toBeVisible();

    await page.getByLabel("İptal nedeni").fill("E2E revision icin iptal");
    await page.getByTestId("maas-hesaplama-cancel").click();
    await expect(page.getByTestId("maas-hesaplama-action-success")).toContainText("iptal");

    await page.getByTestId("maas-hesaplama-create").click();
    await expect(page.getByTestId("maas-hesaplama-action-success")).toContainText("Snapshot oluşturuldu");
  });
});
