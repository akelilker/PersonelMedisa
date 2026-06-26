import { expect, type Page } from "@playwright/test";

export type MainMenuRole =
  | "GENEL_YONETICI"
  | "BOLUM_YONETICISI"
  | "MUHASEBE"
  | "BIRIM_AMIRI";

const ROLE_MENU_EXPECTATIONS: Record<
  MainMenuRole,
  { visible: string[]; hidden: string[]; kayitEnabled: boolean }
> = {
  GENEL_YONETICI: {
    visible: [
      "menu-kayit-surec",
      "menu-personel-karti",
      "menu-raporlar",
      "menu-puantaj",
      "menu-finans",
      "menu-gunluk-kayit",
      "menu-yonetim-paneli"
    ],
    hidden: [],
    kayitEnabled: true
  },
  BOLUM_YONETICISI: {
    visible: [
      "menu-kayit-surec",
      "menu-personel-karti",
      "menu-raporlar",
      "menu-puantaj",
      "menu-finans",
      "menu-gunluk-kayit"
    ],
    hidden: ["menu-yonetim-paneli"],
    kayitEnabled: true
  },
  MUHASEBE: {
    visible: [
      "menu-kayit-surec",
      "menu-personel-karti",
      "menu-raporlar",
      "menu-puantaj",
      "menu-finans",
      "menu-gunluk-kayit"
    ],
    hidden: ["menu-yonetim-paneli"],
    kayitEnabled: true
  },
  BIRIM_AMIRI: {
    visible: [
      "menu-kayit-surec",
      "menu-personel-karti",
      "menu-raporlar",
      "menu-puantaj",
      "menu-gunluk-kayit"
    ],
    hidden: ["menu-finans", "menu-yonetim-paneli"],
    kayitEnabled: false
  }
};

export async function expectMainMenuForRole(page: Page, role: MainMenuRole) {
  const expectations = ROLE_MENU_EXPECTATIONS[role];

  await expect(page.getByTestId("dashboard-page")).toBeVisible();
  await expect(page.getByTestId("dashboard-kpi-grid")).toBeVisible();
  await expect(page.locator("#main-menu .menu-btn")).toHaveCount(expectations.visible.length);

  for (const testId of expectations.visible) {
    await expect(page.getByTestId(testId)).toBeVisible();
  }

  for (const testId of expectations.hidden) {
    await expect(page.getByTestId(testId)).toHaveCount(0);
  }

  if (expectations.kayitEnabled) {
    await expect(page.getByTestId("menu-kayit-surec")).toBeEnabled();
  } else {
    await expect(page.getByTestId("menu-kayit-surec")).toBeDisabled();
  }
}
