import { expect, type Page } from "@playwright/test";
import { mockApi, type MockUserRole } from "./mock-api";

type LoginOptions = {
  username: string;
  password: string;
  rememberMe?: boolean;
};

export type MockRoleCredentials = LoginOptions;

const AUTH_SESSION_KEYS = ["medisa_auth_session", "medisa.auth.session.v1"] as const;

export const MOCK_ROLE_LOGIN: Record<MockUserRole, MockRoleCredentials> = {
  GENEL_YONETICI: { username: "yonetici", password: "secret" },
  BOLUM_YONETICISI: { username: "bolum_yoneticisi", password: "demo123" },
  MUHASEBE: { username: "muhasebe", password: "demo123" },
  BIRIM_AMIRI: { username: "birim_amiri", password: "demo123" }
};

async function ensureLoginForm(page: Page): Promise<void> {
  await page.goto("/login", { waitUntil: "domcontentloaded" });

  const username = page.locator('input[name="username"]');
  for (let attempt = 0; attempt < 2; attempt++) {
    if (await username.isVisible().catch(() => false)) {
      return;
    }
    await page.evaluate((keys) => {
      for (const key of keys) {
        try {
          localStorage.removeItem(key);
          sessionStorage.removeItem(key);
        } catch {
          /* ignore */
        }
      }
    }, [...AUTH_SESSION_KEYS]);
    await page.goto("/login", { waitUntil: "domcontentloaded" });
  }

  await expect(username).toBeVisible({ timeout: 30_000 });
}

export async function waitForAuthSession(page: Page, expectedRole?: MockUserRole): Promise<void> {
  await page.waitForFunction(
    ({ role, keys }) => {
      const key = keys[0];
      const raw = window.sessionStorage.getItem(key) ?? window.localStorage.getItem(key);
      if (!raw) {
        return false;
      }
      try {
        const session = JSON.parse(raw) as { token?: string; user?: { rol?: string } };
        if (!session?.token || !session.user?.rol) {
          return false;
        }
        return !role || session.user.rol === role;
      } catch {
        return false;
      }
    },
    { role: expectedRole ?? null, keys: [...AUTH_SESSION_KEYS] },
    { timeout: 15_000 }
  );
}

export async function login(page: Page, options: LoginOptions): Promise<void> {
  const { username, password, rememberMe = false } = options;

  await ensureLoginForm(page);

  const loginResponse = page.waitForResponse(
    (response) => response.url().includes("/api/auth/login") && response.request().method() === "POST"
  );

  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);

  if (rememberMe) {
    const remember = page.getByLabel(/Beni hatırla/i);
    if ((await remember.count()) > 0) {
      await remember.check();
    }
  }

  await page.getByRole("button", { name: "Giriş Yap" }).click();
  const response = await loginResponse;
  expect(response.ok()).toBeTruthy();

  await expect(page).not.toHaveURL(/\/login$/);
}

export async function loginAsMockRole(
  page: Page,
  role: MockUserRole,
  credentials: MockRoleCredentials = MOCK_ROLE_LOGIN[role]
): Promise<void> {
  await mockApi(page, role);
  await login(page, credentials);
  await waitForAuthSession(page, role);
}
