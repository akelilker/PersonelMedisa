import { describe, expect, it } from "vitest";
import {
  BILDIRIM_DETAIL_ALLOWED_ROLES,
  FINANS_ALLOWED_ROLES,
  PERSONEL_DETAIL_ALLOWED_ROLES,
  PUANTAJ_ALLOWED_ROLES,
  RAPORLAR_ALLOWED_ROLES,
  SUREC_DETAIL_ALLOWED_ROLES,
  getRolePermissions,
  hasRolePermission,
  sessionAllowsSubeAccess
} from "../../src/lib/authorization/role-permissions";
import type { AuthSession } from "../../src/types/auth";

describe("role permissions", () => {
  it("grants management roles full personel and process actions", () => {
    expect(hasRolePermission("GENEL_YONETICI", "personeller.create")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "surecler.cancel")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "bildirimler.update")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "raporlar.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "finans.cancel")).toBe(true);
  });

  it("keeps BIRIM_AMIRI role focused on sube visibility and daily bildirim workflow", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.view.sube")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.view.sube")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.detail.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.create")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.update")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.cancel")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "raporlar.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.create")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.update")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.update")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.amir_kontrol")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "finans.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "finans.create")).toBe(false);
  });

  it("resolves allowed role lists for detail routes from permission matrix", () => {
    expect(PERSONEL_DETAIL_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(SUREC_DETAIL_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(BILDIRIM_DETAIL_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(PUANTAJ_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(RAPORLAR_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE", "BIRIM_AMIRI"])
    );
    expect(FINANS_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE"])
    );
  });

  it("returns empty permissions for unknown/empty role input", () => {
    expect(getRolePermissions(null)).toEqual([]);
    expect(getRolePermissions(undefined)).toEqual([]);
  });

  it("sessionAllowsSubeAccess allows any sube when allowed list empty", () => {
    const session = {
      token: "t",
      ui_profile: "yonetim",
      active_sube_id: null,
      user: { id: 1, ad_soyad: "A", rol: "GENEL_YONETICI", sube_ids: [] }
    } satisfies AuthSession;
    expect(sessionAllowsSubeAccess(session, 99)).toBe(true);
  });

  it("sessionAllowsSubeAccess restricts to sube_ids when list non-empty", () => {
    const session = {
      token: "t",
      ui_profile: "yonetim",
      active_sube_id: 1,
      user: { id: 1, ad_soyad: "Genel Muh", rol: "MUHASEBE", sube_ids: [1, 2] }
    } satisfies AuthSession;
    expect(sessionAllowsSubeAccess(session, 1)).toBe(true);
    expect(sessionAllowsSubeAccess(session, 3)).toBe(false);
  });

  it("grants GENEL_YONETICI all revizyon permissions", () => {
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.create")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.submit")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.cancel")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.approve")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.reject")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.view_finance_effect")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "revizyon.view_audit_history")).toBe(true);
  });

  it("keeps BOLUM_YONETICISI and MUHASEBE without approve/reject", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.view")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.create")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.approve")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.reject")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "revizyon.view_finance_effect")).toBe(true);

    expect(hasRolePermission("MUHASEBE", "revizyon.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "revizyon.create")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "revizyon.approve")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "revizyon.reject")).toBe(false);
    expect(hasRolePermission("MUHASEBE", "revizyon.view_finance_effect")).toBe(true);
  });

  it("keeps BIRIM_AMIRI revizyon scope limited without finance effect and approval", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.create")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.submit")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.cancel")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.view_audit_history")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.approve")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.reject")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "revizyon.view_finance_effect")).toBe(false);
  });
});
