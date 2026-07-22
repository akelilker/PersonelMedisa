// @vitest-environment jsdom

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const {
  fetchPersonellerList,
  fetchBildirimlerList,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchBagliAmirOptions,
  fetchUcretTipiOptions,
  fetchPrimKuraliOptions,
  fetchSurecTuruOptions,
  fetchBildirimTuruOptions,
  getTokenMock,
  getActiveSubeIdMock
} = vi.hoisted(() => ({
  fetchPersonellerList: vi.fn(async () => ({ items: [], meta: { page: 1, limit: 10, total: 0 } })),
  fetchBildirimlerList: vi.fn(async () => ({ items: [], meta: { page: 1, limit: 8, total: 0 } })),
  fetchDepartmanOptions: vi.fn(async () => []),
  fetchGorevOptions: vi.fn(async () => []),
  fetchPersonelTipiOptions: vi.fn(async () => []),
  fetchBagliAmirOptions: vi.fn(async () => []),
  fetchUcretTipiOptions: vi.fn(async () => []),
  fetchPrimKuraliOptions: vi.fn(async () => []),
  fetchSurecTuruOptions: vi.fn(async () => []),
  fetchBildirimTuruOptions: vi.fn(async () => []),
  getTokenMock: vi.fn<() => string | null>(() => null),
  getActiveSubeIdMock: vi.fn<() => number | null>(() => null)
}));

vi.mock("../../src/api/personeller.api", () => ({
  fetchPersonellerList,
  createPersonel: vi.fn(),
  updatePersonel: vi.fn()
}));

vi.mock("../../src/api/bildirimler.api", () => ({
  fetchBildirimlerList,
  createBildirim: vi.fn(),
  updateBildirim: vi.fn(),
  cancelBildirim: vi.fn()
}));

vi.mock("../../src/api/referans.api", () => ({
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchBagliAmirOptions,
  fetchUcretTipiOptions,
  fetchPrimKuraliOptions,
  fetchSurecTuruOptions,
  fetchBildirimTuruOptions
}));

vi.mock("../../src/api/finans.api", () => ({
  createFinansKalem: vi.fn(),
  updateFinansKalem: vi.fn(),
  cancelFinansKalem: vi.fn()
}));

vi.mock("../../src/api/puantaj.api", () => ({
  upsertGunlukPuantaj: vi.fn()
}));

vi.mock("../../src/api/surecler.api", () => ({
  createSurec: vi.fn(),
  updateSurec: vi.fn(),
  cancelSurec: vi.fn()
}));

vi.mock("../../src/auth/auth-manager", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/auth/auth-manager")>();
  return {
    ...actual,
    getToken: getTokenMock,
    getActiveSubeId: getActiveSubeIdMock
  };
});

import {
  attachConnectivityListeners,
  loadDataFromServer,
  resetProtectedDataLoadGate
} from "../../src/data/data-manager";

describe("S90 loadDataFromServer auth gate", () => {
  beforeEach(() => {
    resetProtectedDataLoadGate();
    getTokenMock.mockReset();
    getActiveSubeIdMock.mockReset();
    getTokenMock.mockReturnValue(null);
    getActiveSubeIdMock.mockReturnValue(null);
    fetchPersonellerList.mockClear();
    fetchBildirimlerList.mockClear();
    fetchDepartmanOptions.mockClear();
    fetchGorevOptions.mockClear();
    fetchPersonelTipiOptions.mockClear();
    fetchBagliAmirOptions.mockClear();
    fetchUcretTipiOptions.mockClear();
    fetchPrimKuraliOptions.mockClear();
    fetchSurecTuruOptions.mockClear();
    fetchBildirimTuruOptions.mockClear();
  });

  afterEach(() => {
    resetProtectedDataLoadGate();
    vi.restoreAllMocks();
  });

  it("token yokken hiçbir protected API task oluşturmaz", async () => {
    getTokenMock.mockReturnValue(null);
    await loadDataFromServer();
    expect(fetchPersonellerList).not.toHaveBeenCalled();
    expect(fetchBildirimlerList).not.toHaveBeenCalled();
    expect(fetchDepartmanOptions).not.toHaveBeenCalled();
    expect(fetchSurecTuruOptions).not.toHaveBeenCalled();
    expect(fetchBildirimTuruOptions).not.toHaveBeenCalled();
  });

  it("token varken bootstrap endpoint'lerini bir kez çağırır", async () => {
    getTokenMock.mockReturnValue("tok-1");
    await loadDataFromServer({ force: true });
    expect(fetchPersonellerList).toHaveBeenCalled();
    expect(fetchBildirimlerList).toHaveBeenCalledTimes(1);
    expect(fetchSurecTuruOptions).toHaveBeenCalledTimes(1);
  });

  it("aynı signature ile ikinci çağrı duplicate request üretmez", async () => {
    getTokenMock.mockReturnValue("tok-dup");
    await loadDataFromServer({ force: true });
    const personelCalls = fetchPersonellerList.mock.calls.length;
    const bildirimCalls = fetchBildirimlerList.mock.calls.length;
    await loadDataFromServer();
    expect(fetchPersonellerList.mock.calls.length).toBe(personelCalls);
    expect(fetchBildirimlerList.mock.calls.length).toBe(bildirimCalls);
  });

  it("StrictMode benzeri eşzamanlı çağrıları tek inflight'ta birleştirir", async () => {
    getTokenMock.mockReturnValue("tok-race");
    resetProtectedDataLoadGate();
    await Promise.all([loadDataFromServer({ force: true }), loadDataFromServer({ force: true })]);
    expect(fetchBildirimlerList).toHaveBeenCalledTimes(1);
  });

  it("online event token yokken protected istek açmaz", async () => {
    getTokenMock.mockReturnValue(null);
    const detach = attachConnectivityListeners();
    window.dispatchEvent(new Event("online"));
    await Promise.resolve();
    expect(fetchPersonellerList).not.toHaveBeenCalled();
    expect(fetchBildirimlerList).not.toHaveBeenCalled();
    detach();
  });

  it("online event token varken force refresh yapar", async () => {
    getTokenMock.mockReturnValue("tok-online");
    await loadDataFromServer({ force: true });
    fetchBildirimlerList.mockClear();
    const detach = attachConnectivityListeners();
    window.dispatchEvent(new Event("online"));
    await vi.waitFor(() => {
      expect(fetchBildirimlerList).toHaveBeenCalled();
    });
    detach();
  });
});
