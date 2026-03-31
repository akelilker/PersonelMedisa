import { describe, expect, it } from "vitest";
import {
  BILDIRIM_DETAIL_ALLOWED_ROLES,
  FINANS_ALLOWED_ROLES,
  HAFTALIK_KAPANIS_ALLOWED_ROLES,
  PERSONEL_DETAIL_ALLOWED_ROLES,
  PUANTAJ_ALLOWED_ROLES,
  RAPORLAR_ALLOWED_ROLES,
  SUREC_DETAIL_ALLOWED_ROLES,
  getRolePermissions,
  hasRolePermission
} from "../../src/lib/authorization/role-permissions";

describe("role permissions", () => {
  it("grants management roles full personel and process actions", () => {
    expect(hasRolePermission("GENEL_YONETICI", "personeller.create")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "surecler.cancel")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "bildirimler.update")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "raporlar.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "finans.cancel")).toBe(true);
  });

  it("keeps birim amiri as read-only for mutating actions", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.view.sube")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.view.sube")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.detail.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "raporlar.view")).toBe(true);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.create")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "surecler.update")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "bildirimler.cancel")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.update")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "haftalik-kapanis.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "haftalik-kapanis.close")).toBe(false);
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
    expect(HAFTALIK_KAPANIS_ALLOWED_ROLES).toEqual(
      expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI", "MUHASEBE"])
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
});
