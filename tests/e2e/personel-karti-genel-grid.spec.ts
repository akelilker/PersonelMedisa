import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

type Box = { top: number; left: number; right: number; bottom: number };

function boxesOverlap(a: Box, b: Box, epsilon = 1): boolean {
  const separated =
    a.right <= b.left + epsilon ||
    b.right <= a.left + epsilon ||
    a.bottom <= b.top + epsilon ||
    b.bottom <= a.top + epsilon;
  return !separated;
}

test("Genel kimlik alanı dikey, alt yazı yok, sekme kromu opak", async ({ page }) => {
  await mockApi(page, "GENEL_YONETICI");
  await login(page, { username: "yonetici", password: "secret" });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("/personeller/1");

  await expect(page.getByTestId("personel-dosya-sticky-head")).toBeVisible();
  await expect(page.locator(".modal-body .universal-back-bar")).toBeVisible();

  await expect(
    page.getByText("Temel kimlik, iletişim ve lokasyon verileri bu dosyada salt okunur izlenir.")
  ).toHaveCount(0);

  const kimlikSection = page
    .locator("#personel-kart-panel-genel-bilgiler .personel-dosya-section")
    .filter({ hasText: "Kimlik ve İletişim" });
  const records = kimlikSection.locator(".personel-dosya-record:not(.is-missing)");
  await expect(records.first()).toBeVisible();

  const tcRecord = kimlikSection.locator(".personel-dosya-record").filter({ hasText: "T.C. Kimlik No" });
  await tcRecord.scrollIntoViewIfNeeded();
  const layout = await tcRecord.evaluate((node) => {
    const label = node.querySelector(".personel-dosya-record-label");
    const value = node.querySelector(".personel-dosya-record-value");
    if (!label || !value) {
      return null;
    }
    const labelRect = label.getBoundingClientRect();
    const valueRect = value.getBoundingClientRect();
    return {
      labelBottom: labelRect.bottom,
      valueTop: valueRect.top,
      leftDelta: Math.abs(labelRect.left - valueRect.left)
    };
  });
  expect(layout).not.toBeNull();
  if (layout) {
    expect(layout.valueTop).toBeGreaterThanOrEqual(layout.labelBottom - 2);
    expect(layout.leftDelta).toBeLessThan(4);
  }

  const boxes = await records.evaluateAll((nodes) =>
    nodes.map((node) => {
      const rect = node.getBoundingClientRect();
      return { top: rect.top, left: rect.left, right: rect.right, bottom: rect.bottom };
    })
  );

  for (let i = 0; i < boxes.length; i += 1) {
    for (let j = i + 1; j < boxes.length; j += 1) {
      expect(boxesOverlap(boxes[i], boxes[j]), `record ${i} overlaps record ${j}`).toBe(false);
    }
  }

  const tabList = page.locator(".personel-kart-tablist");
  const tabTopBeforeScroll = await tabList.evaluate((el) => el.getBoundingClientRect().top);
  const scrollRegion = page.getByTestId("personel-dosya-tab-scroll");
  await scrollRegion.evaluate((el) => {
    el.scrollTop = el.scrollHeight;
  });
  const tabTopAfterScroll = await tabList.evaluate((el) => el.getBoundingClientRect().top);
  expect(Math.abs(tabTopBeforeScroll - tabTopAfterScroll)).toBeLessThan(1);
});
