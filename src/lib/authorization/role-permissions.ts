import type { AuthSession, UserRole } from "../../types/auth";
import { canonicalizeUserRole } from "./canonicalize-user-role";

export type AppPermission =
  | "personeller.view"
  | "personeller.view.sube"
  | "personeller.create"
  | "personeller.import.apply"
  | "personeller.update"
  | "personeller.test_fixture.classify"
  | "personeller.test_fixture.archive"
  | "personeller.detail.view"
  | "personeller.ucret.view"
  | "personeller.ucret.manage"
  | "mevzuat_parametreleri.view"
  | "mevzuat_parametreleri.manage"
  | "surecler.view"
  | "surecler.view.sube"
  | "surecler.create"
  | "surecler.update"
  | "surecler.cancel"
  | "surecler.detail.view"
  | "bildirimler.view"
  | "bildirimler.create"
  | "bildirimler.update"
  // bildirimler.cancel intentionally absent: cancel is gated by
  // gunluk_bildirim.update_own_open in canCancelGunlukBildirim and in
  // BildirimlerController::cancel. The sync-queue op of the same name is unrelated.
  | "bildirimler.detail.view"
  | "puantaj.view"
  | "puantaj.update"
  | "puantaj.amir_kontrol"
  | "puantaj.donem_muhurle"
  | "puantaj.haftalik_kapanis.manage"
  | "fazla_calisma_odeme_tercihi.manage"
  | "serbest_zaman.manage"
  | "puantaj.donem_reopen.request"
  | "puantaj.donem_reopen.approve"
  | "puantaj.donem_reseal"
  | "puantaj.donem_seal.history"
  | "puantaj.bildirim_etki.view"
  | "puantaj.bildirim_etki.generate"
  | "puantaj.bildirim_etki.apply"
  | "puantaj.bildirim_etki.dismiss"
  | "puantaj.bildirim_etki.resolve_conflict"
  | "puantaj.donem_kapanis.view"
  | "puantaj.donem_kapanis.export"
  | "puantaj.bildirim_etki.rapor.view"
  | "puantaj.bildirim_etki.rapor.export"
  | "puantaj.olay_karar.view"
  | "puantaj.olay_karar.decide"
  | "disiplin.view"
  | "disiplin.review"
  | "disiplin.defense_manage"
  | "disiplin.final_decision"
  | "maas_hesaplama.view"
  | "maas_hesaplama.manage"
  | "maas_hesaplama_adaylari.view"
  | "maas_hesaplama_adaylari.manage"
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
  | "gunluk_bildirim.complete_day"
  | "haftalik_mutabakat.view"
  | "haftalik_mutabakat.approve"
  | "haftalik_mutabakat.reopen_request"
  | "aylik_bildirim_onayi.view"
  | "aylik_bildirim_onayi.approve"
  | "aylik_bolum_onayi.view"
  | "aylik_bolum_onayi.approve"
  | "genel_yonetici_onayi.view"
  | "genel_yonetici_onayi.approve"
  | "genel_yonetici_bildirim_onayi.view"
  | "genel_yonetici_bildirim_onayi.approve"
  | "patron_ack.view"
  | "patron_ack.mark_seen"
  | "sirket_parametreleri.view"
  | "sirket_parametreleri.manage"
  | "resmi_tatil_takvimi.view"
  | "resmi_tatil_takvimi.manage"
  | "bordro_on_izleme.view"
  | "bordro_kesinlestirme.approve"
  | "personel_bordro_kapsam.view"
  | "personel_bordro_kapsam.manage"
  | "personel_bordro_kapsam.approve"
  | "revizyon.view"
  | "revizyon.create"
  | "revizyon.submit"
  | "revizyon.cancel"
  | "revizyon.approve"
  | "revizyon.reject"
  | "revizyon.view_finance_effect"
  | "revizyon.view_audit_history"
  | "sgk.manuel_kod_override"
  | "sgk_karar_paketi.prepare"
  | "sgk_karar_paketi.approve"
  | "ops.auth_smoke.read"
  | "arsiv.view"
  | "arsiv.download"
  | "arsiv.audit.view"
  | "retention.view"
  | "legal_hold.manage"
  | "retention.destruction.request"
  | "retention.destruction.approve"
  | "retention.destruction.execute"
  | "retention.destruction.view"
  | "yillik_izin_hak_duzeltme.manage"
  | "self_service.view"
  | "self_service.puantaj.view"
  | "self_service.yillik_izin.view"
  | "self_service.fazla_calisma.view"
  | "self_service.qr.scan"
  | "self_service.qr.events.view"
  | "self_service.attendance.correct"
  | "attendance.correction.decide"
  | "qr.kiosk.display";

/**
 * Canonical self-service baseline — same set as PERSONEL role matrix.
 * Granted additionally when session user has a positive personel_id binding.
 */
export const SELF_SERVICE_BASELINE_PERMISSIONS: readonly AppPermission[] = [
  "self_service.view",
  "self_service.puantaj.view",
  "self_service.yillik_izin.view",
  "self_service.fazla_calisma.view",
  "self_service.qr.scan",
  "self_service.qr.events.view",
  "self_service.attendance.correct"
];

const ROLE_PERMISSIONS: Record<UserRole, readonly AppPermission[]> = {
  GENEL_YONETICI: [
    "personeller.view",
    "personeller.view.sube",
    "personeller.create",
    "personeller.import.apply",
    "personeller.update",
    "personeller.test_fixture.classify",
    "personeller.test_fixture.archive",
    "personeller.detail.view",
    "personeller.ucret.view",
    "personeller.ucret.manage",
    "mevzuat_parametreleri.view",
    "mevzuat_parametreleri.manage",
    "surecler.view",
    "surecler.view.sube",
    "surecler.create",
    "surecler.update",
    "surecler.cancel",
    "surecler.detail.view",
    "bildirimler.view",
    "bildirimler.create",
    "bildirimler.update",
    "bildirimler.detail.view",
    "puantaj.view",
    "puantaj.update",
    "puantaj.donem_muhurle",
    "puantaj.haftalik_kapanis.manage",
    "fazla_calisma_odeme_tercihi.manage",
    "serbest_zaman.manage",
    "puantaj.donem_reopen.approve",
    "puantaj.donem_seal.history",
    "puantaj.bildirim_etki.view",
    "puantaj.donem_kapanis.view",
    "puantaj.donem_kapanis.export",
    "puantaj.bildirim_etki.rapor.view",
    "puantaj.bildirim_etki.rapor.export",
    "maas_hesaplama.view",
    "maas_hesaplama.manage",
    "maas_hesaplama_adaylari.view",
    "maas_hesaplama_adaylari.manage",
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
    "genel_yonetici_bildirim_onayi.view",
    "genel_yonetici_bildirim_onayi.approve",
    "patron_ack.view",
    "patron_ack.mark_seen",
    "sirket_parametreleri.view",
    "sirket_parametreleri.manage",
    "resmi_tatil_takvimi.view",
    "resmi_tatil_takvimi.manage",
    "bordro_on_izleme.view",
    "bordro_kesinlestirme.approve",
    "personel_bordro_kapsam.view",
    "personel_bordro_kapsam.manage",
    "personel_bordro_kapsam.approve",
    "revizyon.view",
    "revizyon.create",
    "revizyon.submit",
    "revizyon.cancel",
    "revizyon.approve",
    "revizyon.reject",
    "revizyon.view_finance_effect",
    "revizyon.view_audit_history",
    "sgk.manuel_kod_override",
    "sgk_karar_paketi.prepare",
    "sgk_karar_paketi.approve",
    "yillik_izin_hak_duzeltme.manage",
    "disiplin.view",
    "disiplin.review",
    "disiplin.defense_manage",
    "puantaj.olay_karar.view",
    "arsiv.view",
    "arsiv.download",
    "arsiv.audit.view",
    "retention.view",
    "legal_hold.manage",
    "retention.destruction.request",
    "retention.destruction.approve",
    "retention.destruction.execute",
    "retention.destruction.view",
    "qr.kiosk.display",
    "attendance.correction.decide"
  ],
  BOLUM_YONETICISI: [
    "personeller.view",
    "personeller.view.sube",
    "personeller.create",
    "personeller.import.apply",
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
    "bildirimler.detail.view",
    "puantaj.view",
    "puantaj.update",
    "puantaj.donem_muhurle",
    "puantaj.haftalik_kapanis.manage",
    "fazla_calisma_odeme_tercihi.manage",
    "serbest_zaman.manage",
    "puantaj.donem_reopen.request",
    "puantaj.donem_seal.history",
    "puantaj.bildirim_etki.view",
    "puantaj.donem_kapanis.view",
    "puantaj.bildirim_etki.rapor.view",
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
    "revizyon.view_audit_history",
    "disiplin.view",
    "disiplin.final_decision",
    "puantaj.olay_karar.decide",
    "puantaj.olay_karar.view",
    // Explicit SGK final approve only — does not inherit GENEL_YONETICI matrix.
    "sgk_karar_paketi.approve",
    "attendance.correction.decide"
  ],
  /**
   * Branch-level operational management, scoped by explicit user_subeler (fail-closed).
   * Enters and submits branch operational data; never central payroll finalization,
   * company-wide SGK/finance decisions, or user/system administration.
   */
  SUBE_YONETICISI: [
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
    "bildirimler.detail.view",
    "puantaj.view",
    "puantaj.update",
    "fazla_calisma_odeme_tercihi.manage",
    "serbest_zaman.manage",
    "puantaj.donem_reopen.request",
    "puantaj.donem_seal.history",
    "puantaj.bildirim_etki.view",
    "puantaj.donem_kapanis.view",
    "puantaj.bildirim_etki.rapor.view",
    "raporlar.view",
    "finans.view",
    "isg.view",
    "aylik-ozet.view",
    "gunluk_bildirim.request_correction",
    "haftalik_mutabakat.view",
    "haftalik_mutabakat.reopen_request",
    "aylik_bolum_onayi.view",
    "aylik_bildirim_onayi.view",
    "revizyon.view",
    "revizyon.create",
    "revizyon.submit",
    "revizyon.cancel",
    "revizyon.view_audit_history",
    "disiplin.view",
    "disiplin.final_decision",
    "puantaj.olay_karar.decide",
    "puantaj.olay_karar.view",
    "qr.kiosk.display"
  ],
  /** External accountant: finalized mali/bordro read + export. No operational write. */
  MUHASEBE: [
    "personeller.view",
    "personeller.view.sube",
    "personeller.detail.view",
    "personeller.ucret.view",
    "mevzuat_parametreleri.view",
    "surecler.view",
    "surecler.view.sube",
    "surecler.detail.view",
    "puantaj.view",
    "puantaj.donem_seal.history",
    "puantaj.donem_kapanis.view",
    "puantaj.donem_kapanis.export",
    "puantaj.bildirim_etki.rapor.view",
    "puantaj.bildirim_etki.rapor.export",
    "maas_hesaplama.view",
    "maas_hesaplama_adaylari.view",
    "raporlar.view",
    "finans.view",
    "haftalik_mutabakat.view",
    "bordro_on_izleme.view",
    "sirket_parametreleri.view",
    "resmi_tatil_takvimi.view",
    "personel_bordro_kapsam.view",
    "revizyon.view",
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
    "bildirimler.detail.view",
    "puantaj.view",
    "puantaj.amir_kontrol",
    "puantaj.donem_kapanis.view",
    "puantaj.donem_seal.history",
    "puantaj.bildirim_etki.rapor.view",
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
    "gunluk_bildirim.complete_day",
    "haftalik_mutabakat.view",
    "haftalik_mutabakat.approve",
    "aylik_bildirim_onayi.view",
    "aylik_bildirim_onayi.approve",
    "attendance.correction.decide"
  ],
  /** IK operational owner (successor of IK_BORDRO). Prepare-only for SGK; no final approve. */
  IK_SORUMLUSU: [
    "personeller.view",
    "personeller.view.sube",
    "personeller.create",
    "personeller.import.apply",
    "personeller.update",
    "personeller.test_fixture.classify",
    "personeller.test_fixture.archive",
    "personeller.detail.view",
    "personeller.ucret.view",
    "mevzuat_parametreleri.view",
    "surecler.view",
    "surecler.view.sube",
    "surecler.create",
    "surecler.update",
    "surecler.cancel",
    "surecler.detail.view",
    "bildirimler.view",
    "bildirimler.detail.view",
    "haftalik_mutabakat.view",
    "puantaj.view",
    "puantaj.donem_reopen.request",
    "puantaj.donem_reseal",
    "puantaj.donem_seal.history",
    "puantaj.bildirim_etki.view",
    "puantaj.bildirim_etki.generate",
    "puantaj.bildirim_etki.apply",
    "puantaj.bildirim_etki.dismiss",
    "puantaj.bildirim_etki.resolve_conflict",
    "puantaj.donem_kapanis.view",
    "puantaj.bildirim_etki.rapor.view",
    "puantaj.olay_karar.view",
    "disiplin.view",
    "disiplin.review",
    "disiplin.defense_manage",
    "maas_hesaplama.view",
    "maas_hesaplama.manage",
    "maas_hesaplama_adaylari.view",
    "maas_hesaplama_adaylari.manage",
    "raporlar.view",
    "bordro_on_izleme.view",
    "sirket_parametreleri.view",
    "sirket_parametreleri.manage",
    "personel_bordro_kapsam.view",
    "personel_bordro_kapsam.manage",
    "revizyon.view",
    "revizyon.create",
    "revizyon.submit",
    "revizyon.cancel",
    "revizyon.view_finance_effect",
    "revizyon.view_audit_history",
    "sgk_karar_paketi.prepare",
    "yillik_izin_hak_duzeltme.manage",
    "arsiv.view",
    "arsiv.download",
    "retention.view"
  ],
  /**
   * IT Müdürü / teknik uygulama yöneticisi.
   * Broad troubleshooting READ + yonetim-paneli.manage (users/roles/subeler).
   * Never business approver / policy owner / domain data writer.
   */
  SISTEM_YONETICISI: [
    "personeller.view",
    "personeller.view.sube",
    "personeller.detail.view",
    "personeller.ucret.view",
    "mevzuat_parametreleri.view",
    "surecler.view",
    "surecler.view.sube",
    "surecler.detail.view",
    "bildirimler.view",
    "bildirimler.detail.view",
    "puantaj.view",
    "puantaj.donem_seal.history",
    "puantaj.bildirim_etki.view",
    "puantaj.donem_kapanis.view",
    "puantaj.donem_kapanis.export",
    "puantaj.bildirim_etki.rapor.view",
    "puantaj.bildirim_etki.rapor.export",
    "puantaj.olay_karar.view",
    "disiplin.view",
    "maas_hesaplama.view",
    "maas_hesaplama_adaylari.view",
    "raporlar.view",
    "finans.view",
    "isg.view",
    "yonetim-paneli.view",
    "yonetim-paneli.manage",
    "aylik-ozet.view",
    "haftalik_mutabakat.view",
    "aylik_bildirim_onayi.view",
    "aylik_bolum_onayi.view",
    "genel_yonetici_onayi.view",
    "genel_yonetici_bildirim_onayi.view",
    "patron_ack.view",
    "sirket_parametreleri.view",
    "resmi_tatil_takvimi.view",
    "bordro_on_izleme.view",
    "personel_bordro_kapsam.view",
    "revizyon.view",
    "revizyon.view_finance_effect",
    "revizyon.view_audit_history",
    "arsiv.view",
    "arsiv.download",
    "arsiv.audit.view",
    "retention.view",
    "retention.destruction.view",
    "qr.kiosk.display"
  ],
  /** Self-service read surfaces (S3B). No broad personeller.* / puantaj.view. */
  PERSONEL: SELF_SERVICE_BASELINE_PERMISSIONS,
  AUTH_SMOKE_READONLY: ["ops.auth_smoke.read"]
};

const EMPTY_PERMISSIONS: readonly AppPermission[] = [];

export function getRolePermissions(role?: UserRole | string | null): readonly AppPermission[] {
  const canonical = canonicalizeUserRole(role ?? null);
  if (!canonical) {
    return EMPTY_PERMISSIONS;
  }

  return ROLE_PERMISSIONS[canonical] ?? EMPTY_PERMISSIONS;
}

export function hasRolePermission(
  role: UserRole | string | null | undefined,
  permission: AppPermission
): boolean {
  return getRolePermissions(role).includes(permission);
}

/** Fail-closed: positive personel_id on the auth user (no extra DB). */
export function hasPersonnelLinkedSelfServiceEligibility(
  personelId: number | null | undefined
): boolean {
  return typeof personelId === "number" && Number.isFinite(personelId) && personelId > 0;
}

/**
 * Effective permission check: role matrix + personnel-linked self-service baseline.
 * Mirrors api/src/Auth/RolePermissions::has.
 */
export function hasUserPermission(
  role: UserRole | string | null | undefined,
  permission: AppPermission,
  personelId?: number | null
): boolean {
  if (
    hasPersonnelLinkedSelfServiceEligibility(personelId) &&
    SELF_SERVICE_BASELINE_PERMISSIONS.includes(permission)
  ) {
    return true;
  }
  return hasRolePermission(role, permission);
}

export function getEffectivePermissions(
  role: UserRole | string | null | undefined,
  personelId?: number | null
): readonly AppPermission[] {
  const rolePerms = getRolePermissions(role);
  if (!hasPersonnelLinkedSelfServiceEligibility(personelId)) {
    return rolePerms;
  }
  const merged = new Set<AppPermission>(rolePerms);
  for (const permission of SELF_SERVICE_BASELINE_PERMISSIONS) {
    merged.add(permission);
  }
  return [...merged];
}

/** Oturumdaki yetkili sube listesi; bos + global rol ise tum subeler UX. */
export function getAllowedSubeIdsFromSession(session: AuthSession | null): number[] {
  return session?.user.sube_ids ?? [];
}

/** Backend dogrulamasi zorunlu; frontend UX icin daraltma. Fail-closed for non-global empty. */
export function sessionAllowsSubeAccess(session: AuthSession | null, subeId: number): boolean {
  const role = canonicalizeUserRole(session?.user.rol ?? null);
  const allowed = getAllowedSubeIdsFromSession(session);
  if (allowed.length === 0) {
    if (role === "GENEL_YONETICI" || role === "SISTEM_YONETICISI") {
      return true;
    }
    // Department/unit scope is not branch-assignment based; do not block FE by empty sube_ids.
    if (role === "BOLUM_YONETICISI" || role === "BIRIM_AMIRI") {
      const list = session?.sube_list ?? [];
      if (list.length === 0) {
        return true;
      }
      return list.some((s) => s.id === subeId);
    }
    return false;
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
  /** Branch QR display — scoped by SubeScope; not yonetim-paneli.manage. */
  qrKioskPage: "qr.kiosk.display",
  resmiTatilTakvimiPage: "resmi_tatil_takvimi.view",
  aylikOzetPage: "aylik-ozet.view",
  haftalikKapanisPage: "revizyon.view"
} as const satisfies Record<string, AppPermission>;
