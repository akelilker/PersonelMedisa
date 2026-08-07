import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

async function openPersonelCardGateway(page: Page) {
  await page.goto("/personeller/1");
  await expect(page).toHaveURL(/\/personeller\/1$/);
  await expect(page.locator(".personel-dosya-hero")).toContainText(/Ayşe Yılmaz/i);

  await expect(page.locator('[data-testid="personel-dosya-action-surec-ekle"]')).toHaveCount(0);
  await expect(page.locator('[data-testid="personel-dosya-action-surecte-islem-yap"]')).toHaveCount(1);

  const mutating: string[] = [];
  page.on("request", (req) => {
    if (!["POST", "PUT", "PATCH", "DELETE"].includes(req.method())) return;
    const url = req.url();
    if (/\/api\//i.test(url) && !/login|auth|session/i.test(url)) {
      mutating.push(`${req.method()} ${url}`);
    }
  });

  await page.getByRole("button", { name: "Islemler" }).click();
  await expect(page.getByRole("button", { name: "Süreç Ekle" })).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Süreçte İşlem Yap" })).toHaveCount(1);
  await page.getByRole("button", { name: "Süreçte İşlem Yap" }).click();

  const modal = page.locator(".modal-container--kayit-surec").last();
  await expect(modal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  await expect(modal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");
  await expect(modal.locator("[name='surec-create-personel']")).toHaveValue("1");
  await expect(modal.locator(".workspace-personel-preview--compact strong")).toContainText(/Ayşe Yılmaz/i, {
    timeout: 15_000
  });

  const turu = modal.locator("[name='surec-create-turu'], [name='surec-create-turu-text']").first();
  if (await turu.count()) {
    const value = await turu.inputValue();
    expect(value.trim()).toBe("");
  }

  expect(mutating).toEqual([]);
  return modal;
}

test.describe("I3 Personel card Süreçte İşlem Yap gateway", () => {
  test("1366x768: single action opens surec tab with personel preselect and no process preselect", async ({
    page
  }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const modal = await openPersonelCardGateway(page);

    await expect(modal.getByTestId("kayit-workspace-scroll-body")).toBeVisible();
    await expect(modal.getByTestId("kayit-workspace-tabs")).toBeVisible();
    await expect(modal.getByTestId("kayit-modal-footer")).toBeVisible();

    await modal.getByTestId("kayit-modal-footer-secondary").click();
    await expect(modal).toHaveCount(0);
  });

  test("390x844: gateway action remains usable without horizontal overflow", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const modal = await openPersonelCardGateway(page);

    const metrics = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      viewportWidth: window.innerWidth
    }));
    expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);

    await expect(modal.getByTestId("kayit-tab-surec")).toBeVisible();
    await expect(modal.getByTestId("kayit-modal-footer-primary")).toBeVisible();

    await modal.getByTestId("kayit-modal-footer-secondary").click();
  });

  test("history panel no longer duplicates Süreç Ekle / Süreçte İşlem Yap CTA", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.goto("/personeller/1");
    await expect(page.locator('[data-testid="personel-dosya-action-surecte-islem-yap"]')).toHaveCount(1);
    await expect(page.locator('[data-testid="personel-dosya-action-surec-ekle"]')).toHaveCount(0);

    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();
    const history = page.locator("#personel-kart-panel-surec-gecmisi");
    await expect(history.getByRole("heading", { name: "Süreç Geçmişi" })).toBeVisible();
    await expect(history.getByRole("button", { name: "Süreç Ekle" })).toHaveCount(0);
    await expect(history.getByRole("button", { name: "Süreçte İşlem Yap" })).toHaveCount(0);
  });
});
