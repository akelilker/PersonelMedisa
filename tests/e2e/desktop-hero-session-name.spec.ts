import { mkdirSync } from "node:fs";
import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const FULL_NAME = "ilker AKEL";

test.describe("desktop hero session name", () => {
  test("800px app-home: session ad/soyad is not vertically clipped under logo", async ({ page }) => {
    await page.setViewportSize({ width: 800, height: 900 });
    await mockApi(page, "GENEL_YONETICI", { sessionAdSoyad: FULL_NAME });
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await expect(page.locator("body")).toHaveClass(/app-home-route/);

    const heroUser = page.getByTestId("hero-session-user");
    await expect(heroUser).toHaveText(FULL_NAME);

    const metrics = await page.evaluate(() => {
      const user = document.querySelector('[data-testid="hero-session-user"]');
      if (!(user instanceof HTMLElement)) {
        throw new Error("Missing hero session user");
      }
      const userStyle = getComputedStyle(user);
      const userRect = user.getBoundingClientRect();
      return {
        userText: user.textContent?.trim() ?? "",
        userScrollHeight: user.scrollHeight,
        userClientHeight: user.clientHeight,
        overflow: userStyle.overflow,
        transform: userStyle.transform,
        lineHeight: userStyle.lineHeight
      };
    });

    expect(metrics.userText).toBe(FULL_NAME);
    expect(metrics.userScrollHeight).toBeLessThanOrEqual(metrics.userClientHeight + 1);
    expect(metrics.overflow).not.toBe("hidden");
    expect(metrics.transform).toBe("none");

    const artifactDir = "/opt/cursor/artifacts";
    mkdirSync(artifactDir, { recursive: true });
    await page.locator(".hero.hero-with-session").screenshot({
      path: `${artifactDir}/hero-session-desktop-800.png`
    });
  });
});
