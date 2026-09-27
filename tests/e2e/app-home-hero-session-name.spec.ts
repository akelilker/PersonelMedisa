import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const FULL_NAME = "Sercan Topuz";

test.describe("app-home header session name", () => {
  test("390px: logo-block session name is fully visible (not squeezed into 43px column)", async ({
    page
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockApi(page, "PERSONEL", { sessionAdSoyad: FULL_NAME });
    await login(page, MOCK_ROLE_LOGIN.PERSONEL);
    await expect(page.locator("body")).toHaveClass(/app-home-route/);

    const heroUser = page.getByTestId("hero-session-user");
    await expect(heroUser).toHaveText(FULL_NAME);

    const metrics = await page.evaluate(() => {
      const user = document.querySelector('[data-testid="hero-session-user"]');
      const logo = document.querySelector("body.app-home-route .hero.hero-with-session .hero-logo");
      if (!(user instanceof HTMLElement) || !(logo instanceof HTMLElement)) {
        throw new Error("Missing hero session nodes");
      }
      const userStyle = getComputedStyle(user);
      return {
        logoWidth: logo.getBoundingClientRect().width,
        userText: user.textContent?.trim() ?? "",
        userScrollWidth: user.scrollWidth,
        userClientWidth: user.clientWidth,
        userScrollHeight: user.scrollHeight,
        userClientHeight: user.clientHeight,
        whiteSpace: userStyle.whiteSpace,
        textOverflow: userStyle.textOverflow
      };
    });

    expect(metrics.userText).toBe(FULL_NAME);
    expect(metrics.logoWidth).toBeGreaterThan(52);
    expect(metrics.userScrollWidth).toBeLessThanOrEqual(metrics.userClientWidth + 1);
    expect(metrics.userScrollHeight).toBeLessThanOrEqual(metrics.userClientHeight + 1);
    expect(metrics.whiteSpace).toBe("nowrap");
    expect(metrics.textOverflow).toBe("ellipsis");
  });
});
