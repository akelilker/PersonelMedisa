import { expect, test, type Request } from "@playwright/test";
import { login, waitForAuthSession } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const AUTH_KEYS = ["medisa_auth_session", "medisa.auth.session.v1"] as const;

async function clearAuth(page: import("@playwright/test").Page): Promise<void> {
  await page.goto("/login", { waitUntil: "domcontentloaded" });
  await page.evaluate((keys) => {
    for (const key of keys) {
      try {
        localStorage.removeItem(key);
        sessionStorage.removeItem(key);
      } catch {
        /* ignore */
      }
    }
  }, [...AUTH_KEYS]);
}

function isProtectedDataRequest(req: Request): boolean {
  const type = req.resourceType();
  if (type !== "fetch" && type !== "xhr") {
    return false;
  }
  let path = "";
  try {
    path = new URL(req.url()).pathname;
  } catch {
    return false;
  }
  if (path.includes("/auth/login")) {
    return false;
  }
  return (
    path.includes("/api/personeller") ||
    path.includes("/api/bildirimler") ||
    path.includes("/api/referans/") ||
    /\/personeller(?:\?|$)/.test(path) ||
    /\/bildirimler(?:\?|$)/.test(path) ||
    /\/referans\//.test(path)
  );
}

test.describe("S90 login bootstrap auth gate", () => {
  test("login ekrani protected API cagrisi yapmaz", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await clearAuth(page);
    const protectedHits: string[] = [];
    page.on("request", (req: Request) => {
      if (isProtectedDataRequest(req)) {
        protectedHits.push(req.url());
      }
    });

    await page.goto("/login", { waitUntil: "networkidle" });
    await expect(page).toHaveURL(/\/login(?:\?|$)/);
    await expect(page.locator('input[name="username"]')).toBeVisible();
    await page.waitForTimeout(500);
    expect(protectedHits, protectedHits.join("\n")).toEqual([]);
  });

  test("basarisiz login yalniz login endpoint cagirir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    // mockApi always accepts login; force a real failure for this case.
    await page.route("**/api/auth/login", async (route) => {
      await route.fulfill({
        status: 401,
        contentType: "application/json",
        body: JSON.stringify({
          ok: false,
          error: { code: "UNAUTHORIZED", message: "Kullanıcı adı veya şifre hatalı." }
        })
      });
    });
    await clearAuth(page);
    const dataHits: string[] = [];
    const loginHits: string[] = [];
    page.on("request", (req: Request) => {
      const type = req.resourceType();
      if (type !== "fetch" && type !== "xhr") {
        return;
      }
      const url = req.url();
      if (url.includes("/auth/login")) {
        loginHits.push(url);
        return;
      }
      if (isProtectedDataRequest(req)) {
        dataHits.push(url);
      }
    });

    await page.goto("/login", { waitUntil: "domcontentloaded" });
    await page.locator('input[name="username"]').fill("wrong_user");
    await page.locator('input[name="password"]').fill("wrong_pass");
    await page.getByRole("button", { name: "Giriş Yap" }).click();
    await expect(page.locator(".auth-error")).toBeVisible({ timeout: 10_000 });
    await expect(page).toHaveURL(/\/login(?:\?|$)/);
    await page.waitForTimeout(400);

    expect(dataHits, dataHits.join("\n")).toEqual([]);
    expect(loginHits.length).toBeGreaterThan(0);
  });

  test("basarili login sonrasi preload en fazla bir kez", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await clearAuth(page);
    const counts = {
      personeller: 0,
      bildirimler: 0,
      departmanlar: 0
    };
    page.on("request", (req: Request) => {
      if (req.resourceType() !== "fetch" && req.resourceType() !== "xhr") {
        return;
      }
      const url = req.url();
      if (url.includes("/personeller") && req.method() === "GET") counts.personeller += 1;
      if (url.includes("/bildirimler") && req.method() === "GET") counts.bildirimler += 1;
      if (url.includes("/referans/departmanlar") && req.method() === "GET") counts.departmanlar += 1;
    });

    await login(page, { username: "yonetici", password: "secret" });
    await waitForAuthSession(page, "GENEL_YONETICI");
    await page.waitForTimeout(800);

    expect(counts.personeller).toBeGreaterThan(0);
    expect(counts.bildirimler).toBeGreaterThan(0);
    expect(counts.departmanlar).toBeGreaterThan(0);
    expect(counts.bildirimler).toBeLessThanOrEqual(2);
    expect(counts.departmanlar).toBeLessThanOrEqual(3);
  });
});
