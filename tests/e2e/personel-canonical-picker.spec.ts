import { mkdirSync } from "node:fs";
import { join } from "node:path";
import { expect, test, type Locator, type Page } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

/**
 * PERSONELMEDISA_GLOBAL_CANONICAL_PICKER_SYSTEM — kabul kanıtı.
 *
 * Kanonik owner: src/components/form/AppSelect.tsx (+ app-picker-layer.ts + styles/components/app-select.css)
 * Kontrat: picker + seçenek paneli NET, arka yüzey BLUR, tek owner, tek davranış.
 */

const OUT_DIR = "C:\\Users\\Akel\\Desktop\\PERSONEL-PICKER-KONTROL";
const PANEL = '[data-app-select-panel="1"]';

test.beforeAll(() => {
  mkdirSync(OUT_DIR, { recursive: true });
});

async function openKayitModal(page: Page): Promise<Locator> {
  await page.getByTestId("menu-kayit-surec").click();
  const kayitModal = page.locator(".modal-container--kayit-surec").last();
  await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();
  return kayitModal;
}

async function openPicker(page: Page, combobox: Locator): Promise<Locator> {
  await combobox.click();
  const panel = page.locator(PANEL);
  await expect(panel).toBeVisible();
  await expect(page.locator("body")).toHaveAttribute("data-app-picker-open", "1");
  return panel;
}

async function expectBlurApplied(blurred: Locator): Promise<void> {
  // Blur geçişi 180ms; hesaplanan değer geçiş bitene kadar kesirli olabilir.
  await expect
    .poll(() => blurred.evaluate((element) => getComputedStyle(element).filter), {
      message: "Blur kontratı uygulanmadı",
      timeout: 5_000
    })
    .toContain("blur(2px)");
}

async function expectPickerIsSharp(page: Page) {
  const openFrame = page.locator(".app-select.is-open");
  await expect(openFrame).not.toHaveClass(/app-picker-blurred/);
  const fieldSection = openFrame.locator("xpath=ancestor::div[contains(@class,'form-section')][1]");
  await expect(fieldSection).not.toHaveClass(/app-picker-blurred/);

  // Alanın hiçbir atası blur almaz: seçim alanı gerçekten net kalır.
  const blurredAncestors = await openFrame.locator("select").evaluate((element) => {
    const found: string[] = [];
    let node: HTMLElement | null = element.parentElement;
    while (node) {
      if (node.classList.contains("app-picker-blurred")) {
        found.push(node.className);
      }
      node = node.parentElement;
    }
    return found;
  });
  expect(blurredAncestors).toEqual([]);

  expect(await page.locator(PANEL).evaluate((element) => getComputedStyle(element).filter)).toBe("none");
}

async function expectBlurReleased(page: Page) {
  await expect(page.locator("body")).not.toHaveAttribute("data-app-picker-open", "1");
  expect(await page.locator(".app-picker-blurred").count()).toBe(0);
  await expect(page.locator(PANEL)).toHaveCount(0);
}

test.describe("kanonik picker (Kayıt ve Süreç + global)", () => {
  test("A) Çalışan Kapsamı: blur kontratı, seçenek kartları ve seçim", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openKayitModal(page);

    const combobox = kayitModal.getByRole("combobox", { name: "Çalışan Kapsamı" });
    await expect(combobox).toBeVisible();
    const panel = await openPicker(page, combobox);

    // Blur: arka yüzey bulanır, picker + alanı net kalır.
    await expectBlurApplied(kayitModal.locator(".modal-header"));
    expect(await page.locator(".app-picker-blurred").count()).toBeGreaterThan(0);
    await expect(kayitModal.locator(".modal-header")).toHaveClass(/app-picker-blurred/);
    await expectPickerIsSharp(page);

    // Seçenek kartı kontratı: ayrı ince çerçeveli kartlar + kontrollü gap + radius.
    const card = await panel.locator(".app-select-option").first().evaluate((element) => {
      const style = getComputedStyle(element);
      const parentStyle = getComputedStyle(element.parentElement as Element);
      return {
        borderWidth: style.borderTopWidth,
        borderStyle: style.borderTopStyle,
        radius: style.borderTopLeftRadius,
        background: style.backgroundColor,
        gap: parentStyle.rowGap,
        optionCount: (element.parentElement as Element).querySelectorAll(".app-select-option").length
      };
    });
    expect(card.borderWidth).toBe("1px");
    expect(card.borderStyle).toBe("solid");
    expect(card.radius).toBe("6px");
    expect(card.gap).toBe("4px");
    expect(card.background).not.toBe("rgba(0, 0, 0, 0)");
    expect(card.optionCount).toBe(2);

    // Chevron metin karakteri değil gerçek ikon; aktif alan kırmızı kenarlık alır.
    await expect(page.locator(".app-select.is-open .app-select-chevron path")).toHaveCount(1);
    const activeBorder = await page
      .locator(".app-select.is-open .app-select-trigger")
      .evaluate((element) => getComputedStyle(element).borderTopColor);
    expect(activeBorder).toContain("224, 0, 0");

    await page.screenshot({ path: join(OUT_DIR, "01-1280-KAYIT-CALISAN-KAPSAMI.png") });

    await panel.getByRole("option", { name: "Harici Personel" }).click();
    await expectBlurReleased(page);
    await expect(page.locator("#create-calisan-kapsami")).toHaveValue("DIS_KAYNAK");
  });

  test("B) Şube: kanonik picker + net alan", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openKayitModal(page);

    const combobox = kayitModal.getByRole("combobox", { name: "Şube" });
    const panel = await openPicker(page, combobox);
    await expectPickerIsSharp(page);
    await expect(panel.getByRole("option", { name: "Merkez" })).toBeVisible();

    await page.screenshot({ path: join(OUT_DIR, "02-1280-KAYIT-SUBE.png") });

    await panel.getByRole("option", { name: "Depolama" }).click();
    await expectBlurReleased(page);
    await expect(page.locator("#create-sube")).not.toHaveValue("");
  });

  test("E) Görev / Unvan: kanonik picker (uzun liste geometry)", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openKayitModal(page);

    const combobox = kayitModal.getByRole("combobox", { name: "Unvan" });
    const panel = await openPicker(page, combobox);
    await expectPickerIsSharp(page);

    await page.screenshot({ path: join(OUT_DIR, "03-1280-KAYIT-GOREV.png") });

    const metrics = await panel.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      return {
        top: rect.top,
        bottom: rect.bottom,
        maxHeight: Number.parseFloat(style.maxHeight),
        overflowY: style.overflowY,
        scrollHeight: element.scrollHeight,
        clientHeight: element.clientHeight,
        viewportHeight: window.innerHeight,
        clippedOptions: Array.from(element.querySelectorAll(".app-select-option")).filter(
          (option) =>
            (option as HTMLElement).offsetTop + (option as HTMLElement).offsetHeight >
            element.scrollHeight + 1
        ).length
      };
    });

    expect(metrics.top).toBeGreaterThanOrEqual(0);
    expect(metrics.bottom).toBeLessThanOrEqual(metrics.viewportHeight + 0.5);
    expect(metrics.overflowY).toBe("auto");
    expect(metrics.maxHeight).toBeLessThanOrEqual(320);
    expect(metrics.clippedOptions).toBe(0);

    if (metrics.scrollHeight > metrics.clientHeight) {
      const scrolled = await panel.evaluate((element) => {
        element.scrollTop = element.scrollHeight;
        return element.scrollTop;
      });
      expect(scrolled).toBeGreaterThan(0);
    }

    await page.keyboard.press("Escape");
    await expectBlurReleased(page);
  });

  test("etkileşim kontratı: outside click, ESC ve klavye", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openKayitModal(page);
    const combobox = kayitModal.getByRole("combobox", { name: "Şube" });

    // outside click: picker kapanır, modal açık kalır
    await openPicker(page, combobox);
    await page.mouse.click(4, 4);
    await expectBlurReleased(page);
    await expect(kayitModal).toBeVisible();

    // ESC: picker kapanır, modal kapanmaz
    await openPicker(page, combobox);
    await page.keyboard.press("Escape");
    await expectBlurReleased(page);
    await expect(kayitModal).toBeVisible();

    // klavye: ArrowDown ile aç, ArrowDown + Enter ile seç
    await combobox.focus();
    await page.keyboard.press("ArrowDown");
    await expect(page.locator(PANEL)).toBeVisible();
    await page.keyboard.press("ArrowDown");
    await page.keyboard.press("Enter");
    await expectBlurReleased(page);
    await expect(page.locator("#create-sube")).not.toHaveValue("");
  });

  for (const viewport of [
    { width: 390, height: 844, shot: "04-390-KAYIT-PICKER.png" },
    { width: 430, height: 932, shot: "05-430-KAYIT-PICKER.png" }
  ]) {
    test(`mobil ${viewport.width}: picker viewport içinde ve net`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await mockApi(page, "GENEL_YONETICI");
      await login(page, { username: "yonetici", password: "secret" });
      const kayitModal = await openKayitModal(page);

      const combobox = kayitModal.getByRole("combobox", { name: "Şube" });
      const panel = await openPicker(page, combobox);
      await expectPickerIsSharp(page);

      const geometry = await panel.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        const option = element.querySelector(".app-select-option") as HTMLElement | null;
        const trigger = document.querySelector(".app-select.is-open .app-select-trigger") as HTMLElement | null;
        return {
          left: rect.left,
          right: rect.right,
          top: rect.top,
          bottom: rect.bottom,
          width: rect.width,
          viewportWidth: window.innerWidth,
          viewportHeight: window.innerHeight,
          optionHeight: option ? option.getBoundingClientRect().height : 0,
          triggerHeight: trigger ? trigger.getBoundingClientRect().height : 0
        };
      });

      expect(geometry.left).toBeGreaterThanOrEqual(0);
      expect(geometry.right).toBeLessThanOrEqual(geometry.viewportWidth + 0.5);
      expect(geometry.top).toBeGreaterThanOrEqual(0);
      expect(geometry.bottom).toBeLessThanOrEqual(geometry.viewportHeight + 0.5);
      expect(geometry.width).toBeGreaterThan(120);
      expect(geometry.optionHeight).toBeGreaterThanOrEqual(32);
      expect(geometry.triggerHeight).toBeGreaterThanOrEqual(24);

      await expectBlurApplied(kayitModal.locator(".modal-header"));

      await page.screenshot({ path: join(OUT_DIR, viewport.shot) });

      await panel.getByRole("option", { name: "Merkez" }).click();
      await expectBlurReleased(page);
    });
  }

  test("native select otomasyon kontratı: selectOption React state'ini besler", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const kayitModal = await openKayitModal(page);

    const select = page.locator("#create-sube");
    await expect(select).toHaveCount(1);
    const optionLabel = (await select.locator("option").nth(1).textContent())?.trim() ?? "";
    expect(optionLabel.length).toBeGreaterThan(0);

    await select.selectOption({ index: 1 });

    // Trigger metni React state'inden üretilir: selectOption state'i besliyorsa etiket değişir.
    await expect(page.locator("div.form-section:has(select#create-sube) .app-select-trigger-text")).toHaveText(optionLabel);

    // Blur kontratı bu yolda da tetiklenmez (panel hiç açılmadı).
    await expect(page.locator("body")).not.toHaveAttribute("data-app-picker-open", "1");
  });

  test("F) global owner: Personel Kartı / Belge Takip filtreleri", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    await page.goto("/personeller/belge-takip");

    const modal = page.locator(".modal-container").last();
    const combobox = modal.getByRole("combobox", { name: "Departman" });
    await expect(combobox).toBeVisible();
    expect(await combobox.evaluate((element) => element.getAttribute("name"))).toBe("belge-takip-departman");

    const panel = await openPicker(page, combobox);
    await expectPickerIsSharp(page);

    await expectBlurApplied(modal.locator(".modal-header"));
    expect(await page.locator(".app-picker-blurred").count()).toBeGreaterThan(0);

    await page.screenshot({ path: join(OUT_DIR, "06-GLOBAL-SECOND-FEATURE.png") });

    await page.keyboard.press("Escape");
    await expectBlurReleased(page);
  });
});

test.describe("Süreç personel bağlam seçici (searchable canonical picker)", () => {
  async function openSurecPersonelPicker(page: Page) {
    const kayitModal = await openKayitModal(page);
    await kayitModal.getByTestId("kayit-tab-surec").click();
    const combobox = kayitModal.getByRole("combobox", { name: "Personel" });
    await expect(combobox).toBeVisible();
    return { kayitModal, combobox };
  }

  test("07/08) 1280: searchable picker, filtre, value contract ve seçim", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const { kayitModal, combobox } = await openSurecPersonelPicker(page);

    await kayitModal.getByTestId("kayit-surec-personel-search-toggle").click();
    const panel = page.locator(PANEL);
    await expect(panel).toBeVisible();
    await expect(page.locator("body")).toHaveAttribute("data-app-picker-open", "1");
    await expectPickerIsSharp(page);
    await expectBlurApplied(kayitModal.locator(".modal-header"));

    const search = page.getByTestId("kayit-surec-personel-panel-search");
    await expect(search).toBeVisible();
    await expect(search).toBeFocused();

    await expect(search).toHaveAttribute("placeholder", "Ad/Soyad Veya Sicil No. Girin.");
    await search.fill("Ayşe");

    await expect(page.getByTestId("kayit-surec-personel-search-input")).toHaveCount(0);
    await expect(panel.getByRole("option")).toHaveCount(1);
    await expect(panel.getByRole("option", { name: /Ayşe Yılmaz/ })).toBeVisible();
    await expect(panel.getByRole("option", { name: /Mehmet/i })).toHaveCount(0);

    await page.screenshot({ path: join(OUT_DIR, "07-1280-SUREC-PERSONEL-SEARCH.png") });

    await panel.getByRole("option", { name: /Ayşe Yılmaz/ }).click();

    await expect(page.locator("[name='surec-create-personel']")).toHaveValue("1");
    await expect(page.locator(PANEL)).toHaveCount(0);
    expect(await page.locator(".app-picker-blurred").count()).toBe(0);
    await expect(kayitModal.getByTestId("kayit-surec-personel-ad-soyad")).toContainText("Ayşe YILMAZ");

    await page.screenshot({ path: join(OUT_DIR, "08-1280-SUREC-PERSONEL-SELECTED.png") });
  });

  test("etkileşim: ESC, outside click, klavye ve panel geometry", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
    const { kayitModal, combobox } = await openSurecPersonelPicker(page);
    const search = page.getByTestId("kayit-surec-personel-panel-search");

    // ESC: picker kapanır, modal kapanmaz
    await openPicker(page, combobox);
    await search.press("Escape");
    await expectBlurReleased(page);
    await expect(kayitModal).toBeVisible();

    // outside click
    await openPicker(page, combobox);
    await page.mouse.click(4, 4);
    await expectBlurReleased(page);
    await expect(kayitModal).toBeVisible();

    // panel geometry + kırpılma yok
    await openPicker(page, combobox);
    const panel = page.locator(PANEL);
    const metrics = await panel.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const style = getComputedStyle(element);
      return {
        bottom: rect.bottom,
        overflowY: style.overflowY,
        maxHeight: Number.parseFloat(style.maxHeight),
        viewportHeight: window.innerHeight,
        clipped: Array.from(element.querySelectorAll(".app-select-option")).filter(
          (option) =>
            (option as HTMLElement).offsetTop + (option as HTMLElement).offsetHeight >
            element.scrollHeight + 1
        ).length
      };
    });
    expect(metrics.overflowY).toBe("auto");
    expect(metrics.maxHeight).toBeLessThanOrEqual(320);
    expect(metrics.bottom).toBeLessThanOrEqual(metrics.viewportHeight + 0.5);
    expect(metrics.clipped).toBe(0);

    // klavye: ArrowDown + Enter ile seçim
    await search.press("ArrowDown");
    await search.press("Enter");
    await expect(page.locator("[name='surec-create-personel']")).not.toHaveValue("");
    await expect(panel).toHaveCount(0);
  });

  for (const viewport of [
    { width: 390, height: 844, shot: "09-390-SUREC-PERSONEL.png" },
    { width: 430, height: 932, shot: "10-430-SUREC-PERSONEL.png" }
  ]) {
    test(`mobil ${viewport.width}: searchable picker viewport içinde ve net`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await mockApi(page, "GENEL_YONETICI");
      await login(page, { username: "yonetici", password: "secret" });
      const { kayitModal, combobox } = await openSurecPersonelPicker(page);

      const panel = await openPicker(page, combobox);
      await expectPickerIsSharp(page);
      await expectBlurApplied(kayitModal.locator(".modal-header"));

      const search = page.getByTestId("kayit-surec-personel-panel-search");
      await expect(search).toBeVisible();
      await search.fill("Ayşe");
      await expect(panel.getByRole("option", { name: /Ayşe Yılmaz/ })).toBeVisible();

      const geometry = await panel.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        const option = element.querySelector(".app-select-option") as HTMLElement | null;
        // Arama tek input olarak trigger'ın yerinde (searchInTrigger): panelde ikinci input yok.
        const searchInput = document.querySelector("[data-testid='kayit-surec-personel-panel-search']") as HTMLElement | null;
        return {
          left: rect.left,
          right: rect.right,
          top: rect.top,
          bottom: rect.bottom,
          viewportWidth: window.innerWidth,
          viewportHeight: window.innerHeight,
          optionHeight: option ? option.getBoundingClientRect().height : 0,
          searchHeight: searchInput ? searchInput.getBoundingClientRect().height : 0
        };
      });

      expect(geometry.left).toBeGreaterThanOrEqual(0);
      expect(geometry.right).toBeLessThanOrEqual(geometry.viewportWidth + 0.5);
      expect(geometry.top).toBeGreaterThanOrEqual(0);
      expect(geometry.bottom).toBeLessThanOrEqual(geometry.viewportHeight + 0.5);
      expect(geometry.optionHeight).toBeGreaterThanOrEqual(32);
      expect(geometry.searchHeight).toBeGreaterThanOrEqual(24);

      await page.screenshot({ path: join(OUT_DIR, viewport.shot) });

      await panel.getByRole("option", { name: /Ayşe Yılmaz/ }).click();
      await expect(page.locator("[name='surec-create-personel']")).toHaveValue("1");
      await expectBlurReleased(page);
    });
  }
});
