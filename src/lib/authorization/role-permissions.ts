import type { UserRole } from "../../types/auth";

export type AppPermission =
  | "personeller.view"
  | "personeller.create"
  | "personeller.update"
  | "personeller.detail.view"
  | "surecler.view"
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
  | "finans.cancel";

const ROLE_PERMISSIONS: Record<UserRole, readonly AppPermission[]> = {
  GENEL_YONETICI: [
    "personeller.view",
    "personeller.create",
    "personeller.update",
    "personeller.detail.view",
    "surecler.view",
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
  BOLUM_YONETICISI: [
    "personeller.view",
    "personeller.create",
    "personeller.update",
    "personeller.detail.view",
    "surecler.view",
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
  MUHASEBE: [
    "personeller.view",
    "personeller.create",
    "personeller.update",
    "personeller.detail.view",
    "surecler.view",
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
    "personeller.view",
    "personeller.detail.view",
    "surecler.view",
    "surecler.detail.view",
    "bildirimler.view",
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

/** Route guard: permission tek kaynak — roller derived. */
export const ROUTE_PERMISSION = {
  personelDetail: "personeller.detail.view",
  surecDetail: "surecler.detail.view",
  bildirimDetail: "bildirimler.detail.view",
  puantajPage: "puantaj.view",
  haftalikKapanisPage: "haftalik-kapanis.view",
  raporlarPage: "raporlar.view",
  finansPage: "finans.view"
} as const satisfies Record<string, AppPermission>;
