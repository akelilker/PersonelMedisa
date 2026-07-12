import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi, type MockUserRole } from "./helpers/mock-api";

const S73_PATH = "/api/genel-yonetici-bildirim-onaylari";

async function openBildirimler(page: Page, role: MockUserRole) {
  await mockApi(page, role);
  await login(page, { username: role.toLowerCase(), password: "demo123" });
  await page.goto("/bildirimler");
  await expect(page).toHaveURL(/\/bildirimler$/);
}

test.describe("Genel Yonetici bildirim onay paneli", () => {
  test("eksik baglamda GET yapmaz, secimden sonra hazir ozeti gosterir", async ({ page }) => {
    const getRequests: string[] = [];
    page.on("request", (request) => {
      if (request.method() === "GET" && request.url().includes(`${S73_PATH}/ozet`)) {
        getRequests.push(request.url());
      }
    });

    await openBildirimler(page, "GENEL_YONETICI");
    await expect(page.getByTestId("genel-yonetici-bildirim-onay-panel")).toBeVisible();
    await expect(page.getByText("Genel Yönetici onayı için şube seçin.")).toBeVisible();
    expect(getRequests).toEqual([]);

    await page.getByLabel("Şube").selectOption("1");
    await expect(page.getByLabel("Birim Amiri")).toHaveValue("1");
    await expect(page.getByTestId("genel-yonetici-bildirim-onay-ozet")).toBeVisible();
    await expect(page.getByText("Aylık Bildirim Onay ID")).toBeVisible();
    await expect(page.getByText("Henüz onaylanmadı")).toBeVisible();
    await expect(page.getByTestId("genel-yonetici-bildirim-onay-approve")).toBeEnabled();
    expect(getRequests).toHaveLength(1);
    expect(getRequests[0]).toContain("sube_id=1");
    expect(getRequests[0]).toContain("birim_amiri_user_id=1");
  });

  test("modal ile tek POST gonderir, sonucu yeniler ve duplicate aksiyonunu kapatir", async ({ page }) => {
    let postCount = 0;
    page.on("request", (request) => {
      if (request.method() === "POST" && new URL(request.url()).pathname === S73_PATH) postCount += 1;
    });

    await openBildirimler(page, "GENEL_YONETICI");
    await page.getByLabel("Şube").selectOption("1");
    const approveButton = page.getByTestId("genel-yonetici-bildirim-onay-approve");
    await expect(approveButton).toBeEnabled();
    await approveButton.click();
    await expect(page.getByRole("heading", { name: "Genel Yönetici Onayı" })).toBeVisible();
    await expect(page.getByText(/Bu işlem mevcut sürümde geri alınamaz/)).toBeVisible();
    await page.getByRole("button", { name: "Onayı Ver", exact: true }).click();

    await expect(page.getByText("Genel Yönetici bildirim onayı tamamlandı.")).toBeVisible();
    await expect(page.getByText("Bu dönem Genel Yönetici tarafından onaylanmış.")).toBeVisible();
    await expect(
      page.getByTestId("genel-yonetici-bildirim-onay-ozet").getByText("TAMAMLANDI", { exact: true })
    ).toHaveCount(2);
    await expect(approveButton).toBeDisabled();
    expect(postCount).toBe(1);
    await approveButton.click({ force: true });
    expect(postCount).toBe(1);
  });

  test("sube degisince BA ve eski ozet temizlenir", async ({ page }) => {
    await openBildirimler(page, "GENEL_YONETICI");
    await page.getByLabel("Şube").selectOption("1");
    await expect(page.getByTestId("genel-yonetici-bildirim-onay-ozet")).toBeVisible();
    await page.getByLabel("Şube").selectOption("2");
    await expect(page.getByTestId("genel-yonetici-bildirim-onay-ozet")).toHaveCount(0);
    await expect(page.getByLabel("Birim Amiri")).toHaveValue("4");
    await expect(page.getByTestId("genel-yonetici-bildirim-onay-ozet")).toBeVisible();
  });

  for (const role of ["BIRIM_AMIRI", "BOLUM_YONETICISI", "MUHASEBE"] as const) {
    test(`${role} paneli gormez ve S73 istegi gondermez`, async ({ page }) => {
      const requests: string[] = [];
      page.on("request", (request) => {
        if (new URL(request.url()).pathname.startsWith(S73_PATH)) requests.push(request.url());
      });
      await openBildirimler(page, role);
      await expect(page.getByTestId("genel-yonetici-bildirim-onay-panel")).toHaveCount(0);
      expect(requests).toEqual([]);
    });
  }
});
