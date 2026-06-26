import { expect, type Page } from "@playwright/test";

export type MainMenuRole =
  | "GENEL_YONETICI"
  | "BOLUM_YONETICISI"
  | "MUHASEBE"
  | "BIRIM_AMIRI";

const ROLE_KAYIT_ENABLED: Record<MainMenuRole, boolean> = {
  GENEL_YONETICI: true,
  BOLUM_YONETICISI: true,
  MUHASEBE: true,
  BIRIM_AMIRI: false
};

export async function expectThreeButtonMainMenu(page: Page, kayitEnabled: boolean) {
  await expect(page.locator("#main-menu .menu-btn")).toHaveCount(3);
  await expect(page.getByTestId("menu-kayit-surec")).toBeVisible();
  if (kayitEnabled) {
    await expect(page.getByTestId("menu-kayit-surec")).toBeEnabled();
  } else {
    await expect(page.getByTestId("menu-kayit-surec")).toBeDisabled();
  }
  await expect(page.getByTestId("menu-personel-karti")).toBeVisible();
  await expect(page.getByTestId("menu-raporlar")).toBeVisible();
  await expect(page.getByTestId("menu-puantaj")).toHaveCount(0);
  await expect(page.getByTestId("menu-finans")).toHaveCount(0);
  await expect(page.getByTestId("menu-gunluk-kayit")).toHaveCount(0);
  await expect(page.getByTestId("menu-yonetim-paneli")).toHaveCount(0);
}

export async function expectMainMenuForRole(page: Page, role: MainMenuRole) {
  await expectThreeButtonMainMenu(page, ROLE_KAYIT_ENABLED[role]);
}
