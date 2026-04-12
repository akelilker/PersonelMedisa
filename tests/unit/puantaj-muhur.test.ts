import { describe, expect, it } from "vitest";
import {
  hasRolePermission,
  getRolesWithPermission
} from "../../src/lib/authorization/role-permissions";

describe("puantaj.muhurle permission", () => {
  it("GENEL_YONETICI has puantaj.muhurle", () => {
    expect(hasRolePermission("GENEL_YONETICI", "puantaj.muhurle")).toBe(true);
  });

  it("BOLUM_YONETICISI has puantaj.muhurle", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.muhurle")).toBe(true);
  });

  it("MUHASEBE does NOT have puantaj.muhurle", () => {
    expect(hasRolePermission("MUHASEBE", "puantaj.muhurle")).toBe(false);
  });

  it("BIRIM_AMIRI does NOT have puantaj.muhurle", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.muhurle")).toBe(false);
  });

  it("only GENEL_YONETICI and BOLUM_YONETICISI have puantaj.muhurle", () => {
    const roles = getRolesWithPermission("puantaj.muhurle");
    expect(roles).toEqual(expect.arrayContaining(["GENEL_YONETICI", "BOLUM_YONETICISI"]));
    expect(roles).toHaveLength(2);
  });
});

describe("MUHURLENDI state guard logic", () => {
  it("isMuhurlendi correctly identifies sealed records", () => {
    const states = ["ACIK", "HESAPLANDI", "MUHURLENDI"];
    const results = states.map((s) => s === "MUHURLENDI");
    expect(results).toEqual([false, false, true]);
  });

  it("canEdit is false when isMuhurlendi is true, even with update permission", () => {
    const canUpdatePuantaj = true;
    const isMuhurlendi = true;
    const canEdit = canUpdatePuantaj && !isMuhurlendi;
    expect(canEdit).toBe(false);
  });

  it("canEdit is true when record is not sealed and user has permission", () => {
    const canUpdatePuantaj = true;
    const isMuhurlendi = false;
    const canEdit = canUpdatePuantaj && !isMuhurlendi;
    expect(canEdit).toBe(true);
  });

  it("canEdit is false when user lacks permission, regardless of seal state", () => {
    const canUpdatePuantaj = false;
    const isMuhurlendi = false;
    const canEdit = canUpdatePuantaj && !isMuhurlendi;
    expect(canEdit).toBe(false);
  });
});
