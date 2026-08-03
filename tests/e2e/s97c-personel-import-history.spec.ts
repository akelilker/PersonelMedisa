import { expect, test } from "@playwright/test";
import { loginAsMockRole, login, waitForAuthSession, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("S97-C personel import history UI", () => {
  test("authorized user sees empty import history state", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/personeller");
    await page.getByTestId("personeller-import-history-open").click();
    await expect(page.getByTestId("personel-import-history-title")).toBeVisible();
    await expect(page.getByTestId("personel-import-history-empty")).toContainText(
      "Henüz tamamlanmış veya başarısız bir personel import işlemi bulunmuyor."
    );
    await expect(page.getByText(/Tekrar çalıştır|Retry|Sil|İptal|Personelleri Sisteme Aktar/i)).toHaveCount(
      0
    );
  });

  test("synthetic completed run opens detail, masked TC, evidence CSV without raw secrets", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI", { personelImportHistorySeed: "completed" });
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await waitForAuthSession(page, "GENEL_YONETICI");
    await page.goto("/personeller");
    await page.getByTestId("personeller-import-history-open").click();
    await expect(page.getByTestId("personel-import-history-list")).toBeVisible();
    await page.getByTestId("personel-import-history-open-91001").click();
    await expect(page.getByTestId("personel-import-history-detail")).toBeVisible();
    await expect(page.getByText("100******46")).toBeVisible();
    await expect(page.getByText(/10000000146/)).toHaveCount(0);
    await expect(page.getByText(/pir-e2e-hidden-key/)).toHaveCount(0);
    await expect(page.getByText(/Tekrar çalıştır|Retry import|Sil|İptal/i)).toHaveCount(0);

    const downloadPromise = page.waitForEvent("download");
    await page.getByTestId("personel-import-history-evidence").click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toContain("personel-import-kaniti-91001");
    const path = await download.path();
    expect(path).toBeTruthy();
    const fs = await import("node:fs");
    const csv = fs.readFileSync(path!, "utf8");
    expect(csv).toContain("100******46");
    expect(csv).not.toContain("10000000146");
    expect(csv).not.toMatch(/idempotency_key/i);
    expect(csv).not.toContain("pir-e2e-hidden-key");
  });

  test("birim amiri cannot see history action and endpoint returns 403", async ({ page }) => {
    await loginAsMockRole(page, "BIRIM_AMIRI");
    await page.goto("/personeller");
    await expect(page.getByTestId("personeller-import-history-open")).toHaveCount(0);

    const status = await page.evaluate(async () => {
      const raw =
        window.sessionStorage.getItem("medisa_auth_session") ||
        window.localStorage.getItem("medisa_auth_session") ||
        "";
      let token = "";
      try {
        const parsed = JSON.parse(raw) as { token?: string };
        token = parsed.token ?? "";
      } catch {
        token = "";
      }
      const res = await fetch("/api/personeller/import/runs", {
        headers: token ? { Authorization: `Bearer ${token}` } : {}
      });
      return res.status;
    });
    expect(status).toBe(403);
  });
});
