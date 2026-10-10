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
    await expect(kayitModal.getByTestId("kayit-surec-personel-ad-soyad")).toHaveCount(0);

    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Ad/Soyad Veya Sicil No. Girin.").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    // Özet kartı yok; kimlik Genel panelindeki Ad SOYAD başlığında (aynı veri, tek yüzey).
    await expect(kayitModal.getByTestId("kayit-surec-personel-context")).toHaveCount(0);
    const context = kayitModal.getByTestId("kayit-surec-personel-ad-soyad");
    await expect(context).toBeVisible();
    await expect(context).toContainText("Ayşe YILMAZ");
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toHaveCount(0);
    await expect(kayitModal.getByTestId("kayit-surec-personel-genel-panel")).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-surec-personel-genel-panel").getByRole("heading", { name: /Genel bilgiler/i })).toHaveCount(0);

    await kayitModal.getByTestId("kayit-surec-personel-degistir").click();
    await expect(kayitModal.getByRole("combobox", { name: "Personel" })).toBeVisible();
    await expect(kayitModal.getByRole("listbox", { name: "Personel listesi" })).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-surec-person-head")).toBeVisible();

    await kayitModal.getByPlaceholder("Ad/Soyad Veya Sicil No. Girin.").fill("Mehmet");
    await kayitModal.getByRole("option", { name: /Mehmet Kaya/i }).click();

    await expect(context).toContainText("Mehmet KAYA");
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
