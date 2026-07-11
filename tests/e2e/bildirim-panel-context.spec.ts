import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

async function openBildirimler(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, { username: role.toLowerCase(), password: "demo123" });
  await page.goto("/bildirimler");
  await expect(page).toHaveURL(/\/bildirimler$/);
}

test.describe("Bildirim panel rol baglami", () => {
  test("GENEL_YONETICI secim tamamlanmadan ozet istemez ve tek sube baglaminda yukler", async ({ page }) => {
    const summaryRequests: string[] = [];
    page.on("request", (request) => {
      if (request.url().includes("/haftalik-bildirim-mutabakatlari/ozet") || request.url().includes("/aylik-bildirim-onaylari/ozet")) {
        summaryRequests.push(request.url());
      }
    });

    await openBildirimler(page, "GENEL_YONETICI");
    await expect(page.getByTestId("bildirim-panel-context")).toBeVisible();
    await expect(page.getByLabel("Şube")).toHaveValue("");
    expect(summaryRequests).toEqual([]);

    await page.getByLabel("Şube").selectOption("1");
    await expect(page.getByLabel("Birim Amiri")).toHaveValue("1");
    await expect(page.getByTestId("haftalik-mutabakat-counts")).toBeVisible();
    await expect(page.getByTestId("aylik-bildirim-onay-counts")).toBeVisible();
    expect(summaryRequests).toHaveLength(2);
    expect(summaryRequests.every((requestUrl) => requestUrl.includes("sube_id=1") && requestUrl.includes("birim_amiri_user_id=1"))).toBe(true);

    await page.getByLabel("Şube").selectOption("2");
    await expect(page.getByLabel("Birim Amiri")).toHaveValue("4");
    await expect.poll(() => summaryRequests.filter((requestUrl) => requestUrl.includes("sube_id=2") && requestUrl.includes("birim_amiri_user_id=4")).length).toBe(2);
    await expect(page.getByTestId("haftalik-mutabakat-approve")).toHaveCount(0);
    await expect(page.getByTestId("aylik-bildirim-onay-approve")).toHaveCount(0);
  });

  for (const [role, branchId, amirId] of [
    ["BOLUM_YONETICISI", "2", "4"],
    ["MUHASEBE", "1", "1"]
  ] as const) {
    test(`${role} izinli sube ve birim amiri baglaminda read-only kalir`, async ({ page }) => {
      await openBildirimler(page, role);
      await expect(page.getByTestId("bildirim-panel-context")).toBeVisible();
      await expect(page.getByLabel("Şube")).toHaveValue(branchId);
      await expect(page.getByLabel("Birim Amiri")).toHaveValue(amirId);
      await expect(page.getByTestId("haftalik-mutabakat-counts")).toBeVisible();
      await expect(page.getByTestId("aylik-bildirim-onay-counts")).toBeVisible();
      await expect(page.getByTestId("haftalik-mutabakat-approve")).toHaveCount(0);
      await expect(page.getByTestId("aylik-bildirim-onay-approve")).toHaveCount(0);
    });
  }

  test("BIRIM_AMIRI selector gormez ve kendi onay aksiyonlarini korur", async ({ page }) => {
    await openBildirimler(page, "BIRIM_AMIRI");
    await expect(page.getByTestId("bildirim-panel-context")).toHaveCount(0);
    await expect(page.getByTestId("haftalik-mutabakat-counts")).toBeVisible();
    await expect(page.getByTestId("aylik-bildirim-onay-counts")).toBeVisible();
    await expect(page.getByTestId("haftalik-mutabakat-approve")).toBeVisible();
    await expect(page.getByTestId("aylik-bildirim-onay-approve")).toBeVisible();
  });
});
