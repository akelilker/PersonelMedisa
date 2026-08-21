import { expect, test, type Locator, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

async function openKayitModal(page: Page) {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container--kayit-surec").last();
  await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  return kayitModal;
}

async function assertNoHorizontalOverflow(page: Page, kayitModal: Locator) {
  const metrics = await page.evaluate(() => {
    const doc = document.documentElement;
    return {
      docScrollWidth: doc.scrollWidth,
      viewportWidth: window.innerWidth
    };
  });
  expect(metrics.docScrollWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);

  const modalBox = await kayitModal.boundingBox();
  expect(modalBox).not.toBeNull();
  if (modalBox) {
    expect(modalBox.x).toBeGreaterThanOrEqual(-1);
    expect(modalBox.x + modalBox.width).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  }
}

async function assertFlowActionsScrollWithContent(kayitModal: Locator) {
  const header = kayitModal.locator(".modal-header");
  const tabs = kayitModal.locator(".kayit-workspace-tabs");
  const footer = kayitModal.getByTestId("kayit-modal-footer");
  const modalBody = kayitModal.locator(".modal-body--kayit-surec");

  await expect(header).toBeVisible();
  await expect(tabs).toBeVisible();
  await expect(footer).toBeVisible();
  await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();

  const flowDom = await kayitModal.evaluate((modal) => {
    const body = modal.querySelector(".modal-body--kayit-surec");
    const flowFooter = modal.querySelector(".modal-footer.modal-footer--flow");
    const fixedSibling = Array.from(modal.children).find(
      (el) => el.classList.contains("modal-footer") && !el.classList.contains("modal-footer--flow")
    );
    const borderTop = flowFooter ? getComputedStyle(flowFooter).borderTopWidth : null;
    return {
      footerInBody: Boolean(body && flowFooter && body.contains(flowFooter)),
      hasFixedSiblingFooter: Boolean(fixedSibling),
      borderTopWidth: borderTop
    };
  });

  expect(flowDom.footerInBody).toBe(true);
  expect(flowDom.hasFixedSiblingFooter).toBe(false);
  expect(flowDom.borderTopWidth === "0px" || flowDom.borderTopWidth === "0").toBeTruthy();

  const before = await Promise.all([header.boundingBox(), tabs.boundingBox(), footer.boundingBox()]);
  expect(before[0]).not.toBeNull();
  expect(before[1]).not.toBeNull();
  expect(before[2]).not.toBeNull();

  const scrolled = await modalBody.evaluate((el) => {
    const canScroll = el.scrollHeight > el.clientHeight + 2;
    if (canScroll) {
      el.scrollTop = Math.min(el.scrollHeight - el.clientHeight, 160);
    }
    return {
      canScroll,
      scrollTop: el.scrollTop,
      overflowY: getComputedStyle(el).overflowY
    };
  });

  expect(scrolled.overflowY).toMatch(/auto|scroll/);

  if (scrolled.canScroll) {
    expect(scrolled.scrollTop).toBeGreaterThan(0);
    const after = await Promise.all([header.boundingBox(), footer.boundingBox()]);
    expect(Math.abs((after[0]?.y ?? 0) - (before[0]?.y ?? 0))).toBeLessThanOrEqual(2);
    expect((after[1]?.y ?? 0) + 1).toBeLessThan(before[2]?.y ?? 0);
  }
}

test.describe("I2 Kayit modal viewport layout", () => {
  test("Kayıt modalı flow actions ile body scroll kullanır", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openKayitModal(page);
    await expect(kayitModal.getByTestId("kayit-tab-yeni-kayit")).toHaveAttribute("aria-selected", "true");
    await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toHaveAttribute(
      "form",
      "kayit-surec-personel-form"
    );
    await expect(kayitModal.getByRole("button", { name: "Kapat" })).toBeVisible();

    await assertFlowActionsScrollWithContent(kayitModal);
    await assertNoHorizontalOverflow(page, kayitModal);

    await expect(kayitModal.getByRole("link", { name: "Puantaj" })).toHaveCount(0);
    await expect(kayitModal.getByRole("link", { name: /Revizyon/i })).toHaveCount(0);
    await expect(kayitModal.getByText(/Modül menü/i)).toHaveCount(0);
  });

  test("Kayıt modalında body scroll eder ve footer submit formu tetikler", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openKayitModal(page);
    await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toHaveAttribute(
      "form",
      "kayit-surec-personel-form"
    );

    await kayitModal.locator('[name="create-tc"]').fill("19876543210");
    await kayitModal.locator('[name="create-ad"]').fill("Kayit");
    await kayitModal.locator('[name="create-soyad"]').fill("Layout");
    await kayitModal.locator('[name="create-dogum"]').fill("1991-04-12");
    await kayitModal.locator('[name="create-telefon"]').fill("05324445566");
    await kayitModal.locator('[name="create-acil-kisi"]').fill("Acil Kisi");
    await kayitModal.locator('[name="create-acil-tel"]').fill("05327778899");
    await kayitModal.locator('[name="create-sicil"]').fill("E2E-I2-01");
    await kayitModal.locator('[name="create-ise-giris"]').fill("2026-06-15");
    await kayitModal.getByRole("combobox", { name: "Departman" }).click();
    await kayitModal.getByRole("option", { name: "Döşeme" }).click();
    await kayitModal.getByRole("combobox", { name: "Unvan" }).click();
    await kayitModal.getByRole("option", { name: "Genel Müdür" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel Tipi" }).click();
    await kayitModal.getByRole("option", { name: "Tam Zamanlı" }).click();

    await kayitModal.getByTestId("kayit-modal-footer-primary").click();
    await expect(kayitModal.locator(".personel-create-error")).toContainText("Şube seçilmelidir.");

    const scrollOwnerCheck = await kayitModal.evaluate((modal) => {
      const body = modal.querySelector(".modal-body--kayit-surec") as HTMLElement | null;
      const scroll = modal.querySelector('[data-testid="kayit-workspace-scroll-body"]') as HTMLElement | null;
      const flowFooter = modal.querySelector(".modal-footer.modal-footer--flow") as HTMLElement | null;
      const field = modal.querySelector(".form-input") as HTMLElement | null;
      return {
        bodyOverflowY: body ? getComputedStyle(body).overflowY : null,
        scrollOverflowY: scroll ? getComputedStyle(scroll).overflowY : null,
        containerOverflow: getComputedStyle(modal).overflow,
        footerInBody: Boolean(body && flowFooter && body.contains(flowFooter)),
        fieldBackground: field ? getComputedStyle(field).backgroundColor : null
      };
    });

    expect(scrollOwnerCheck.bodyOverflowY).toMatch(/auto|scroll/);
    expect(scrollOwnerCheck.scrollOverflowY).toMatch(/visible/);
    expect(scrollOwnerCheck.containerOverflow).toMatch(/hidden|clip/);
    expect(scrollOwnerCheck.footerInBody).toBe(true);
    // --bg-field #0f1418 => rgb(15, 20, 24)
    expect(scrollOwnerCheck.fieldBackground).toBe("rgb(15, 20, 24)");
  });

  test("Süreç sekmesinde footer flow ve form association korunur", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openKayitModal(page);
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await expect(kayitModal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");

    await expect(kayitModal.getByTestId("kayit-modal-footer")).toHaveCount(0);

    await kayitModal.getByRole("combobox", { name: "Personel" }).click();
    await kayitModal.getByPlaceholder("Personel ara").fill("Ayşe");
    await kayitModal.getByRole("option", { name: /Ayşe Yılmaz/i }).click();

    await kayitModal.getByRole("tab", { name: "Ayrılma" }).click();
    await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toHaveAttribute(
      "form",
      "kayit-surec-surec-form"
    );
    await expect(kayitModal.getByTestId("kayit-workspace-tabs")).toBeVisible();

    const surecFlow = await kayitModal.evaluate((modal) => {
      const body = modal.querySelector(".modal-body--kayit-surec");
      const flowFooter = modal.querySelector(".modal-footer.modal-footer--flow");
      return Boolean(body && flowFooter && body.contains(flowFooter));
    });
    expect(surecFlow).toBe(true);
  });

  for (const viewport of [
    { width: 390, height: 844 },
    { width: 360, height: 800 },
    { width: 320, height: 720 }
  ] as const) {
    test(`Kayıt modalı ${viewport.width}x${viewport.height} viewport'ta flow actions erişilebilir`, async ({
      page
    }) => {
      await page.setViewportSize(viewport);
      await mockApi(page, "GENEL_YONETICI");
      await login(page, { username: "yonetici", password: "secret" });

      const kayitModal = await openKayitModal(page);
      await expect(kayitModal.getByTestId("kayit-tab-yeni-kayit")).toBeVisible();
      await expect(kayitModal.getByTestId("kayit-tab-surec")).toBeVisible();
      await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();
      await expect(kayitModal.getByRole("button", { name: "Kapat" })).toBeVisible();

      await assertNoHorizontalOverflow(page, kayitModal);

      const modalBody = kayitModal.locator(".modal-body--kayit-surec");
      await modalBody.evaluate((el) => {
        el.scrollTop = el.scrollHeight;
      });
      await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();

      const flowOk = await kayitModal.evaluate((modal) => {
        const body = modal.querySelector(".modal-body--kayit-surec");
        const flow = modal.querySelector(".modal-footer.modal-footer--flow");
        return Boolean(body && flow && body.contains(flow));
      });
      expect(flowOk).toBe(true);
    });
  }

  test("Personel Kartı modalı Kayıt layout değişikliğinden etkilenmez", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    await page.getByTestId("menu-personel-karti").click();
    const personelModal = page.getByRole("dialog", { name: "Personel Kartı" });
    await expect(personelModal).toBeVisible();
    await expect(personelModal.locator(".modal-header")).toBeVisible();
    await expect(personelModal.getByRole("heading", { name: "Personeller" })).toBeVisible();
    await expect(personelModal.getByRole("button", { name: "Kapat" })).toBeVisible();
    await expect(personelModal.getByTestId("kayit-workspace-scroll-body")).toHaveCount(0);
    await expect(personelModal.locator(".modal-footer--flow")).toHaveCount(0);
  });
});
