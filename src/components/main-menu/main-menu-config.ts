import type { AppPermission } from "../../lib/authorization/role-permissions";
import {
  PERSONELLER_LIST_ANY,
  ROUTE_PERMISSION
} from "../../lib/authorization/role-permissions";

export type MainMenuVisibilityMode = "hide" | "disabled";

export type MainMenuModalAction = "kayit-surec";

type MainMenuConfigBase = {
  label: string;
  testId: string;
  visibilityMode: MainMenuVisibilityMode;
};

export type MainMenuModalConfigItem = MainMenuConfigBase & {
  kind: "modal";
  modalAction: MainMenuModalAction;
  requiredAnyPermissions: AppPermission[];
  activePathPrefix: string;
};

export type MainMenuRouteConfigItem = MainMenuConfigBase & {
  kind: "route";
  route: string;
  requiredPermission?: AppPermission;
  requiredAnyPermissions?: AppPermission[];
};

export type MainMenuConfigItem = MainMenuModalConfigItem | MainMenuRouteConfigItem;

export const MAIN_MENU_ITEMS: readonly MainMenuConfigItem[] = [
  {
    label: "Kayıt ve Süreç",
    testId: "menu-kayit-surec",
    kind: "modal",
    modalAction: "kayit-surec",
    requiredAnyPermissions: ["personeller.create", "surecler.create"],
    visibilityMode: "disabled",
    activePathPrefix: "/surecler"
  },
  {
    label: "Personel Kartı",
    testId: "menu-personel-karti",
    kind: "route",
    route: "/personeller",
    requiredAnyPermissions: [...PERSONELLER_LIST_ANY],
    visibilityMode: "disabled"
  },
  {
    label: "Raporlar",
    testId: "menu-raporlar",
    kind: "route",
    route: "/raporlar",
    requiredPermission: ROUTE_PERMISSION.raporlarPage,
    visibilityMode: "disabled"
  },
  {
    label: "Puantaj",
    testId: "menu-puantaj",
    kind: "route",
    route: "/puantaj",
    requiredPermission: ROUTE_PERMISSION.puantajPage,
    visibilityMode: "hide"
  },
  {
    label: "Finans",
    testId: "menu-finans",
    kind: "route",
    route: "/finans",
    requiredPermission: ROUTE_PERMISSION.finansPage,
    visibilityMode: "hide"
  },
  {
    label: "Günlük Kayıt",
    testId: "menu-gunluk-kayit",
    kind: "route",
    route: "/bildirimler",
    requiredPermission: ROUTE_PERMISSION.bildirimlerPage,
    visibilityMode: "hide"
  },
  {
    label: "Yönetim Paneli",
    testId: "menu-yonetim-paneli",
    kind: "route",
    route: "/yonetim-paneli",
    requiredPermission: ROUTE_PERMISSION.yonetimPaneliPage,
    visibilityMode: "hide"
  }
] as const;
