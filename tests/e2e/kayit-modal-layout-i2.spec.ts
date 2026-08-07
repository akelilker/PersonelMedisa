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

async function assertFixedChromeWhileScrolling(kayitModal: Locator) {
  const header = kayitModal.locator(".modal-header");
  const tabs = kayitModal.locator(".kayit-workspace-tabs");
  const footer = kayitModal.getByTestId("kayit-modal-footer");
  const scrollBody = kayitModal.getByTestId("kayit-workspace-scroll-body");

  await expect(header).toBeVisible();
  await expect(tabs).toBeVisible();
  await expect(footer).toBeVisible();
  await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();

  const before = await Promise.all([header.boundingBox(), tabs.boundingBox(), footer.boundingBox()]);
  expect(before[0]).not.toBeNull();
  expect(before[1]).not.toBeNull();
  expect(before[2]).not.toBeNull();

  const scrolled = await scrollBody.evaluate((el) => {
    const canScroll = el.scrollHeight > el.clientHeight + 2;
    if (canScroll) {
      el.scrollTop = Math.min(el.scrollHeight - el.clientHeight, 120);
    }
    return {
      canScroll,
      scrollTop: el.scrollTop,
      scrollHeight: el.scrollHeight,
      clientHeight: el.clientHeight,
      modalBodyOverflowY: (() => {
        const body = el.closest(".modal-body");
        return body ? getComputedStyle(body).overflowY : null;
      })()
    };
  });

  expect(scrolled.modalBodyOverflowY === "hidden" || scrolled.modalBodyOverflowY === "clip").toBeTruthy();

  if (scrolled.canScroll) {
    expect(scrolled.scrollTop).toBeGreaterThan(0);
    const after = await Promise.all([header.boundingBox(), tabs.boundingBox(), footer.boundingBox()]);
    expect(Math.abs((after[0]?.y ?? 0) - (before[0]?.y ?? 0))).toBeLessThanOrEqual(2);
    expect(Math.abs((after[1]?.y ?? 0) - (before[1]?.y ?? 0))).toBeLessThanOrEqual(2);
    expect(Math.abs((after[2]?.y ?? 0) - (before[2]?.y ?? 0))).toBeLessThanOrEqual(2);
  }
}

test.describe("I2 Kayit modal viewport layout", () => {
  test("Kayıt modalı 1366x768'de header tabs ve footer'ı sabit tutar", async ({ page }) => {
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

    await assertFixedChromeWhileScrolling(kayitModal);
    await assertNoHorizontalOverflow(page, kayitModal);

    // Package 1 regression: no local module bridges inside kayit surface.
    await expect(kayitModal.getByRole("link", { name: "Puantaj" })).toHaveCount(0);
    await expect(kayitModal.getByRole("link", { name: /Revizyon/i })).toHaveCount(0);
    await expect(kayitModal.getByText(/Modül menü/i)).toHaveCount(0);
  });

  test("Kayıt modalında yalnız form body scroll eder ve footer submit formu tetikler", async ({ page }) => {
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
    await kayitModal.getByRole("combobox", { name: "Bölüm" }).click();
    await kayitModal.getByRole("option", { name: "Döşeme" }).click();
    await kayitModal.getByRole("combobox", { name: "Görev / Unvan" }).click();
    await kayitModal.getByRole("option", { name: "Genel Müdür" }).click();
    await kayitModal.getByRole("combobox", { name: "Personel Tipi" }).click();
    await kayitModal.getByRole("option", { name: "Tam Zamanlı" }).click();

    await kayitModal.getByTestId("kayit-modal-footer-primary").click();
    await expect(kayitModal.locator(".personel-create-error")).toContainText("Şube seçilmelidir.");

    const scrollOwnerCheck = await kayitModal.evaluate((modal) => {
      const body = modal.querySelector(".modal-body--kayit-surec") as HTMLElement | null;
      const scroll = modal.querySelector('[data-testid="kayit-workspace-scroll-body"]') as HTMLElement | null;
      return {
        bodyOverflowY: body ? getComputedStyle(body).overflowY : null,
        scrollOverflowY: scroll ? getComputedStyle(scroll).overflowY : null,
        containerOverflow: getComputedStyle(modal).overflow
      };
    });

    expect(scrollOwnerCheck.bodyOverflowY === "hidden" || scrollOwnerCheck.bodyOverflowY === "clip").toBeTruthy();
    expect(scrollOwnerCheck.scrollOverflowY).toMatch(/auto|scroll/);
    expect(scrollOwnerCheck.containerOverflow).toMatch(/hidden|clip/);
  });

  test("Süreç sekmesinde footer aktif süreç formuna bağlı kalır", async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openKayitModal(page);
    await kayitModal.getByTestId("kayit-tab-surec").click();
    await expect(kayitModal.getByTestId("kayit-tab-surec")).toHaveAttribute("aria-selected", "true");

    // Personel picker state: no primary footer until an actionable form is active.
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
  });

  test("Kayıt modalı 390x844 viewport'ta yatay taşma yapmaz", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });

    const kayitModal = await openKayitModal(page);
    await expect(kayitModal.getByTestId("kayit-tab-yeni-kayit")).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-tab-surec")).toBeVisible();
    await expect(kayitModal.getByTestId("kayit-modal-footer-primary")).toBeVisible();
    await expect(kayitModal.getByRole("button", { name: "Kapat" })).toBeVisible();

    await assertNoHorizontalOverflow(page, kayitModal);

    const footerBox = await kayitModal.getByTestId("kayit-modal-footer").boundingBox();
    const viewport = page.viewportSize();
    expect(footerBox).not.toBeNull();
    expect(viewport).not.toBeNull();
    if (footerBox && viewport) {
      expect(footerBox.y + footerBox.height).toBeLessThanOrEqual(viewport.height + 1);
      expect(footerBox.y).toBeGreaterThanOrEqual(0);
    }
  });

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
  });
});
