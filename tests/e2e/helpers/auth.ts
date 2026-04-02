import { expect, type Page } from "@playwright/test";

type LoginOptions = {
  username: string;
  password: string;
  rememberMe?: boolean;
};

const AUTH_SESSION_KEYS = ["medisa_auth_session", "medisa.auth.session.v1"] as const;

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

export async function login(page: Page, options: LoginOptions): Promise<void> {
  const { username, password, rememberMe = false } = options;

  await ensureLoginForm(page);

  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill(password);

  if (rememberMe) {
      const remember = page.getByLabel(/Beni hatırla/i);
    if ((await remember.count()) > 0) {
      await remember.check();
    }
  }

  await page.getByRole("button", { name: "Giriş Yap" }).click();

  await expect(page).not.toHaveURL(/\/login$/);
}
