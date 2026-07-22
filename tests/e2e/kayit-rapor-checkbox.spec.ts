import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayit Surec hastalik raporu tri-state", () => {
  test("hastalikta belirlenmedi/evet/hayir korunur; is kazasinda alan gorunmez", async ({ page }) => {
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

    const policySelect = kayitModal.getByLabel("İlk 2 gün firma tarafından ödenecek mi?");
    await expect(policySelect).toBeVisible();
    await expect(policySelect).toHaveValue("belirsiz");
    await policySelect.selectOption("evet");
    await expect(policySelect).toHaveValue("evet");
    await policySelect.selectOption("hayir");
    await expect(policySelect).toHaveValue("hayir");

    await kayitModal.getByRole("button", { name: /İş Kazası/i }).click();
    await expect(kayitModal.getByLabel("İlk 2 gün firma tarafından ödenecek mi?")).toHaveCount(0);
  });
});
