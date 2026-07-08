import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec hastalik raporu checkbox", () => {
  test("rapor seciminde checkbox gorunur ve varsayilan unchecked; is kazasinda gorunmez", async ({ page }) => {
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container").last();
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "İzin / Devamsızlık" }).click();
    await kayitModal.getByRole("button", { name: /Rapor/i }).click();

    const checkbox = kayitModal.getByTestId("surec-ilk-iki-gun-firma-oder-mi");
    await expect(checkbox).toBeVisible();
    await expect(checkbox).not.toBeChecked();

    await kayitModal.getByRole("button", { name: /İş Kazası/i }).click();
    await expect(kayitModal.getByTestId("surec-ilk-iki-gun-firma-oder-mi")).toHaveCount(0);
  });
});
