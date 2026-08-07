import { expect, test, type Page } from "@playwright/test";
import { loginAsMockRole, MOCK_ROLE_LOGIN } from "./helpers/auth";

const CLOSE_WEEK_START = "2026-05-04";
const CLOSE_WEEK_END = "2026-05-10";

function ignoreBenignConsole(line: string): boolean {
  return (
    line.includes("favicon") ||
    line.includes("Download the React DevTools") ||
    /Failed to load resource:.*favicon/i.test(line)
  );
}

async function openClosePage(page: Page) {
  await page.goto("/haftalik-kapanis", { waitUntil: "domcontentloaded" });
  await expect(page.getByTestId("haftalik-kapanis-page")).toBeVisible();
  await expect(page.getByTestId("hk-close-panel")).toBeVisible();
}

async function chooseWeekAndBranchScope(page: Page) {
  await page.getByTestId("hk-close-hafta-baslangic").fill(CLOSE_WEEK_START);
  await expect(page.getByTestId("hk-close-hafta-bitis")).toContainText(CLOSE_WEEK_END);
  await page.getByTestId("hk-close-scope-sube").check();
}

test.describe("I5 haftalik kapanis close UI", () => {
  test("BOLUM_YONETICISI branch-wide close success with confirmation", async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on("console", (msg) => {
      if (msg.type() === "error" && !ignoreBenignConsole(msg.text())) {
        consoleErrors.push(msg.text());
      }
    });

    await loginAsMockRole(page, "BOLUM_YONETICISI", MOCK_ROLE_LOGIN.BOLUM_YONETICISI);
    await openClosePage(page);
    await chooseWeekAndBranchScope(page);

    const postsBeforeConfirm: string[] = [];
    page.on("request", (req) => {
      if (req.method() === "POST" && /\/api\/haftalik-kapanis$/.test(req.url())) {
        postsBeforeConfirm.push(req.postData() ?? "");
      }
    });

    await page.getByTestId("hk-close-open").click();
    await expect(page.getByTestId("hk-close-confirm-dialog")).toBeVisible();
    expect(postsBeforeConfirm).toHaveLength(0);

    const postPromise = page.waitForRequest(
      (req) => req.method() === "POST" && /\/api\/haftalik-kapanis$/.test(req.url())
    );
    const responsePromise = page.waitForResponse(
      (res) => res.request().method() === "POST" && /\/api\/haftalik-kapanis$/.test(res.url())
    );

    await page.getByTestId("hk-close-confirm-dialog-confirm").click();
    const request = await postPromise;
    const response = await responsePromise;

    const body = request.postDataJSON() as Record<string, unknown>;
    expect(body).toEqual({
      hafta_baslangic: CLOSE_WEEK_START,
      hafta_bitis: CLOSE_WEEK_END
    });
    expect(Object.prototype.hasOwnProperty.call(body, "departman_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(body, "sube_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(body, "created_by")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(body, "actor_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(body, "user_id")).toBe(false);
    expect(Object.prototype.hasOwnProperty.call(body, "state")).toBe(false);

    expect(response.status()).toBe(201);
    await expect(page.getByTestId("hk-close-success")).toBeVisible();
    await expect(page.getByTestId("hk-close-success-state")).toContainText("KAPANDI");
    expect(consoleErrors).toEqual([]);
  });

  test("department scope posts numeric departman_id", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI", MOCK_ROLE_LOGIN.BOLUM_YONETICISI);
    await openClosePage(page);
    await page.getByTestId("hk-close-hafta-baslangic").fill("2026-05-11");
    await page.getByTestId("hk-close-scope-departman").check();
    await expect(page.getByTestId("hk-close-departman")).toBeVisible();
    await page.getByTestId("hk-close-departman").selectOption("6");

    await page.getByTestId("hk-close-open").click();
    await expect(page.getByTestId("hk-close-confirm-dialog")).toBeVisible();

    const postPromise = page.waitForRequest(
      (req) => req.method() === "POST" && /\/api\/haftalik-kapanis$/.test(req.url())
    );
    await page.getByTestId("hk-close-confirm-dialog-confirm").click();
    const request = await postPromise;
    const body = request.postDataJSON() as Record<string, unknown>;
    expect(body).toEqual({
      hafta_baslangic: "2026-05-11",
      hafta_bitis: "2026-05-17",
      departman_id: 6
    });
    expect(typeof body.departman_id).toBe("number");
    expect(Object.prototype.hasOwnProperty.call(body, "sube_id")).toBe(false);
    await expect(page.getByTestId("hk-close-success")).toBeVisible();
  });

  test("mutabakat 409 shows blocker without success", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI", MOCK_ROLE_LOGIN.BOLUM_YONETICISI);
    await page.route("**/api/haftalik-kapanis", async (route) => {
      if (route.request().method() !== "POST") {
        await route.fallback();
        return;
      }
      await route.fulfill({
        status: 409,
        contentType: "application/json",
        body: JSON.stringify({
          data: null,
          meta: {},
          errors: [
            {
              code: "STATE_CONFLICT",
              message: "Haftalik mutabakat tamamlanmamis."
            }
          ]
        })
      });
    });

    await openClosePage(page);
    await chooseWeekAndBranchScope(page);
    await page.getByTestId("hk-close-open").click();
    await page.getByTestId("hk-close-confirm-dialog-confirm").click();
    await expect(page.getByTestId("hk-close-confirm-error")).toContainText(
      "Haftalik mutabakat tamamlanmamis"
    );
    await expect(page.getByTestId("hk-close-success")).toHaveCount(0);
  });

  test("duplicate 409 shows blocker without fake success", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI", MOCK_ROLE_LOGIN.BOLUM_YONETICISI);
    await openClosePage(page);
    await page.getByTestId("hk-close-hafta-baslangic").fill("2026-05-18");
    await page.getByTestId("hk-close-open").click();
    await page.getByTestId("hk-close-confirm-dialog-confirm").click();
    await expect(page.getByTestId("hk-close-success")).toBeVisible();

    await page.getByTestId("hk-close-hafta-baslangic").fill("2026-05-25");
    await page.getByTestId("hk-close-hafta-baslangic").fill("2026-05-18");
    await expect(page.getByTestId("hk-close-open")).toBeEnabled();
    await page.getByTestId("hk-close-open").click();
    await page.getByTestId("hk-close-confirm-dialog-confirm").click();
    await expect(page.getByTestId("hk-close-confirm-error")).toContainText("zaten olusturulmus");
    await expect(page.getByTestId("hk-close-success")).toHaveCount(0);
  });

  test("roles without puantaj.muhurle hide close action", async ({ page }) => {
    for (const role of ["MUHASEBE", "BIRIM_AMIRI", "PATRON"] as const) {
      await loginAsMockRole(page, role, MOCK_ROLE_LOGIN[role]);
      await page.goto("/haftalik-kapanis", { waitUntil: "domcontentloaded" });
      if (role === "PATRON") {
        // PATRON may lack revizyon.view — still must not expose close action.
        await expect(page.getByTestId("hk-close-open")).toHaveCount(0);
        await expect(page.getByTestId("hk-close-panel")).toHaveCount(0);
      } else {
        await expect(page.getByTestId("haftalik-kapanis-page")).toBeVisible();
        await expect(page.getByTestId("hk-close-panel")).toHaveCount(0);
        await expect(page.getByTestId("hk-close-open")).toHaveCount(0);
      }
    }
  });

  test("desktop and mobile close panel usable", async ({ page }) => {
    await loginAsMockRole(page, "BOLUM_YONETICISI", MOCK_ROLE_LOGIN.BOLUM_YONETICISI);

    for (const viewport of [
      { width: 1366, height: 768 },
      { width: 390, height: 844 }
    ]) {
      await page.setViewportSize(viewport);
      await openClosePage(page);
      const overflow = await page.evaluate(() => {
        return Math.max(
          document.documentElement.scrollWidth - window.innerWidth,
          document.body.scrollWidth - window.innerWidth
        );
      });
      expect(overflow).toBeLessThanOrEqual(2);
      await expect(page.getByTestId("hk-close-hafta-baslangic")).toBeVisible();
      await page.getByTestId("hk-close-hafta-baslangic").fill("2026-06-01");
      await page.getByTestId("hk-close-open").click();
      await expect(page.getByTestId("hk-close-confirm-dialog")).toBeVisible();
      await page.getByTestId("hk-close-confirm-dialog-cancel").click();
      await expect(page.getByTestId("hk-close-confirm-dialog")).toHaveCount(0);
    }
  });

  test("existing page surfaces remain", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI", MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/haftalik-kapanis", { waitUntil: "domcontentloaded" });
    await expect(page.getByTestId("hk-revizyon-merkezi-link")).toBeVisible();
    await expect(page.getByTestId("hk-onay-bekleyenler-link")).toBeVisible();
    await expect(page.getByTestId("hk-corrections-link")).toBeVisible();
    await expect(page.getByTestId("hk-revizyon-talebi-ac")).toBeVisible();
    // GY all-sube mode: panel visible, close disabled without active branch.
    await expect(page.getByTestId("hk-close-panel")).toBeVisible();
    await expect(page.getByTestId("hk-close-active-sube-required")).toBeVisible();
    await expect(page.getByTestId("hk-close-open")).toBeDisabled();
  });
});
