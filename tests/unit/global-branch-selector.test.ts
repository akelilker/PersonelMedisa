import { beforeEach, describe, expect, it, vi } from "vitest";
import { finalizeAuthSessionSube } from "../../src/auth/auth-session-sube";
import { setActiveSubeId, getSession, MEDISA_AUTH_SESSION_KEY } from "../../src/auth/auth-manager";
import type { AuthSession } from "../../src/types/auth";

function buildGlobalSession(overrides: Partial<AuthSession> = {}): AuthSession {
  return finalizeAuthSessionSube({
    token: "demo-token",
    ui_profile: "yonetim",
    user: {
      id: 1,
      ad_soyad: "Genel Yönetici",
      rol: "GENEL_YONETICI",
      sube_ids: [],
      bolum_ids: [],
      birim_ids: []
    },
    active_sube_id: null,
    sube_list: [
      { id: 1, ad: "Medisa Fabrika" },
      { id: 2, ad: "Medisa İzmir" },
      { id: 3, ad: "Medisa Sakarya" }
    ],
    ...overrides
  });
}

describe("global branch selector (GENEL_YONETICI)", () => {
  beforeEach(() => {
    const bag = new Map<string, string>();
    const storage = {
      getItem: (key: string) => bag.get(key) ?? null,
      setItem: (key: string, value: string) => {
        bag.set(key, value);
      },
      removeItem: (key: string) => {
        bag.delete(key);
      },
      clear: () => bag.clear(),
      key: () => null,
      length: 0
    };
    vi.stubGlobal("window", { sessionStorage: storage, localStorage: storage });
  });

  it("defaults to all-branches (null) when multiple subeler and empty assignment", () => {
    const session = buildGlobalSession({ active_sube_id: undefined as unknown as null });
    expect(session.active_sube_id).toBeNull();
  });

  it("allows selecting a single branch without mutating sube_ids", () => {
    window.sessionStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(buildGlobalSession()));
    setActiveSubeId(2);
    const after = getSession();
    expect(after?.active_sube_id).toBe(2);
    expect(after?.user.sube_ids).toEqual([]);
  });

  it("allows returning to all-branches after single-branch filter", () => {
    window.sessionStorage.setItem(
      MEDISA_AUTH_SESSION_KEY,
      JSON.stringify(buildGlobalSession({ active_sube_id: 2 }))
    );
    setActiveSubeId(null);
    expect(getSession()?.active_sube_id).toBeNull();
  });

  it("rejects branch ids outside session.sube_list for global users", () => {
    window.sessionStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(buildGlobalSession()));
    setActiveSubeId(999);
    expect(getSession()?.active_sube_id).toBeNull();
  });
});

describe("scoped branch selector", () => {
  beforeEach(() => {
    const bag = new Map<string, string>();
    const storage = {
      getItem: (key: string) => bag.get(key) ?? null,
      setItem: (key: string, value: string) => {
        bag.set(key, value);
      },
      removeItem: (key: string) => {
        bag.delete(key);
      },
      clear: () => bag.clear(),
      key: () => null,
      length: 0
    };
    vi.stubGlobal("window", { sessionStorage: storage, localStorage: storage });
  });

  it("rejects branch outside assigned sube_ids", () => {
    const scoped = finalizeAuthSessionSube({
      token: "t",
      ui_profile: "yonetim",
      user: {
        id: 2,
        ad_soyad: "Şube Yöneticisi",
        rol: "SUBE_YONETICISI",
        sube_ids: [1],
        bolum_ids: [],
        birim_ids: []
      },
      active_sube_id: 1,
      sube_list: [
        { id: 1, ad: "Medisa Fabrika" },
        { id: 2, ad: "Medisa İzmir" }
      ]
    });
    window.sessionStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(scoped));
    setActiveSubeId(2);
    expect(getSession()?.active_sube_id).toBe(1);
  });
});
