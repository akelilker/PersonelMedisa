import { describe, expect, it } from "vitest";
import { resolveKayitModalRouteConfig } from "../../src/features/kayit/kayit-modal-contract";

describe("resolveKayitModalRouteConfig", () => {
  it("returns null for invalid state", () => {
    expect(resolveKayitModalRouteConfig(null)).toBeNull();
    expect(resolveKayitModalRouteConfig(undefined)).toBeNull();
    expect(resolveKayitModalRouteConfig({})).toBeNull();
  });

  it("resolves surec tab with personel prefill", () => {
    expect(
      resolveKayitModalRouteConfig({
        kayitModal: {
          tab: "surec",
          personelId: 42
        }
      })
    ).toEqual({
      tab: "surec",
      personelId: "42",
      targetTab: null,
      personelTab: null,
      intent: null,
      recordId: null,
      returnTo: null,
      operation: null
    });
  });

  it("resolves annual-leave entitlement adjustment operation preselect", () => {
    expect(
      resolveKayitModalRouteConfig({
        kayitModal: {
          tab: "surec",
          personelId: 7,
          personelTab: "izin-devamsizlik",
          operation: "yillik-izin-hak-duzeltme"
        }
      })
    ).toEqual({
      tab: "surec",
      personelId: "7",
      targetTab: "izin-devamsizlik",
      personelTab: "izin-devamsizlik",
      intent: null,
      recordId: null,
      returnTo: null,
      operation: "yillik-izin-hak-duzeltme"
    });
  });

  it("normalizes unknown tab to yeni-kayit and ignores stale legacy fields", () => {
    expect(
      resolveKayitModalRouteConfig({
        kayitModal: {
          tab: "invalid",
          intent: "personel-edit-gateway",
          returnTo: "/personeller/7"
        }
      })
    ).toEqual({
      tab: "yeni-kayit",
      personelId: null,
      targetTab: null,
      personelTab: null,
      intent: "personel-edit-gateway",
      recordId: null,
      returnTo: "/personeller/7",
      operation: null
    });
  });
});
