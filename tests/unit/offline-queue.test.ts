import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import {
  STALE_PROCESSING_MS,
  clearAllAppPersistence,
  enqueueSyncOperation,
  initAppDataFromStorage,
  processSyncQueue,
  recoverStaleProcessingItems,
  resumeAuthBlockedQueueForCurrentActor,
} from "../../src/data/data-manager";
import type { AuthSession } from "../../src/types/auth";
import { getActorFingerprint } from "../../src/auth/auth-manager";

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

const USER_A: AuthSession = {
  token: "user-a-token",
  ui_profile: "yonetim",
  user: { id: 1, ad_soyad: "User A", rol: "GENEL_YONETICI", sube_ids: [1] },
};

const USER_B: AuthSession = {
  token: "user-b-token",
  ui_profile: "yonetim",
  user: { id: 2, ad_soyad: "User B", rol: "GENEL_YONETICI", sube_ids: [1] },
};

describe("offline-queue lifecycle", () => {
  let localStorageMock: Record<string, string> = {};
  let quotaFail = false;

  beforeEach(() => {
    localStorageMock = {};
    quotaFail = false;
    vi.stubGlobal("window", {
      localStorage: {
        getItem: (key: string) => localStorageMock[key] ?? null,
        setItem: (key: string, value: string) => {
          if (quotaFail) {
            throw new DOMException("QuotaExceededError");
          }
          localStorageMock[key] = value;
        },
        removeItem: (key: string) => {
          delete localStorageMock[key];
        },
      },
      appData: undefined,
      navigator: { onLine: true },
    });
    mockGetSession.mockReturnValue(USER_A);
    mockCreatePersonel.mockClear();
    initAppDataFromStorage();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("401 → BLOCKED_AUTH; same-actor re-auth resumes with stable Idempotency-Key", async () => {
    const enq = enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    expect(enq).toBe("queued");
    const before = JSON.parse(localStorageMock["medisa_sync_queue"]);
    const stableId = before[0].id as string;

    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Unauthorized", 401));
    await processSyncQueue();

    let queue = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(queue[0].state).toBe("BLOCKED_AUTH");
    expect(queue[0].id).toBe(stableId);

    // logout clears cache but keeps queue
    clearAllAppPersistence();
    expect(localStorageMock["medisa_sync_queue"]).toBeTruthy();

    mockGetSession.mockReturnValue(USER_A);
    initAppDataFromStorage();
    const resumed = resumeAuthBlockedQueueForCurrentActor();
    expect(resumed).toBe(1);

    mockCreatePersonel.mockResolvedValue({ id: 99 });
    mockCreatePersonel.mockClear();
    await processSyncQueue();

    expect(mockCreatePersonel).toHaveBeenCalledTimes(1);
    expect(mockCreatePersonel.mock.calls[0]?.[1]).toEqual({ idempotencyKey: stableId });
    queue = JSON.parse(localStorageMock["medisa_sync_queue"] ?? "[]");
    expect(queue.filter((i: { id: string }) => i.id === stableId)).toHaveLength(0);
  });

  it("403 → BLOCKED_PERMISSION and is not auto-resumed after login", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Forbidden", 403));
    await processSyncQueue();

    const queue = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(queue[0].state).toBe("BLOCKED_PERMISSION");

    const resumed = resumeAuthBlockedQueueForCurrentActor();
    expect(resumed).toBe(0);
    expect(JSON.parse(localStorageMock["medisa_sync_queue"])[0].state).toBe("BLOCKED_PERMISSION");
  });

  it("cross-user login never resumes other actor BLOCKED_AUTH", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Unauthorized", 401));
    await processSyncQueue();

    const fpA = getActorFingerprint(USER_A);
    expect(JSON.parse(localStorageMock["medisa_sync_queue"])[0].ownerFingerprint).toBe(fpA);

    mockGetSession.mockReturnValue(USER_B);
    initAppDataFromStorage();
    expect(resumeAuthBlockedQueueForCurrentActor()).toBe(0);

    mockCreatePersonel.mockClear();
    await processSyncQueue();
    expect(mockCreatePersonel).not.toHaveBeenCalled();

    // A's item still present in raw storage
    const raw = JSON.parse(localStorageMock["medisa_sync_queue"]);
    expect(raw.some((i: { ownerFingerprint: string; state: string }) => i.ownerFingerprint === fpA && i.state === "BLOCKED_AUTH")).toBe(
      true
    );
  });

  it("stale PROCESSING recovers to FAILED_RETRYABLE with same id", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    const id = JSON.parse(localStorageMock["medisa_sync_queue"])[0].id as string;
    const fp = getActorFingerprint(USER_A)!;
    const staleAt = new Date(Date.now() - STALE_PROCESSING_MS - 1_000).toISOString();
    localStorageMock["medisa_sync_queue"] = JSON.stringify([
      {
        id,
        op: "personeller.create",
        payload: {},
        createdAt: staleAt,
        ownerFingerprint: fp,
        state: "PROCESSING",
        attemptCount: 1,
        lastAttemptAt: staleAt,
        lastError: null,
      },
    ]);

    const recovered = recoverStaleProcessingItems(Date.now());
    expect(recovered).toBe(1);
    const item = JSON.parse(localStorageMock["medisa_sync_queue"])[0];
    expect(item.state).toBe("FAILED_RETRYABLE");
    expect(item.id).toBe(id);

    mockCreatePersonel.mockResolvedValue({ id: 1 });
    await processSyncQueue();
    expect(mockCreatePersonel).toHaveBeenCalled();
  });

  it("fresh PROCESSING is not recovered", () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    const id = JSON.parse(localStorageMock["medisa_sync_queue"])[0].id as string;
    const fp = getActorFingerprint(USER_A)!;
    const freshAt = new Date().toISOString();
    localStorageMock["medisa_sync_queue"] = JSON.stringify([
      {
        id,
        op: "personeller.create",
        payload: {},
        createdAt: freshAt,
        ownerFingerprint: fp,
        state: "PROCESSING",
        attemptCount: 1,
        lastAttemptAt: freshAt,
        lastError: null,
      },
    ]);
    expect(recoverStaleProcessingItems(Date.now())).toBe(0);
    expect(JSON.parse(localStorageMock["medisa_sync_queue"])[0].state).toBe("PROCESSING");
  });

  it("PENDING→PROCESSING quota failure does not dispatch mutation", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    quotaFail = true;
    mockCreatePersonel.mockClear();
    await processSyncQueue();
    expect(mockCreatePersonel).not.toHaveBeenCalled();
  });

  it("success prunes COMPLETED payload (no accumulation)", async () => {
    for (let i = 0; i < 100; i++) {
      enqueueSyncOperation({ op: "personeller.create", payload: { i } as any });
    }
    mockCreatePersonel.mockResolvedValue({ id: 1 });
    // process in batches — each successful item is removed
    for (let i = 0; i < 100; i++) {
      await processSyncQueue();
    }
    const queue = JSON.parse(localStorageMock["medisa_sync_queue"] ?? "[]");
    expect(queue.filter((i: { state: string }) => i.state === "COMPLETED")).toHaveLength(0);
    expect(queue).toHaveLength(0);
  });

  it("409 → CONFLICT", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Conflict", 409));
    await processSyncQueue();
    expect(JSON.parse(localStorageMock["medisa_sync_queue"])[0].state).toBe("CONFLICT");
  });

  it("500 → FAILED_RETRYABLE then DEAD_LETTER after max attempts", async () => {
    enqueueSyncOperation({ op: "personeller.create", payload: {} as any });
    mockCreatePersonel.mockRejectedValue(new ApiRequestError("Server Error", 500));
    for (let i = 0; i < 5; i++) {
      await processSyncQueue();
    }
    const item = JSON.parse(localStorageMock["medisa_sync_queue"])[0];
    expect(item.state).toBe("DEAD_LETTER");
    expect(item.attemptCount).toBe(5);
  });
});
