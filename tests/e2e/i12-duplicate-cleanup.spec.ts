import { expect, test } from "@playwright/test";
import { loginAsMockRole } from "./helpers/auth";
import { openRaporlarPanel } from "./helpers/raporlar-panel";

test.describe("I12 duplicate cleanup regression", () => {
  test("Card gateway opens Süreç with person preselected and no write", async ({ page }) => {
    const mutating: string[] = [];
    page.on("request", (request) => {
      const method = request.method().toUpperCase();
      if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
        mutating.push(`${method} ${request.url()}`);
      }
    });

    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/personeller/1");
    await page.getByRole("button", { name: "Islemler" }).click();
    await page.getByTestId("personel-dosya-action-surecte-islem-yap").click();

    const modal = page.locator(".modal-container--kayit-surec, .modal-container").filter({
      has: page.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })
    });
    await expect(modal).toBeVisible();
    await expect(modal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");
    await expect(modal.getByRole("combobox", { name: "Personel" })).toBeVisible();
    await expect(modal.getByRole("heading", { name: /Ayşe Yılmaz|Ayse Yilmaz/i })).toBeVisible();
    await expect(modal.getByRole("tab", { name: "Genel" })).toBeVisible();
    expect(mutating.filter((entry) => !entry.includes("/auth/"))).toEqual([]);
  });

  test("legacy revizyon-merkezi redirect preserved", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/revizyon-merkezi");
    await expect(page).toHaveURL(/\/haftalik-kapanis\/revizyonlar/);
  });

  test("I11 grouped Raporlar nav still renders", async ({ page }) => {
    await openRaporlarPanel(page, "GENEL_YONETICI", "standart");
    await expect(page.getByRole("link", { name: "Liste Raporları" })).toHaveAttribute(
      "aria-current",
      "page"
    );
    await expect(page.getByTestId("raporlar-nav-group-kapanis")).toBeVisible();
    await expect(page.getByTestId("raporlar-nav-group-bordro")).toBeVisible();
  });

  test("Bordro Kapsam owner remains reachable", async ({ page }) => {
    await loginAsMockRole(page, "GENEL_YONETICI");
    await page.goto("/raporlar?panel=bordro-hazirlik&tab=personel-kapsam&personelId=1");
    await expect(page.getByTestId("bordro-hazirlik-merkezi")).toBeVisible();
    await expect(page.getByTestId("bordro-hazirlik-tab-personel-kapsam")).toHaveClass(/is-active/);
    await expect(page.getByLabel("Personel ID")).toHaveValue("1");
  });
});
