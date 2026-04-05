import type { AuthSession, UserRole } from "../../types/auth";

export type AppPermission =
  | "personeller.view"
  | "personeller.view.sube"
  | "personeller.create"
  | "personeller.update"
  | "personeller.detail.view"
  | "surecler.view"
  | "surecler.view.sube"
  | "surecler.create"
  | "surecler.update"
  | "surecler.cancel"
  | "surecler.detail.view"
  | "bildirimler.view"
  | "bildirimler.create"
  | "bildirimler.update"
  | "bildirimler.cancel"
  | "bildirimler.detail.view"
  | "puantaj.view"
  | "puantaj.update"
  | "haftalik-kapanis.view"
  | "haftalik-kapanis.close"
  | "raporlar.view"
  | "finans.view"
  | "finans.create"
  | "finans.update"
  | "finans.cancel"
  | "yonetim-paneli.view"
  | "yonetim-paneli.manage"
  | "aylik-ozet.view"
  | "aylik-ozet.review"
  | "aylik-ozet.finalize";

const ROLE_PERMISSIONS: Record<UserRole, readonly AppPermission[]> = {
  GENEL_YONETICI: [
    "personeller.view",
    "personeller.view.sube",
    "personeller.create",
    "personeller.update",
    "personeller.detail.view",
    "surecler.view",
    "surecler.view.sube",
    "surecler.create",
    "surecler.update",
    "surecler.cancel",
    "surecler.detail.view",
    "bildirimler.view",
    "bildirimler.create",
    "bildirimler.update",
    "bildirimler.cancel",
    "bildirimler.detail.view",
    "puantaj.view",
    "puantaj.update",
    "haftalik-kapanis.view",
    "haftalik-kapanis.close",
    "raporlar.view",
    "finans.view",
    "finans.create",
    "finans.update",
    "finans.cancel",
    "yonetim-paneli.view",
    "yonetim-paneli.manage",
    "aylik-ozet.view",
    "aylik-ozet.finalize"
  ],
  BOLUM_YONETICISI: [
    "personeller.view",
    "personeller.view.sube",
    "personeller.create",
    "personeller.update",
    "personeller.detail.view",
    "surecler.view",
    "surecler.view.sube",
    "surecler.create",
    "surecler.update",
    "surecler.cancel",
    "surecler.detail.view",
    "bildirimler.view",
    "bildirimler.create",
    "bildirimler.update",
    "bildirimler.cancel",
    "bildirimler.detail.view",
    "puantaj.view",
    "puantaj.update",
    "haftalik-kapanis.view",
    "haftalik-kapanis.close",
    "raporlar.view",
    "finans.view",
    "finans.create",
    "finans.update",
    "finans.cancel",
    "aylik-ozet.view",
    "aylik-ozet.review"
  ],
  MUHASEBE: [
    "personeller.view",
    "personeller.view.sube",
    "personeller.create",
    "personeller.update",
    "personeller.detail.view",
    "surecler.view",
    "surecler.view.sube",
    "surecler.create",
    "surecler.update",
    "surecler.cancel",
    "surecler.detail.view",
    "bildirimler.view",
    "bildirimler.create",
    "bildirimler.update",
    "bildirimler.cancel",
    "bildirimler.detail.view",
    "puantaj.view",
    "puantaj.update",
    "haftalik-kapanis.view",
    "haftalik-kapanis.close",
    "raporlar.view",
    "finans.view",
    "finans.create",
    "finans.update",
    "finans.cancel"
  ],
  BIRIM_AMIRI: [
    "personeller.view.sube",
    "personeller.detail.view",
    "surecler.view.sube",
    "surecler.detail.view",
    "bildirimler.view",
    "bildirimler.create",
    "bildirimler.update",
    "bildirimler.cancel",
    "bildirimler.detail.view",
    "puantaj.view",
    "raporlar.view"
  ]
};

const EMPTY_PERMISSIONS: readonly AppPermission[] = [];

export function getRolePermissions(role?: UserRole | null): readonly AppPermission[] {
  if (!role) {
    return EMPTY_PERMISSIONS;
  }

  return ROLE_PERMISSIONS[role] ?? EMPTY_PERMISSIONS;
}

export function hasRolePermission(
  role: UserRole | null | undefined,
  permission: AppPermission
): boolean {
  return getRolePermissions(role).includes(permission);
}

/** Oturumdaki yetkili sube listesi; bos ise yonetim (tum subeler) varsayimi. */
export function getAllowedSubeIdsFromSession(session: AuthSession | null): number[] {
  return session?.user.sube_ids ?? [];
}

/** Backend dogrulamasi zorunlu; frontend UX icin daraltma. */
export function sessionAllowsSubeAccess(session: AuthSession | null, subeId: number): boolean {
  const allowed = getAllowedSubeIdsFromSession(session);
  if (allowed.length === 0) {
    return true;
  }
  return allowed.includes(subeId);
}

export function getRolesWithPermission(permission: AppPermission): UserRole[] {
  const roles = Object.keys(ROLE_PERMISSIONS) as UserRole[];
  return roles.filter((role) => ROLE_PERMISSIONS[role].includes(permission));
}

export const PERSONEL_DETAIL_ALLOWED_ROLES = getRolesWithPermission("personeller.detail.view");
export const SUREC_DETAIL_ALLOWED_ROLES = getRolesWithPermission("surecler.detail.view");
export const BILDIRIM_DETAIL_ALLOWED_ROLES = getRolesWithPermission("bildirimler.detail.view");
export const PUANTAJ_ALLOWED_ROLES = getRolesWithPermission("puantaj.view");
export const HAFTALIK_KAPANIS_ALLOWED_ROLES = getRolesWithPermission("haftalik-kapanis.view");
export const RAPORLAR_ALLOWED_ROLES = getRolesWithPermission("raporlar.view");
export const FINANS_ALLOWED_ROLES = getRolesWithPermission("finans.view");
export const AYLIK_OZET_ALLOWED_ROLES = getRolesWithPermission("aylik-ozet.view");

/** Liste rotalari: genel veya sube kapsamli goruntuleme */
export const PERSONELLER_LIST_ANY: AppPermission[] = ["personeller.view", "personeller.view.sube"];
export const SURECLER_LIST_ANY: AppPermission[] = ["surecler.view", "surecler.view.sube"];

/** Route guard: permission tek kaynak — roller derived. */
export const ROUTE_PERMISSION = {
  bildirimlerPage: "bildirimler.view",
  personelDetail: "personeller.detail.view",
  surecDetail: "surecler.detail.view",
  bildirimDetail: "bildirimler.detail.view",
  puantajPage: "puantaj.view",
  haftalikKapanisPage: "haftalik-kapanis.view",
  raporlarPage: "raporlar.view",
  finansPage: "finans.view",
  yonetimPaneliPage: "yonetim-paneli.view",
  aylikOzetPage: "aylik-ozet.view"
} as const satisfies Record<string, AppPermission>;
