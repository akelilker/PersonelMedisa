import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { getActorFingerprint, getSession } from "../../src/auth/auth-manager";
import {
  clearAllAppPersistence,
  ensureAppData,
  enqueueSyncOperation,
  getAppData,
  initAppDataFromStorage,
  setCacheEntry,
} from "../../src/data/data-manager";
import type { AuthSession } from "../../src/types/auth";

const { mockGetSession } = vi.hoisted(() => ({
  mockGetSession: vi.fn<[], AuthSession | null>(),
}));

vi.mock("../../src/auth/auth-manager", async (importOriginal) => {
  const actual = await importOriginal<
    typeof import("../../src/auth/auth-manager")
  >();
  return {
    ...actual,
    getSession: mockGetSession,
  };
});

const USER_A_SESSION: AuthSession = {
  token: "user-a-token",
  ui_profile: "yonetim",
  user: {
    id: 1,
    ad_soyad: "User A",
    rol: "GENEL_YONETICI",
    sube_ids: [1, 2],
  },
};

const USER_B_SESSION: AuthSession = {
  token: "user-b-token",
  ui_profile: "yonetim",
  user: {
    id: 2,
    ad_soyad: "User B",
    rol: "GENEL_YONETICI",
    sube_ids: [1, 3],
  },
};

describe("data-isolation", () => {
  let localStorageMock: Record<string, string> = {};

  beforeEach(() => {
    localStorageMock = {};
    vi.stubGlobal("window", {
      localStorage: {
        getItem: (key: string) => localStorageMock[key] ?? null,
        setItem: (key: string, value: string) => {
          localStorageMock[key] = value;
        },
        removeItem: (key: string) => {
          delete localStorageMock[key];
        },
      },
      // appData'yi her test oncesi sifirla
      appData: undefined
    });
    mockGetSession.mockReturnValue(null);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("should purge app data when actor fingerprint changes between sessions", () => {
    // 1. User A olarak basla ve cache'e veri yaz
    mockGetSession.mockReturnValue(USER_A_SESSION);
    initAppDataFromStorage();
    setCacheEntry("test_key", "user_a_data");
    expect(getAppData().cache["test_key"].data).toBe("user_a_data");

    const fingerprintA = getActorFingerprint(USER_A_SESSION);
    expect(getAppData().ownerFingerprint).toBe(fingerprintA);

    // 2. Oturum degistir (User B)
    mockGetSession.mockReturnValue(USER_B_SESSION);

    // 3. ensureAppData, degisikligi algilamali ve veriyi temizlemeli
    const appData = ensureAppData();
    const fingerprintB = getActorFingerprint(USER_B_SESSION);
    expect(appData.ownerFingerprint).toBe(fingerprintB);
    expect(appData.cache["test_key"]).toBeUndefined();
    expect(Object.keys(appData.cache)).toHaveLength(0);
  });

  it("should not allow enqueuing operations for a different user's queue", () => {
    // 1. User A olarak basla ve bir item enqueue et
    mockGetSession.mockReturnValue(USER_A_SESSION);
    initAppDataFromStorage();
    const resultA = enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    expect(resultA).toBe("queued");
    
    // localStorage'da User A'nin queue'su var
    const queueA = JSON.parse(localStorageMock["medisa_sync_queue"] || "[]");
    expect(queueA).toHaveLength(1);
    expect(queueA[0].ownerFingerprint).toBe(getActorFingerprint(USER_A_SESSION));

    // 2. User B olarak oturum ac
    mockGetSession.mockReturnValue(USER_B_SESSION);
    
    // ensureAppData, User A'nin verilerini temizlemis olmali
    ensureAppData();
    expect(localStorageMock["medisa_sync_queue"]).toBeUndefined();

    // 3. User B yeni bir item enqueue ettiginde, eski item'larin uzerine yazmamali
    const resultB = enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    expect(resultB).toBe("queued");

    const queueB = JSON.parse(localStorageMock["medisa_sync_queue"] || "[]");
    expect(queueB).toHaveLength(1);
    expect(queueB[0].ownerFingerprint).toBe(getActorFingerprint(USER_B_SESSION));
  });

  it("should clear all persistence on logout, including user-scoped data", () => {
    // User A olarak basla ve veri olustur
    mockGetSession.mockReturnValue(USER_A_SESSION);
    initAppDataFromStorage();
    setCacheEntry("test_key", "user_a_data");
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });

    expect(localStorageMock["medisa_app_data"]).toBeDefined();
    expect(localStorageMock["medisa_sync_queue"]).toBeDefined();
    
    // Logout ol
    mockGetSession.mockReturnValue(null);
    clearAllAppPersistence();
    
    // Tum ilgili localStorage verilerinin temizlendigini dogrula
    expect(localStorageMock["medisa_app_data"]).toBeUndefined();
    expect(localStorageMock["medisa_sync_queue"]).toBeUndefined();

    // appData'nin da sifirlandigini dogrula
    const appData = getAppData();
    expect(appData.ownerFingerprint).toBeNull();
    expect(Object.keys(appData.cache)).toHaveLength(0);
  });
});