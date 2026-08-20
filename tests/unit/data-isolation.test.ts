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
      appData: undefined
    });
    mockGetSession.mockReturnValue(null);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("should purge app data when actor fingerprint changes between sessions", () => {
    mockGetSession.mockReturnValue(USER_A_SESSION);
    initAppDataFromStorage();
    setCacheEntry("test_key", "user_a_data");
    expect(getAppData().cache["test_key"].data).toBe("user_a_data");

    const fingerprintA = getActorFingerprint(USER_A_SESSION);
    expect(getAppData().ownerFingerprint).toBe(fingerprintA);

    mockGetSession.mockReturnValue(USER_B_SESSION);

    const appData = ensureAppData();
    const fingerprintB = getActorFingerprint(USER_B_SESSION);
    expect(appData.ownerFingerprint).toBe(fingerprintB);
    expect(appData.cache["test_key"]).toBeUndefined();
    expect(Object.keys(appData.cache)).toHaveLength(0);
  });

  it("keeps other actors queue items but never exposes them to current actor", () => {
    mockGetSession.mockReturnValue(USER_A_SESSION);
    initAppDataFromStorage();
    const resultA = enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    expect(resultA).toBe("queued");

    const queueA = JSON.parse(localStorageMock["medisa_sync_queue"] || "[]");
    expect(queueA).toHaveLength(1);
    expect(queueA[0].ownerFingerprint).toBe(getActorFingerprint(USER_A_SESSION));

    mockGetSession.mockReturnValue(USER_B_SESSION);
    ensureAppData();

    const resultB = enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    expect(resultB).toBe("queued");

    const queueAll = JSON.parse(localStorageMock["medisa_sync_queue"] || "[]");
    expect(queueAll).toHaveLength(2);
    expect(queueAll.map((i: { ownerFingerprint: string }) => i.ownerFingerprint).sort()).toEqual(
      [getActorFingerprint(USER_A_SESSION), getActorFingerprint(USER_B_SESSION)].sort()
    );
  });

  it("clears protected cache on logout but keeps sync queue for same-actor 401 resume", () => {
    mockGetSession.mockReturnValue(USER_A_SESSION);
    initAppDataFromStorage();
    setCacheEntry("test_key", "user_a_data");
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });

    expect(localStorageMock["medisa_app_data"]).toBeDefined();
    expect(localStorageMock["medisa_sync_queue"]).toBeDefined();

    mockGetSession.mockReturnValue(null);
    clearAllAppPersistence();

    expect(localStorageMock["medisa_app_data"]).toBeUndefined();
    expect(localStorageMock["medisa_sync_queue"]).toBeDefined();

    const appData = getAppData();
    expect(appData.ownerFingerprint).toBeNull();
    expect(Object.keys(appData.cache)).toHaveLength(0);
  });
});
