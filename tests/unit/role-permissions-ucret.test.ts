import { describe, expect, it } from "vitest";
import {
  getRolesWithPermission,
  hasRolePermission
} from "../../src/lib/authorization/role-permissions";

describe("ucret ve mevzuat yetki matrisi (S77-B)", () => {
  it("personeller.ucret.view GENEL_YONETICI, MUHASEBE ve IK_BORDRO icindir", () => {
    expect(getRolesWithPermission("personeller.ucret.view").sort()).toEqual([
      "GENEL_YONETICI",
      "IK_BORDRO",
      "MUHASEBE"
    ]);
  });

  it("personeller.ucret.manage yalnizca GENEL_YONETICI ve MUHASEBE icindir", () => {
    expect(getRolesWithPermission("personeller.ucret.manage").sort()).toEqual([
      "GENEL_YONETICI",
      "MUHASEBE"
    ]);
  });

  it("mevzuat_parametreleri.view prepare/approve rollerini kapsar; manage yalnizca GENEL_YONETICI", () => {
    expect(getRolesWithPermission("mevzuat_parametreleri.view").sort()).toEqual([
      "GENEL_YONETICI",
      "IK_BORDRO",
      "MUHASEBE",
      "SGK_KARAR_ONAY_YETKILISI"
    ]);
    expect(getRolesWithPermission("mevzuat_parametreleri.manage")).toEqual(["GENEL_YONETICI"]);
  });

  it("BIRIM_AMIRI ve BOLUM_YONETICISI ucret bilgisine erisemez", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.ucret.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "personeller.ucret.manage")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "personeller.ucret.view")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "personeller.ucret.manage")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "mevzuat_parametreleri.view")).toBe(false);
    expect(hasRolePermission("PATRON", "personeller.ucret.view")).toBe(false);
    expect(hasRolePermission("IK_BORDRO", "personeller.ucret.manage")).toBe(false);
    expect(hasRolePermission("SGK_KARAR_ONAY_YETKILISI", "personeller.ucret.view")).toBe(false);
  });
});
