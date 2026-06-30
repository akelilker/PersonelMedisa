import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const users = {
  genelYonetici: { username: "genel_yonetici", password: "demo123" }
};

test.describe("timeline quality", () => {
  test("belge sureci timeline etiket ve tarih kalitesini korur", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    const createResponse = await page.evaluate(async () => {
      const response = await fetch("/api/surecler", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          personel_id: 1,
          surec_turu: "BELGE",
          alt_tur: "SERTIFIKA",
          baslangic_tarihi: "2026-06-30",
          aciklama: "S29 timeline kalite belge"
        })
      });
      return { status: response.status, pathname: window.location.pathname };
    });

    expect(createResponse.status).toBe(201);
    expect(createResponse.pathname).not.toBe("/yetkisiz");

    await page.goto("/personeller/1");
    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();

    const timeline = page
      .locator("#personel-kart-panel-surec-gecmisi")
      .locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toBeVisible();
    await expect(timeline).toContainText("Belge / Sertifika");
    await expect(timeline).not.toContainText("Belge / Sertifika / Sertifika");
    await expect(timeline).not.toContainText(/Sertf/i);
    await expect(timeline).not.toContainText('"tip"');
    await expect(timeline).not.toContainText("[object Object]");

    const belgeItem = timeline.locator("li").filter({ hasText: "Belge / Sertifika" }).first();
    const itemText = await belgeItem.innerText();
    expect(itemText.match(/Başlangıç:/g)?.length ?? 0).toBeLessThanOrEqual(1);
    expect(itemText).toContain("Tarih: 2026-06-30");
    expect(itemText).toContain("S29 timeline kalite belge");

    await page.getByRole("tab", { name: "Eğitim / Belgeler" }).click();
    await expect(page.getByTestId("personel-belgeler-panel")).toBeVisible();
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
  });

  test("belge metadata adini timeline ozetinde gosterir", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, users.genelYonetici);

    await page.goto("/personeller/1");
    await page.getByRole("tab", { name: "Süreç Geçmişi" }).click();

    const timeline = page
      .locator("#personel-kart-panel-surec-gecmisi")
      .locator("[data-testid='personel-surec-timeline']");
    await expect(timeline).toBeVisible();
    await expect(timeline).toContainText("Belge / Sertifika");
    await expect(timeline).toContainText("S32 Forklift Belgesi");
    await expect(timeline).not.toContainText("Belge / Sertifika / Sertifika");
    await expect(timeline).not.toContainText('"tip"');
    await expect(timeline).not.toContainText("_personel_belge_kaydi");
    await expect(timeline).not.toContainText("[object Object]");
    await expect(page).not.toHaveURL(/\/yetkisiz$/);
  });
});
