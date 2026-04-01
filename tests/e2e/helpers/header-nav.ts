import type { Page } from "@playwright/test";

export async function openHeaderSettingsMenu(page: Page): Promise<void> {
  await page.getByTestId("header-settings-toggle").click();
}
