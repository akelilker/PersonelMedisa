import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import { getActorFingerprint, getSession } from "../../src/auth/auth-manager";
import {
  enqueueSyncOperation,
  initAppDataFromStorage,
  processSyncQueue,
} from "../../src/data/data-manager";
import type { AuthSession } from "../../src/types/auth";
import { createPersonel } from "../../src/api/personeller.api";

const { mockGetSession, mockCreatePersonel } = vi.hoisted(() => ({
  mockGetSession: vi.fn<[], AuthSession | null>(),
  mockCreatePersonel: vi.fn(),
}));

vi.mock("../../src/auth/auth-manager", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../../src/auth/auth-manager")>()),
  getSession: mockGetSession,
}));

vi.mock("../../src/api/personeller.api", () => ({
  createPersonel: mockCreatePersonel,
}));

const USER_SESSION: AuthSession = {
  token: "user-token",
  ui_profile: "yonetim",
  user: { id: 1, ad_soyad: "Test User", rol: "GENEL_YONETICI", sube_ids: [1] },
};

describe("offline-queue state machine", () => {
  let localStorageMock: Record<string, string> = {};

  beforeEach(() => {
    localStorageMock = {};
    vi.stubGlobal("window", {
      localStorage: {
        getItem: (key: string) => localStorageMock[key] ?? null,
        setItem: (key: string, value: string) => {
          localStorageMock[key] = value;
        },
        removeItem: (key: string) => delete localStorageMock[key],
      },
      appData: undefined,
    });
    mockGetSession.mockReturnValue(USER_SESSION);
    mockCreatePersonel.mockClear();
    initAppDataFromStorage();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("should move item to BLOCKED_AUTH on 401 Unauthorized and not delete it", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    
    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Unauthorized", 401));

    await processSyncQueue();

    const queue = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(queue).toHaveLength(1);
    const item = queue[0];
    expect(item.state).toBe("BLOCKED_AUTH");
    expect(item.attemptCount).toBe(1);
    expect(item.lastError).toEqual({
      status: 401,
      message: "Unauthorized",
      code: undefined,
    });
  });

  it("should move item to BLOCKED_AUTH on 403 Forbidden and not delete it", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    
    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Forbidden", 403));

    await processSyncQueue();

    const queue = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(queue).toHaveLength(1);
    const item = queue[0];
    expect(item.state).toBe("BLOCKED_AUTH");
    expect(item.lastError?.status).toBe(403);
  });

  it("should move item to CONFLICT on 409 Conflict", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });

    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Conflict", 409));

    await processSyncQueue();

    const queue = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(queue).toHaveLength(1);
    expect(queue[0].state).toBe("CONFLICT");
  });

  it("should move item to FAILED_RETRYABLE on 500 server error", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    
    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Server Error", 500));

    await processSyncQueue();

    const queue = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(queue).toHaveLength(1);
    expect(queue[0].state).toBe("FAILED_RETRYABLE");
    expect(queue[0].attemptCount).toBe(1);
  });
  
  it("should move item to DEAD_LETTER after max attempts", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    
    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Server Error", 500));

    // Simulate MAX_ATTEMPTS failures
    for (let i = 0; i < 5; i++) {
        await processSyncQueue();
    }

    const queue = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(queue).toHaveLength(1);
    const item = queue[0];
    expect(item.state).toBe("DEAD_LETTER");
    expect(item.attemptCount).toBe(5);
  });

  it("should mark item as COMPLETED on success", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    
    mockCreatePersonel.mockResolvedValue({ id: 123 });

    await processSyncQueue();

    const queue = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(queue).toHaveLength(1);
    expect(queue[0].state).toBe("COMPLETED");
  });
});