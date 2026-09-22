import { expect, test } from "@playwright/test";
import { login } from "./helpers/auth";
import { mockApi } from "./helpers/mock-api";

test.describe("Kayıt modal UI polish", () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 });
    await mockApi(page, "GENEL_YONETICI");
    await login(page, { username: "yonetici", password: "secret" });
  });

  test("Kayıt sekmesi: tab chrome, muted placeholder, saatlik default, mavi bulk link", async ({ page }) => {
    await page.getByTestId("menu-kayit-surec").click();
    const kayitModal = page.locator(".modal-container--kayit-surec").last();
    await expect(kayitModal.getByRole("heading", { name: /Kayıt ve Süreç İşlemleri/i })).toBeVisible();

    const tabChrome = await kayitModal.evaluate((modal) => {
      const tabs = modal.querySelector(".kayit-workspace-tabs") as HTMLElement | null;
      const activeTab = modal.querySelector(".kayit-workspace-tab.is-active") as HTMLElement | null;
      const link = modal.querySelector('[data-testid="kayit-bulk-import-link"]') as HTMLElement | null;

      const tabsStyle = tabs ? getComputedStyle(tabs) : null;
      const activeStyle = activeTab ? getComputedStyle(activeTab) : null;
      const linkStyle = link ? getComputedStyle(link) : null;

      return {
        tabsBorderBottom: tabsStyle?.borderBottomWidth ?? null,
        activeBorderColor: activeStyle?.borderBottomColor ?? null,
        linkColor: linkStyle?.color ?? null
      };
    });

    expect(tabChrome.tabsBorderBottom).toBe("0px");
    expect(tabChrome.activeBorderColor).toMatch(/rgb\(224, 0, 0\)|rgba\(224, 0, 0/);
    expect(tabChrome.linkColor).toBe("rgb(96, 165, 250)");

    const ucretTipi = await kayitModal.getByRole("combobox", { name: "Ücret Tipi" }).innerText();
    expect(ucretTipi).toContain("Saatlik");

    await expect(kayitModal.getByTestId("kayit-bulk-import-link")).toHaveText("Tıklayınız.");
  });

  test("Toplu Kayıt Aktarma: back bar, no rule, centered action grid", async ({ page }) => {
    await page.getByTestId("menu-kayit-surec").click();
    await page.getByTestId("kayit-bulk-import-link").click();
    await expect(page.getByTestId("personel-import-dry-run-title")).toContainText("Toplu Kayıt Aktarma");

    const importModal = page.locator(".personel-import-dry-run-modal").last();
    const layout = await importModal.evaluate((modal) => {
      const backBar = modal.querySelector(".personel-import-back-bar") as HTMLElement | null;
      const stage = modal.querySelector(".personel-import-stage") as HTMLElement | null;
      const actions = modal.querySelector(".personel-import-dry-run-actions") as HTMLElement | null;
      const backStyle = backBar ? getComputedStyle(backBar) : null;
      const stageStyle = stage ? getComputedStyle(stage) : null;

      if (!stage || !actions) {
        return { ok: false as const };
      }

      const stageBox = stage.getBoundingClientRect();
      const actionsBox = actions.getBoundingClientRect();
      const verticalDelta =
        (actionsBox.top + actionsBox.height / 2) - (stageBox.top + stageBox.height / 2);

      return {
        ok: true as const,
        backBorderBottom: backStyle?.borderBottomWidth ?? null,
        stageJustify: stageStyle?.justifyContent ?? null,
        verticalDelta
      };
    });

    expect(layout.ok).toBe(true);
    if (layout.ok) {
      expect(layout.backBorderBottom).toBe("0px");
      expect(layout.stageJustify).toBe("center");
      expect(Math.abs(layout.verticalDelta)).toBeLessThan(48);
    }

    await expect(importModal.getByTestId("personel-import-back-kayit")).toBeVisible();
    await expect(importModal.getByTestId("personel-import-template-download")).toBeVisible();
  });
});
