import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

async function openPersonelDetay(page: import("@playwright/test").Page, personelId = 1) {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "yonetici", password: "secret" });
  await page.goto(`/personeller/${personelId}`);
  await expect(page).toHaveURL(new RegExp(`/personeller/${personelId}$`));
  await expect(page.locator(".personel-dosya-hero")).toBeVisible();
}

test.describe("personel kartı sekme overflow", () => {
  test("390px: yatay oklar görünür, scrollbar gizli, tüm sekmeler erişilebilir", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openPersonelDetay(page);

    const tabList = page.getByTestId("personel-kart-tablist");
    const scroller = page.getByTestId("personel-kart-tab-scroller");

    await expect(scroller).toHaveClass(/is-overflowing/);
    await expect(page.getByTestId("personel-kart-tab-scroll-next")).toBeVisible();
    await expect(page.getByTestId("personel-kart-tab-scroll-prev")).toBeDisabled();

    const scrollbarHidden = await tabList.evaluate((el) => {
      const style = window.getComputedStyle(el);
      return style.scrollbarWidth === "none" || style.msOverflowStyle === "none";
    });
    expect(scrollbarHidden).toBe(true);

    const surecTab = page.getByRole("tab", { name: /^Süreç Geçmişi$/ });
    await expect(surecTab).not.toBeInViewport();

    await page.getByTestId("personel-kart-tab-scroll-next").click();
    await expect(surecTab).toBeInViewport();
    await surecTab.click();
    await expect(surecTab).toHaveAttribute("aria-selected", "true");
  });

  test("1280px: tüm sekmeler sığdığında oklar görünmez", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await openPersonelDetay(page);

    const scroller = page.getByTestId("personel-kart-tab-scroller");
    await expect(scroller).not.toHaveClass(/is-overflowing/);
    await expect(page.getByTestId("personel-kart-tab-scroll-next")).toHaveCount(0);
    await expect(page.getByTestId("personel-kart-tab-scroll-prev")).toHaveCount(0);
  });
});
