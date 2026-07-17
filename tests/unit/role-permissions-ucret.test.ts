import { describe, expect, it } from "vitest";
import {
  getRolesWithPermission,
  hasRolePermission
} from "../../src/lib/authorization/role-permissions";

describe("ucret ve mevzuat yetki matrisi (S77-B)", () => {
  it("personeller.ucret.view yalnizca GENEL_YONETICI ve MUHASEBE icindir", () => {
    expect(getRolesWithPermission("personeller.ucret.view").sort()).toEqual([
      "GENEL_YONETICI",
      "MUHASEBE"
    ]);
  });

  it("personeller.ucret.manage yalnizca GENEL_YONETICI ve MUHASEBE icindir", () => {
    expect(getRolesWithPermission("personeller.ucret.manage").sort()).toEqual([
      "GENEL_YONETICI",
      "MUHASEBE"
    ]);
  });

  it("mevzuat_parametreleri.view GENEL_YONETICI ve MUHASEBE, manage yalnizca GENEL_YONETICI", () => {
    expect(getRolesWithPermission("mevzuat_parametreleri.view").sort()).toEqual([
      "GENEL_YONETICI",
      "MUHASEBE"
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
  });
});
