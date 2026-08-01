import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

async function openSealPanel(page: Page, role: "MUHASEBE" | "GENEL_YONETICI") {
  await openRaporlarPanel(page, role, "donem-kapanis");
  await page.evaluate(() => {
    const key = "medisa_auth_session";
    const fromSession = sessionStorage.getItem(key);
    const storage = fromSession ? sessionStorage : localStorage;
    const raw = fromSession ?? localStorage.getItem(key);
    if (!raw) throw new Error("auth session missing");
    const session = JSON.parse(raw) as { active_sube_id?: number | null };
    session.active_sube_id = 1;
    storage.setItem(key, JSON.stringify(session));
  });
  await page.goto("/raporlar?panel=donem-kapanis", { waitUntil: "domcontentloaded" });
  await page.getByLabel("Ay", { exact: true }).first().fill("2026-04");
  await page.getByLabel("Şube").selectOption("1");
  await expect(page.getByTestId("donem-seal-reopen-panel")).toBeVisible({ timeout: 15_000 });
}

async function postTestHelper(page: Page, path: string) {
  const result = await page.evaluate(async (helperPath) => {
    const key = "medisa_auth_session";
    const raw = sessionStorage.getItem(key) ?? localStorage.getItem(key);
    const session = raw ? (JSON.parse(raw) as { token?: string }) : null;
    const response = await fetch(helperPath, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...(session?.token ? { Authorization: `Bearer ${session.token}` } : {})
      },
      body: "{}"
    });
    return { status: response.status, body: await response.json() };
  }, path);
  expect(result.status).toBe(200);
}

test.describe("S87 seal revision reopen dual-control", () => {
  test("MUHASEBE request → self-approve blocked → GY approve → snapshot/canonical gates → reseal history", async ({
    page
  }) => {
    await openSealPanel(page, "MUHASEBE");
    await expect(page.getByTestId("donem-seal-period-state")).toHaveText("SEALED");
    await expect(page.getByTestId("donem-seal-active-snapshot")).toContainText("iptal gerekli");

    await page.getByTestId("donem-reopen-request-btn").click();
    await page.locator('textarea[name="donem-seal-request-dialog-input"]').fill("Canonical duzeltme gerekli.");
    await page.getByTestId("donem-seal-request-dialog-confirm").click();
    await expect(page.getByTestId("donem-seal-period-state")).toHaveText("REOPEN_PENDING", { timeout: 10_000 });

    // MUHASEBE approve butonu gizli (self) — yoksa API self-approval 403
    await expect(page.getByTestId("donem-reopen-approve-btn")).toHaveCount(0);

    await loginAsMockRole(page, "GENEL_YONETICI");
    await openSealPanel(page, "GENEL_YONETICI");
    await expect(page.getByTestId("donem-seal-period-state")).toHaveText("REOPEN_PENDING");
    await page.getByTestId("donem-reopen-approve-btn").click();
    await page.getByTestId("donem-seal-approve-dialog-confirm").click();
    await expect(page.getByTestId("donem-seal-period-state")).toHaveText("REOPENED", { timeout: 10_000 });

    await loginAsMockRole(page, "MUHASEBE");
    await openSealPanel(page, "MUHASEBE");
    await expect(page.getByTestId("donem-reseal-btn")).toBeDisabled();

    await postTestHelper(page, "/api/test/seal-reopen/cancel-active-snapshot");
    await page.reload({ waitUntil: "domcontentloaded" });
    await openSealPanel(page, "MUHASEBE");
    await expect(page.getByTestId("donem-seal-active-snapshot")).toContainText("Yok");

    // Canonical eksikken reseal bloke
    await page.getByTestId("donem-reseal-btn").click();
    await page.locator('textarea[name="donem-seal-reseal-dialog-input"]').fill("Reseal denemesi");
    await page.getByTestId("donem-seal-reseal-dialog-confirm").click();
    await expect(page.getByText(/CANONICAL_CALENDAR_INCOMPLETE|Canonical eksik/i)).toBeVisible({
      timeout: 10_000
    });

    await postTestHelper(page, "/api/test/seal-reopen/complete-canonical");
    await page.reload({ waitUntil: "domcontentloaded" });
    await openSealPanel(page, "MUHASEBE");
    await page.getByTestId("donem-reseal-btn").click();
    await page.locator('textarea[name="donem-seal-reseal-dialog-input"]').fill("Canonical tamam; reseal");
    await page.getByTestId("donem-seal-reseal-dialog-confirm").click();
    await expect(page.getByTestId("donem-seal-period-state")).toHaveText("SEALED", { timeout: 10_000 });
    await expect(page.getByTestId("donem-seal-effective-rev")).toContainText("2");
    await expect(page.getByText(/r1:SUPERSEDED/i)).toBeVisible();
    await expect(page.getByText(/r2:MUHURLENDI\*/i)).toBeVisible();
  });
});
