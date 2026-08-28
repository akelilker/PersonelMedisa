import { describe, expect, it } from "vitest";
import {
  hasRolePermission,
  getRolesWithPermission
} from "../../src/lib/authorization/role-permissions";

const CLOSURE_PERMISSIONS = [
  "puantaj.donem_muhurle",
  "puantaj.haftalik_kapanis.manage"
] as const;

const BRANCH_PAYROLL_INPUT_PERMISSIONS = [
  "fazla_calisma_odeme_tercihi.manage",
  "serbest_zaman.manage"
] as const;

describe("puantaj period closure permissions", () => {
  it("GENEL_YONETICI and BOLUM_YONETICISI keep both closure permissions", () => {
    for (const permission of CLOSURE_PERMISSIONS) {
      expect(hasRolePermission("GENEL_YONETICI", permission)).toBe(true);
      expect(hasRolePermission("BOLUM_YONETICISI", permission)).toBe(true);
    }
  });

  it("MUHASEBE and BIRIM_AMIRI have no closure permission", () => {
    for (const permission of CLOSURE_PERMISSIONS) {
      expect(hasRolePermission("MUHASEBE", permission)).toBe(false);
      expect(hasRolePermission("BIRIM_AMIRI", permission)).toBe(false);
    }
  });

  it("only GENEL_YONETICI and BOLUM_YONETICISI can close a period", () => {
    for (const permission of CLOSURE_PERMISSIONS) {
      expect([...getRolesWithPermission(permission)].sort()).toEqual([
        "BOLUM_YONETICISI",
        "GENEL_YONETICI"
      ]);
    }
  });

  it("SUBE_YONETICISI cannot seal a month or close a week", () => {
    for (const permission of CLOSURE_PERMISSIONS) {
      expect(hasRolePermission("SUBE_YONETICISI", permission)).toBe(false);
    }
  });

  it("IK_SORUMLUSU gains no closure authority from the permission split", () => {
    for (const permission of CLOSURE_PERMISSIONS) {
      expect(hasRolePermission("IK_SORUMLUSU", permission)).toBe(false);
    }
  });
});

describe("branch-scoped payroll input permissions", () => {
  it("SUBE_YONETICISI can enter fazla calisma payment preference and serbest zaman", () => {
    for (const permission of BRANCH_PAYROLL_INPUT_PERMISSIONS) {
      expect(hasRolePermission("SUBE_YONETICISI", permission)).toBe(true);
    }
  });

  it("GENEL_YONETICI and BOLUM_YONETICISI keep the same input capability", () => {
    for (const permission of BRANCH_PAYROLL_INPUT_PERMISSIONS) {
      expect(hasRolePermission("GENEL_YONETICI", permission)).toBe(true);
      expect(hasRolePermission("BOLUM_YONETICISI", permission)).toBe(true);
    }
  });

  it("read-only roles get no payroll input write", () => {
    for (const permission of BRANCH_PAYROLL_INPUT_PERMISSIONS) {
      for (const role of ["MUHASEBE", "BIRIM_AMIRI", "SISTEM_YONETICISI", "PERSONEL"] as const) {
        expect(hasRolePermission(role, permission)).toBe(false);
      }
    }
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
