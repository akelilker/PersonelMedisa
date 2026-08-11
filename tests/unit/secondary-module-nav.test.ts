import { describe, expect, it } from "vitest";
import { hasRolePermission, type AppPermission } from "../../src/lib/authorization/role-permissions";
import type { UserRole } from "../../src/types/auth";
import {
  isSecondaryModuleActive,
  resolveSecondaryModules,
  SECONDARY_MODULE_CATALOG,
  type SecondaryModuleId
} from "../../src/lib/shell/secondary-module-nav";

function permissionGate(role: UserRole) {
  return (permission: AppPermission) => hasRolePermission(role, permission);
}

function activeModuleIds(pathname: string): SecondaryModuleId[] {
  return SECONDARY_MODULE_CATALOG.filter((module) => isSecondaryModuleActive(pathname, module.id)).map(
    (module) => module.id
  );
}

describe("secondary-module-nav", () => {
  it("exposes the canonical six-module catalog", () => {
    expect(SECONDARY_MODULE_CATALOG.map((item) => item.id)).toEqual([
      "puantaj",
      "gunluk-kayit",
      "haftalik-kapanis",
      "revizyon-merkezi",
      "belge-takip",
      "finans"
    ]);
  });

  it("keeps Revizyon Merkezi global nav on the canonical path", () => {
    const revizyon = SECONDARY_MODULE_CATALOG.find((item) => item.id === "revizyon-merkezi");
    expect(revizyon?.to).toBe("/haftalik-kapanis/revizyonlar");
    expect(SECONDARY_MODULE_CATALOG.some((item) => item.to === "/revizyon-merkezi")).toBe(false);
  });

  it("returns all modules for GENEL_YONETICI", () => {
    const modules = resolveSecondaryModules(permissionGate("GENEL_YONETICI"));
    expect(modules.map((item) => item.label)).toEqual([
      "Puantaj",
      "Günlük Kayıt",
      "Haftalık Kapanış",
      "Revizyon Merkezi",
      "Belge Takip",
      "Finans"
    ]);
  });

  it("returns permission-filtered modules for BIRIM_AMIRI without finans", () => {
    const modules = resolveSecondaryModules(permissionGate("BIRIM_AMIRI"));
    expect(modules.map((item) => item.id)).toEqual([
      "puantaj",
      "gunluk-kayit",
      "haftalik-kapanis",
      "revizyon-merkezi",
      "belge-takip"
    ]);
    expect(modules.some((item) => item.id === "finans")).toBe(false);
  });

  it("returns an empty list for PERSONEL with no secondary permissions", () => {
    expect(resolveSecondaryModules(permissionGate("PERSONEL"))).toEqual([]);
  });

  it("exposes troubleshooting secondary modules for SISTEM_YONETICISI", () => {
    const modules = resolveSecondaryModules(permissionGate("SISTEM_YONETICISI"));
    expect(modules.map((item) => item.id)).toEqual([
      "puantaj",
      "gunluk-kayit",
      "haftalik-kapanis",
      "revizyon-merkezi",
      "belge-takip",
      "finans"
    ]);
  });

  it("filters to a partial permission set", () => {
    const modules = resolveSecondaryModules(
      (permission) => permission === "puantaj.view" || permission === "finans.view"
    );
    expect(modules.map((item) => item.id)).toEqual(["puantaj", "finans"]);
  });

  it("marks gunluk kayit active for detail routes", () => {
    expect(isSecondaryModuleActive("/bildirimler/42", "gunluk-kayit")).toBe(true);
    expect(isSecondaryModuleActive("/bildirimler/42", "puantaj")).toBe(false);
  });

  it("keeps haftalik, revizyon and correction active states mutually exclusive", () => {
    expect(activeModuleIds("/haftalik-kapanis")).toEqual(["haftalik-kapanis"]);
    expect(activeModuleIds("/haftalik-kapanis/12")).toEqual(["haftalik-kapanis"]);

    expect(activeModuleIds("/haftalik-kapanis/revizyonlar")).toEqual(["revizyon-merkezi"]);
    expect(activeModuleIds("/haftalik-kapanis/revizyonlar/yeni")).toEqual(["revizyon-merkezi"]);
    expect(activeModuleIds("/haftalik-kapanis/revizyonlar/9")).toEqual(["revizyon-merkezi"]);

    expect(activeModuleIds("/haftalik-kapanis/corrections")).toEqual(["revizyon-merkezi"]);
    expect(activeModuleIds("/haftalik-kapanis/corrections/77")).toEqual(["revizyon-merkezi"]);
  });

  it("matches belge takip, finans and puantaj routes", () => {
    expect(isSecondaryModuleActive("/personeller/belge-takip", "belge-takip")).toBe(true);
    expect(isSecondaryModuleActive("/finans", "finans")).toBe(true);
    expect(isSecondaryModuleActive("/puantaj", "puantaj")).toBe(true);
    expect(isSecondaryModuleActive("/personeller", "belge-takip")).toBe(false);
  });
});
