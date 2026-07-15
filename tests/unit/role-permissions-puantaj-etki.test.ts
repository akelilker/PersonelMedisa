import { describe, expect, it } from "vitest";
import { hasRolePermission } from "../../src/lib/authorization/role-permissions";

describe("puantaj bildirim etki permissions", () => {
  it("MUHASEBE view ve dismiss yetkisine sahiptir", () => {
    expect(hasRolePermission("MUHASEBE", "puantaj.bildirim_etki.view")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "puantaj.bildirim_etki.dismiss")).toBe(true);
    expect(hasRolePermission("MUHASEBE", "puantaj.bildirim_etki.resolve_conflict")).toBe(true);
  });

  it("BIRIM_AMIRI panel yetkisine sahip degildir", () => {
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.bildirim_etki.view")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.bildirim_etki.dismiss")).toBe(false);
    expect(hasRolePermission("BIRIM_AMIRI", "puantaj.bildirim_etki.resolve_conflict")).toBe(false);
  });

  it("BOLUM_YONETICISI view gorur ancak dismiss yapamaz", () => {
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.bildirim_etki.view")).toBe(true);
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.bildirim_etki.dismiss")).toBe(false);
    expect(hasRolePermission("BOLUM_YONETICISI", "puantaj.bildirim_etki.resolve_conflict")).toBe(false);
  });

  it("PATRON panel yetkisine sahip degildir", () => {
    expect(hasRolePermission("PATRON", "puantaj.bildirim_etki.view")).toBe(false);
    expect(hasRolePermission("PATRON", "puantaj.bildirim_etki.dismiss")).toBe(false);
  });

  it("GENEL_YONETICI view gorur ancak dismiss yapamaz", () => {
    expect(hasRolePermission("GENEL_YONETICI", "puantaj.bildirim_etki.view")).toBe(true);
    expect(hasRolePermission("GENEL_YONETICI", "puantaj.bildirim_etki.dismiss")).toBe(false);
    expect(hasRolePermission("GENEL_YONETICI", "puantaj.bildirim_etki.resolve_conflict")).toBe(false);
  });
});
