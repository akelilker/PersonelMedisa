import { describe, expect, it } from "vitest";
import { dropExpiredAllows } from "../../src/api/auth.api";
import { hasSessionPermission } from "../../src/lib/authorization/role-permissions";
import type { AuthSession } from "../../src/types/auth";

function session(partial: Partial<AuthSession> & { permissions?: string[] }): AuthSession {
  return {
    token: "t",
    ui_profile: "yonetim",
    active_sube_id: null,
    permissions_hydrated: partial.permissions_hydrated,
    permissions_stale: partial.permissions_stale,
    permissions_source: partial.permissions_source,
    user: {
      id: 4,
      ad_soyad: "Bölüm",
      rol: "BOLUM_YONETICISI",
      sube_ids: [],
      effective_permissions: partial.permissions
    }
  };
}

describe("oturum etkin izinleri", () => {
  it("ek ALLOW listede varsa ekran iznini açar; DENY listeden düşünce kapatır", () => {
    const allow = session({
      permissions_hydrated: true,
      permissions_source: "server",
      permissions: ["finans.view", "personeller.update"]
    });
    expect(hasSessionPermission(allow, "finans.view")).toBe(true);
    expect(hasSessionPermission(allow, "personeller.update")).toBe(true);

    const deny = session({
      permissions_hydrated: true,
      permissions_stale: true,
      permissions_source: "server",
      permissions: ["personeller.view"]
    });
    expect(hasSessionPermission(deny, "personeller.update")).toBe(false);
    expect(hasSessionPermission(deny, "personeller.view")).toBe(true);
  });

  it("boş hidrate liste ile eksik listeyi ayırır; yenileme hatası rol matrisine dönmez", () => {
    const empty = session({ permissions_hydrated: true, permissions_source: "server", permissions: [] });
    expect(hasSessionPermission(empty, "personeller.view")).toBe(false);

    const missing = session({ permissions_hydrated: false, permissions_source: undefined });
    expect(hasSessionPermission(missing, "personeller.update")).toBe(true);

    const failedRefresh = session({
      permissions_hydrated: false,
      permissions_stale: true
    });
    expect(hasSessionPermission(failedRefresh, "personeller.update")).toBe(false);

    const staleKept = session({
      permissions_hydrated: true,
      permissions_stale: true,
      permissions_source: "server",
      permissions: ["personeller.view"]
    });
    expect(hasSessionPermission(staleKept, "personeller.update")).toBe(false);
    expect(hasSessionPermission(staleKept, "personeller.view")).toBe(true);
  });

  it("süresi dolmuş ALLOW düşer; DENY kalkmaz ve rol izni eklenmez", () => {
    const now = Date.parse("2026-10-14T12:00:00Z");
    const open = session({
      permissions_hydrated: true,
      permissions_source: "server",
      permissions: ["raporlar.view", "yonetim-paneli.view"]
    });
    open.expiring_allows = [{ permission: "yonetim-paneli.view", ends_at_ms: now - 1000 }];
    const dropped = dropExpiredAllows(open, now);
    expect(dropped?.user.effective_permissions).toEqual(["raporlar.view"]);
    expect(dropped?.expiring_allows).toEqual([]);
    expect(hasSessionPermission(dropped, "yonetim-paneli.view")).toBe(false);
    expect(hasSessionPermission(dropped, "raporlar.view")).toBe(true);
    expect(hasSessionPermission(dropped, "personeller.update")).toBe(false);

    const pending = session({
      permissions_hydrated: true,
      permissions_source: "server",
      permissions: ["yonetim-paneli.view"]
    });
    pending.expiring_allows = [{ permission: "yonetim-paneli.view", ends_at_ms: now + 60_000 }];
    expect(dropExpiredAllows(pending, now)).toBeNull();
  });
});
