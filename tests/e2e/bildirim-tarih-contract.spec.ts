import { expect, test, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

const SELECTED_OPERATION_DATE = "2026-05-15";

async function openFilledCreateModal(page: Page) {
  await mockApi(page, "BIRIM_AMIRI");
  await login(page, { username: "birim", password: "secret" });
  await page.goto("/bildirimler");
  await page.getByRole("button", { name: /Günlük Kayıt Gir|Yeni Günlük Kayıt/i }).click();

  const modal = page.locator(".modal-container").last();
  await expect(modal).toBeVisible();
  await modal.getByLabel("Tarih").fill(SELECTED_OPERATION_DATE);
  await modal.getByLabel("Personel").selectOption("1");
  await modal
    .getByRole("group", { name: "Kayıt Senaryosu" })
    .getByRole("button", { name: /Diğer|Dıger|Diger/i })
    .click();
  await modal
    .getByLabel("Not / Açıklama")
    .fill("S74-D1-D1 tarih kontratı regression kaydı");

  return modal;
}

test.describe("S74-D1-D1 günlük bildirim tarih kontratı", () => {
  test("seçilen geçmiş tarihi request, response, liste ve detail boyunca korur", async ({ page }) => {
    const modal = await openFilledCreateModal(page);
    const responsePromise = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname.endsWith("/api/bildirimler") &&
        response.request().method() === "POST"
    );

    await modal.getByRole("button", { name: "Kaydet" }).click();

    const response = await responsePromise;
    const requestBody = response.request().postDataJSON();
    const responseBody = (await response.json()) as { data?: { tarih?: string } };

    expect(requestBody).toMatchObject({ tarih: SELECTED_OPERATION_DATE });
    expect(response.status()).toBe(201);
    expect(responseBody.data?.tarih).toBe(SELECTED_OPERATION_DATE);

    const createdRow = page.locator(".bildirimler-item").first();
    await expect(createdRow).toContainText(`Tarih: ${SELECTED_OPERATION_DATE}`);
    await createdRow.getByRole("link", { name: "Detay" }).click();
    await expect(page).toHaveURL(/\/bildirimler\/\d+$/);
    await expect(page.getByText(`Tarih: ${SELECTED_OPERATION_DATE}`)).toBeVisible();
  });

  test("boş tarih açık validation verir ve POST üretmez", async ({ page }) => {
    const modal = await openFilledCreateModal(page);
    const description = "S74-D1-D1 tarih kontratı regression kaydı";
    await modal.getByLabel("Tarih").fill("");
    const dateInput = modal.getByLabel("Tarih");
    const createForm = modal.locator("form");
    await dateInput.evaluate((input) => {
      input.addEventListener(
        "invalid",
        () => input.setAttribute("data-native-invalid-fired", "true"),
        { once: true }
      );
    });
    await createForm.evaluate((form) => {
      form.addEventListener(
        "submit",
        () => form.setAttribute("data-native-submit-fired", "true"),
        { once: true }
      );
    });

    let createPostCount = 0;
    page.on("request", (request) => {
      if (
        new URL(request.url()).pathname.endsWith("/api/bildirimler") &&
        request.method() === "POST"
      ) {
        createPostCount += 1;
      }
    });

    await modal.getByRole("button", { name: "Kaydet" }).click();

    await expect(dateInput).toHaveAttribute("data-native-invalid-fired", "true");
    await expect(createForm).not.toHaveAttribute("data-native-submit-fired", "true");
    await expect(modal.getByText("Tarih zorunludur.")).toBeVisible();
    expect(createPostCount).toBe(0);
    await expect(modal).toBeVisible();
    await expect(modal.getByLabel("Tarih")).toHaveValue("");
    await expect(modal.getByLabel("Personel")).toHaveValue("1");
    await expect(
      modal
        .getByRole("group", { name: "Kayıt Senaryosu" })
        .getByRole("button", { name: /Diğer|Dıger|Diger/i })
    ).toHaveAttribute("aria-pressed", "true");
    await expect(modal.getByLabel("Not / Açıklama")).toHaveValue(description);
  });

  test("geçerli tarih seçildiğinde tarih hatasını yeni submit olmadan temizler", async ({ page }) => {
    const modal = await openFilledCreateModal(page);
    await modal.getByLabel("Tarih").fill("");

    let createPostCount = 0;
    page.on("request", (request) => {
      if (
        new URL(request.url()).pathname.endsWith("/api/bildirimler") &&
        request.method() === "POST"
      ) {
        createPostCount += 1;
      }
    });

    await modal.getByRole("button", { name: "Kaydet" }).click();
    await expect(modal.getByText("Tarih zorunludur.")).toBeVisible();

    await modal.getByLabel("Tarih").fill(SELECTED_OPERATION_DATE);

    await expect(modal.getByText("Tarih zorunludur.")).toHaveCount(0);
    await expect(modal.getByLabel("Tarih")).toHaveValue(SELECTED_OPERATION_DATE);
    expect(createPostCount).toBe(0);

    await modal.getByRole("button", { name: "Vazgeç" }).click();
    await page.getByRole("button", { name: /Günlük Kayıt Gir|Yeni Günlük Kayıt/i }).click();
    const reopenedModal = page.locator(".modal-container").last();
    await expect(reopenedModal.getByText("Tarih zorunludur.")).toHaveCount(0);
    await expect(reopenedModal.getByLabel("Tarih")).not.toHaveValue("");
    expect(createPostCount).toBe(0);
  });
});
