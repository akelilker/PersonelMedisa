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
  | "puantaj.amir_kontrol"
  | "puantaj.muhurle"
  | "raporlar.view"
  | "finans.view"
  | "finans.create"
  | "finans.update"
  | "finans.cancel"
  | "isg.view"
  | "yonetim-paneli.view"
  | "yonetim-paneli.manage"
  | "aylik-ozet.view"
  | "aylik-ozet.review"
  | "aylik-ozet.executive_ack"
  | "gunluk_bildirim.create"
  | "gunluk_bildirim.update_own_open"
  | "gunluk_bildirim.submit"
  | "gunluk_bildirim.request_correction"
  | "haftalik_mutabakat.view"
  | "haftalik_mutabakat.approve"
  | "haftalik_mutabakat.reopen_request"
  | "aylik_bildirim_onayi.view"
  | "aylik_bildirim_onayi.approve"
  | "aylik_bolum_onayi.view"
  | "aylik_bolum_onayi.approve"
  | "genel_yonetici_onayi.view"
  | "genel_yonetici_onayi.approve"
  | "patron_ack.view"
  | "patron_ack.mark_seen"
  | "sirket_parametreleri.view"
  | "sirket_parametreleri.manage"
  | "bordro_on_izleme.view"
  | "bordro_kesinlestirme.approve"
  | "revizyon.view"
  | "revizyon.create"
  | "revizyon.submit"
  | "revizyon.cancel"
  | "revizyon.approve"
  | "revizyon.reject"
  | "revizyon.view_finance_effect"
  | "revizyon.view_audit_history";

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
    "puantaj.muhurle",
    "raporlar.view",
    "finans.view",
    "finans.create",
    "finans.update",
    "finans.cancel",
    "isg.view",
    "yonetim-paneli.view",
    "yonetim-paneli.manage",
    "aylik-ozet.view",
    "aylik-ozet.executive_ack",
    "gunluk_bildirim.request_correction",
    "haftalik_mutabakat.view",
    "haftalik_mutabakat.reopen_request",
    "aylik_bolum_onayi.view",
    "aylik_bildirim_onayi.view",
    "genel_yonetici_onayi.view",
    "genel_yonetici_onayi.approve",
    "patron_ack.view",
    "sirket_parametreleri.view",
    "sirket_parametreleri.manage",
    "bordro_on_izleme.view",
    "bordro_kesinlestirme.approve",
    "revizyon.view",
    "revizyon.create",
    "revizyon.submit",
    "revizyon.cancel",
    "revizyon.approve",
    "revizyon.reject",
    "revizyon.view_finance_effect",
    "revizyon.view_audit_history"
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
    "puantaj.muhurle",
    "raporlar.view",
    "finans.view",
    "finans.create",
    "finans.update",
    "finans.cancel",
    "isg.view",
    "aylik-ozet.view",
    "aylik-ozet.review",
    "gunluk_bildirim.request_correction",
    "haftalik_mutabakat.view",
    "haftalik_mutabakat.reopen_request",
    "aylik_bolum_onayi.view",
    "aylik_bolum_onayi.approve",
    "aylik_bildirim_onayi.view",
    "revizyon.view",
    "revizyon.create",
    "revizyon.submit",
    "revizyon.cancel",
    "revizyon.view_finance_effect",
    "revizyon.view_audit_history"
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
    "raporlar.view",
    "finans.view",
    "finans.create",
    "finans.update",
    "finans.cancel",
    "haftalik_mutabakat.view",
    "aylik_bildirim_onayi.view",
    "bordro_on_izleme.view",
    "sirket_parametreleri.view",
    "revizyon.view",
    "revizyon.create",
    "revizyon.submit",
    "revizyon.cancel",
    "revizyon.view_finance_effect",
    "revizyon.view_audit_history"
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
    "puantaj.amir_kontrol",
    "raporlar.view",
    "isg.view",
    "revizyon.view",
    "revizyon.create",
    "revizyon.submit",
    "revizyon.cancel",
    "revizyon.view_audit_history",
    "gunluk_bildirim.create",
    "gunluk_bildirim.update_own_open",
    "gunluk_bildirim.submit",
    "haftalik_mutabakat.view",
    "haftalik_mutabakat.approve",
    "aylik_bildirim_onayi.view",
    "aylik_bildirim_onayi.approve"
  ],
  PATRON: [
    "raporlar.view",
    "patron_ack.view",
    "patron_ack.mark_seen"
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
export const RAPORLAR_ALLOWED_ROLES = getRolesWithPermission("raporlar.view");
export const FINANS_ALLOWED_ROLES = getRolesWithPermission("finans.view");
export const AYLIK_OZET_ALLOWED_ROLES = getRolesWithPermission("aylik-ozet.view");
export const ISG_ALLOWED_ROLES = getRolesWithPermission("isg.view");

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
  raporlarPage: "raporlar.view",
  finansPage: "finans.view",
  isgPage: "isg.view",
  yonetimPaneliPage: "yonetim-paneli.view",
  aylikOzetPage: "aylik-ozet.view"
} as const satisfies Record<string, AppPermission>;
