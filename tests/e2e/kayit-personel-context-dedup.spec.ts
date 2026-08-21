import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit surec personel context dedup", () => {
  test("selected person collapses picker and change reopens it", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container--kayit-surec").last();
    await kayitModal.getByRole("button", { name: "Süreç" }).click();

    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-surec-personel-context")).toHaveCount(0);

    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    const context = kayitModal.getByTestId("kayit-surec-personel-context");
    await expect(context).toBeVisible();
    await expect(context).toContainText(/Ayşe Yılmaz/i);
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toHaveCount(0);
    await expect(kayitModal.getByTestId("kayit-surec-personel-genel-panel")).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-surec-personel-genel-panel").getByRole("heading", { name: /Ayşe Yılmaz/i })).toHaveCount(0);
    await expect(kayitModal.getByTestId("kayit-surec-personel-genel-panel").getByRole("heading", { name: /Genel bilgiler/i })).toBeVisible();

    await kayitModal.getByTestId("kayit-surec-personel-degistir").click();
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toBeVisible();
    await expect(kayitModal.getByRole("listbox", { name: "Personel listesi" })).toBeVisible();
    await expect(context).toBeVisible();

    await kayitModal.getByPlaceholder("Personel ara").fill("Mehmet");
    await kayitModal.getByRole("option", { name: /Mehmet Kaya/i }).click();

    await expect(context).toContainText(/Mehmet Kaya/i);
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toHaveCount(0);
    await expect(kayitModal.getByTestId("kayit-surec-personel-degistir")).toBeEnabled();

    for (const width of [1200, 390, 360, 320] as const) {
      await page.setViewportSize({ width, height: 844 });
      await expect(context).toBeVisible();
      await expect(kayitModal.getByTestId("kayit-surec-personel-degistir")).toBeVisible();
      const overflow = await kayitModal.evaluate((node) => {
        const el = node as HTMLElement;
        return el.scrollWidth > el.clientWidth + 1;
      });
      expect(overflow, `horizontal overflow at ${width}`).toBe(false);
    }
  });
});
