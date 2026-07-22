import { expect, test } from "@playwright/test";
import { login, MOCK_ROLE_LOGIN } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("S88 resmi tatil takvimi", () => {
  test("yetkili rol menü ve route erişimi", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);

    await page.getByTestId("header-settings-toggle").click();
    await expect(page.getByTestId("settings-resmi-tatil-takvimi")).toBeVisible();
    await page.getByTestId("settings-resmi-tatil-takvimi").click();
    await expect(page).toHaveURL(/\/resmi-tatil-takvimi$/);
    await expect(page.getByTestId("resmi-tatil-takvimi-page")).toBeVisible();
    await expect(page.getByTestId("rtt-readiness-cards")).toBeVisible();
    await expect(page.getByTestId("rtt-policy-not-active")).toContainText(/kapalı/i);
  });

  test("muhasebe read-only: liste var, write yok", async ({ page }) => {
    await mockApi(page, "MUHASEBE");
    await login(page, MOCK_ROLE_LOGIN.MUHASEBE);
    await page.goto("/resmi-tatil-takvimi");
    await expect(page.getByTestId("resmi-tatil-takvimi-page")).toBeVisible();
    await expect(page.getByTestId("rtt-create-btn")).toHaveCount(0);
  });

  test("yetkisiz doğrudan route reddi", async ({ page }) => {
    await mockApi(page, "BIRIM_AMIRI");
    await login(page, MOCK_ROLE_LOGIN.BIRIM_AMIRI);
    await page.goto("/resmi-tatil-takvimi");
    await expect(page).toHaveURL(/\/yetkisiz/);
  });

  test("TAM_GUN taslak, interval yok, aktifleştir, duplicate, revizyon, geçmiş, iptal, preview", async ({
    page
  }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, MOCK_ROLE_LOGIN.GENEL_YONETICI);
    await page.goto("/resmi-tatil-takvimi");

    await page.getByLabel("Yıl").fill("2099");
    await page.getByLabel("Tarih başlangıç").fill("2099-01-01");
    await page.getByLabel("Tarih bitiş").fill("2099-12-31");
    await expect(page.getByTestId("rtt-readiness-cards")).toBeVisible();

    await page.getByTestId("rtt-create-btn").click();
    const form = page.getByTestId("rtt-form");
    await form.getByLabel("Tarih", { exact: true }).fill("2099-01-15");
    await form.getByLabel("Tatil kodu").fill("SYN_TAM");
    await form.getByLabel("Tatil adı").fill("Sentetik Tam Gün");
    await form.getByLabel("Gün kapsamı").selectOption("TAM_GUN");
    await expect(form.getByLabel("Interval başlangıç")).toHaveCount(0);
    await form.getByLabel("Kaynak türü").fill("TEST");
    await form.getByLabel("Kaynak referansı").fill("e2e-tam-1");

    const createReq = page.waitForRequest(
      (req) =>
        req.url().includes("/resmi-tatil-takvimi") &&
        req.method() === "POST" &&
        !req.url().includes("projection") &&
        !req.url().includes("aktiflestir") &&
        !req.url().includes("revize") &&
        !req.url().includes("iptal")
    );
    await page.getByTestId("rtt-submit").click();
    const body = JSON.parse((await createReq).postData() || "{}") as Record<string, unknown>;
    expect(body.tatil_interval_baslangic ?? null).toBeNull();
    expect(body.tatil_interval_bitis ?? null).toBeNull();
    await expect(page.getByTestId("rtt-action-message")).toContainText(/Taslak/i);

    await expect(page.getByTestId("rtt-list")).toContainText("Sentetik Tam Gün");
    const dateText = await page.locator('[data-testid^="rtt-date-"]').first().innerText();
    expect(dateText).toMatch(/15/);

    // YARIM_GUN validation
    await page.getByTestId("rtt-create-btn").click();
    const form2 = page.getByTestId("rtt-form");
    await form2.getByLabel("Tarih", { exact: true }).fill("2099-01-16");
    await form2.getByLabel("Tatil kodu").fill("SYN_YARIM");
    await form2.getByLabel("Tatil adı").fill("Sentetik Yarım");
    await form2.getByLabel("Gün kapsamı").selectOption("YARIM_GUN");
    await form2.getByLabel("Kaynak türü").fill("TEST");
    await form2.getByLabel("Kaynak referansı").fill("e2e-yarim-1");
    await form2.getByLabel("Interval başlangıç").fill("18:00");
    await form2.getByLabel("Interval bitiş").fill("13:00");
    await page.getByTestId("rtt-submit").click();
    await expect(page.getByTestId("rtt-submit-error")).toBeVisible();
    await form2.getByLabel("Interval başlangıç").fill("13:00");
    await form2.getByLabel("Interval bitiş").fill("18:00");
    await page.getByTestId("rtt-submit").click();
    await expect(page.getByTestId("rtt-list")).toContainText("Sentetik Yarım");

    // Activate first TAM_GUN draft
    await page.locator('[data-testid^="rtt-activate-"]').first().click();
    await expect(page.getByTestId("rtt-action-message")).toContainText(/aktif/i);

    // Duplicate activate conflict
    await page.getByTestId("rtt-create-btn").click();
    const form3 = page.getByTestId("rtt-form");
    await form3.getByLabel("Tarih", { exact: true }).fill("2099-01-15");
    await form3.getByLabel("Tatil kodu").fill("SYN_DUP");
    await form3.getByLabel("Tatil adı").fill("Dup");
    await form3.getByLabel("Gün kapsamı").selectOption("TAM_GUN");
    await form3.getByLabel("Kaynak türü").fill("TEST");
    await form3.getByLabel("Kaynak referansı").fill("e2e-dup");
    await page.getByTestId("rtt-submit").click();
    await page.locator('[data-testid^="rtt-activate-"]').last().click();
    await expect(page.getByTestId("rtt-action-error")).toContainText(/aktif UBGT/i);

    // Revise active
    await page.locator('[data-testid^="rtt-revise-"]').first().click();
    const form4 = page.getByTestId("rtt-form");
    await form4.getByLabel("Tatil adı").fill("Sentetik Tam Gün Rev2");
    await form4.getByLabel("Revizyon gerekçesi").fill("E2E revizyon");
    await page.getByTestId("rtt-submit").click();
    await expect(page.getByTestId("rtt-action-message")).toContainText(/revizyon/i);

    await page.locator('[data-testid^="rtt-history-"]').first().click();
    await expect(page.getByTestId("rtt-history-modal")).toBeVisible();
    await expect(page.getByTestId("rtt-history-item-rev-1")).toBeVisible();
    await page.keyboard.press("Escape");

    // Cancel
    await page.locator('[data-testid^="rtt-cancel-"]').first().click();
    await page.getByTestId("rtt-cancel-form").getByLabel("İptal gerekçesi").fill("E2E iptal");
    await page.getByTestId("rtt-cancel-submit").click();
    await expect(page.getByTestId("rtt-action-message")).toContainText(/iptal/i);

    await expect(page.getByTestId("rtt-preview-readonly")).toContainText(/read-only/i);
    await expect(page.getByTestId("rtt-card-policy")).toBeVisible();
  });
});
