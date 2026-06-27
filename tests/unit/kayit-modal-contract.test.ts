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
      intent: null,
      returnTo: null
    });
  });

  it("resolves personel edit gateway contract", () => {
    expect(
      resolveKayitModalRouteConfig({
        kayitModal: {
          tab: "yeni-kayit",
          personelId: 7,
          intent: "personel-edit-gateway",
          returnTo: "/personeller/7"
        }
      })
    ).toEqual({
      tab: "yeni-kayit",
      personelId: "7",
      intent: "personel-edit-gateway",
      returnTo: "/personeller/7"
    });
  });

  it("normalizes unknown tab to yeni-kayit and ignores invalid intent", () => {
    expect(
      resolveKayitModalRouteConfig({
        kayitModal: {
          tab: "invalid",
          intent: "other",
          returnTo: "  "
        }
      })
    ).toEqual({
      tab: "yeni-kayit",
      personelId: null,
      intent: null,
      returnTo: null
    });
  });
});
