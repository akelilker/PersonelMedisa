import { defineConfig, devices } from "@playwright/test";

const MOBILE_REGRESSION = "**/mobile-tasit-parity*.spec.ts";

export default defineConfig({
  testDir: "tests/e2e",
  fullyParallel: false,
  retries: 0,
  workers: 1,
  timeout: 60_000,
  use: {
    baseURL: "http://127.0.0.1:4173",
    headless: true,
    // PR #327 registers a pass-through SW for PWA installability. WebKit routes fetch
    // through the worker, which bypasses Playwright `page.route` mocks and yields 404 login.
    serviceWorkers: "block"
  },
  projects: [
    {
      name: "chromium",
      testIgnore: MOBILE_REGRESSION,
      use: { browserName: "chromium" }
    },
    {
      name: "chromium-mobile-regression",
      testMatch: MOBILE_REGRESSION,
      use: { browserName: "chromium" }
    },
    {
      name: "webkit-mobile-regression",
      testMatch: MOBILE_REGRESSION,
      use: { ...devices["iPhone 14"] }
    }
  ],
  webServer: {
    command: "npm run dev -- --host 127.0.0.1 --port 4173",
    url: "http://127.0.0.1:4173/login",
    reuseExistingServer: false,
    timeout: 120_000
  }
});
