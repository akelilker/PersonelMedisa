import { ROUTE_PERMISSION, type AppPermission } from "../authorization/role-permissions";

export type SecondaryModuleId =
  | "puantaj"
  | "gunluk-kayit"
  | "haftalik-kapanis"
  | "revizyon-merkezi"
  | "belge-takip"
  | "finans";

export type SecondaryModuleDef = {
  id: SecondaryModuleId;
  label: string;
  to: string;
  permission: AppPermission;
};

/** Canonical secondary modules; visibility is filtered via permission owner only. */
export const SECONDARY_MODULE_CATALOG: readonly SecondaryModuleDef[] = [
  {
    id: "puantaj",
    label: "Puantaj",
    to: "/puantaj",
    permission: ROUTE_PERMISSION.puantajPage
  },
  {
    id: "gunluk-kayit",
    label: "Günlük Kayıt",
    to: "/bildirimler",
    permission: ROUTE_PERMISSION.bildirimlerPage
  },
  {
    id: "haftalik-kapanis",
    label: "Haftalık Kapanış",
    to: "/haftalik-kapanis",
    permission: ROUTE_PERMISSION.haftalikKapanisPage
  },
  {
    id: "revizyon-merkezi",
    label: "Revizyon Merkezi",
    to: "/haftalik-kapanis/revizyonlar",
    permission: "revizyon.view"
  },
  {
    id: "belge-takip",
    label: "Belge Takip",
    to: "/personeller/belge-takip",
    permission: ROUTE_PERMISSION.personelDetail
  },
  {
    id: "finans",
    label: "Finans",
    to: "/finans",
    permission: ROUTE_PERMISSION.finansPage
  }
];

export function resolveSecondaryModules(
  hasPermission: (permission: AppPermission) => boolean
): SecondaryModuleDef[] {
  return SECONDARY_MODULE_CATALOG.filter((module) => hasPermission(module.permission));
}

function isRevizyonMerkeziPath(pathname: string): boolean {
  return (
    pathname === "/haftalik-kapanis/revizyonlar" ||
    pathname.startsWith("/haftalik-kapanis/revizyonlar/") ||
    pathname === "/haftalik-kapanis/corrections" ||
    pathname.startsWith("/haftalik-kapanis/corrections/")
  );
}

/**
 * Exact active-route helper: revizyonlar* and corrections* win over haftalik-kapanis prefix.
 */
export function isSecondaryModuleActive(pathname: string, moduleId: SecondaryModuleId): boolean {
  switch (moduleId) {
    case "puantaj":
      return pathname === "/puantaj" || pathname.startsWith("/puantaj/");
    case "gunluk-kayit":
      return pathname === "/bildirimler" || pathname.startsWith("/bildirimler/");
    case "haftalik-kapanis":
      if (isRevizyonMerkeziPath(pathname)) {
        return false;
      }
      return pathname === "/haftalik-kapanis" || pathname.startsWith("/haftalik-kapanis/");
    case "revizyon-merkezi":
      return isRevizyonMerkeziPath(pathname);
    case "belge-takip":
      return pathname === "/personeller/belge-takip" || pathname.startsWith("/personeller/belge-takip/");
    case "finans":
      return pathname === "/finans" || pathname.startsWith("/finans/");
    default:
      return false;
  }
}
