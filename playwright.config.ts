import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "tests/e2e",
  fullyParallel: false,
  retries: 0,
  workers: 1,
  timeout: 60_000,
  use: {
    baseURL: "http://127.0.0.1:4173",
    headless: true
  },
  webServer: {
    command: "npm run dev -- --host 127.0.0.1 --port 4173",
    url: "http://127.0.0.1:4173/login",
    // Always own the E2E server lifecycle. Sharing a leftover :4173 process caused
    // mid-suite ERR_CONNECTION_REFUSED / first-interaction 60s timeouts under load.
    reuseExistingServer: false,
    timeout: 120_000
  }
});
