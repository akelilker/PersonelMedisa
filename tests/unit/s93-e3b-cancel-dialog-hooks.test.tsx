// @vitest-environment jsdom

import { act, renderHook, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { APP_DATA_SCHEMA_VERSION } from "../../src/data/app-data.types";
import { dataCacheKeys, setCacheEntry } from "../../src/data/data-manager";
import { useBildirimler } from "../../src/hooks/useBildirimler";
import { useFinans } from "../../src/hooks/useFinans";
import { useSurecler } from "../../src/hooks/useSurecler";
import type { PaginatedResult } from "../../src/types/api";
import type { Bildirim } from "../../src/types/bildirim";
import type { FinansKalem } from "../../src/types/finans";
import type { Surec } from "../../src/types/surec";

const cancelSurecMock = vi.hoisted(() => vi.fn());
const fetchSureclerListMock = vi.hoisted(() => vi.fn());
const fetchSurecTuruOptionsMock = vi.hoisted(() => vi.fn());
const cancelFinansKalemMock = vi.hoisted(() => vi.fn());
const fetchFinansKalemListMock = vi.hoisted(() => vi.fn());
const cancelBildirimMock = vi.hoisted(() => vi.fn());
const fetchBildirimlerListMock = vi.hoisted(() => vi.fn());
const fetchDepartmanOptionsMock = vi.hoisted(() => vi.fn());
const fetchBildirimTuruOptionsMock = vi.hoisted(() => vi.fn());
const fetchPersonellerListMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/surecler.api", () => ({
  cancelSurec: cancelSurecMock,
  createSurec: vi.fn(),
  fetchSurecDetail: vi.fn(),
  fetchSureclerList: fetchSureclerListMock,
  updateSurec: vi.fn()
}));

vi.mock("../../src/api/referans.api", () => ({
  fetchSurecTuruOptions: fetchSurecTuruOptionsMock,
  fetchDepartmanOptions: fetchDepartmanOptionsMock,
  fetchBildirimTuruOptions: fetchBildirimTuruOptionsMock
}));

vi.mock("../../src/api/finans.api", () => ({
  cancelFinansKalem: cancelFinansKalemMock,
  fetchFinansKalemList: fetchFinansKalemListMock,
  updateFinansKalem: vi.fn()
}));

vi.mock("../../src/api/bildirimler.api", () => ({
  cancelBildirim: cancelBildirimMock,
  createBildirim: vi.fn(),
  fetchBildirimDetail: vi.fn(),
  fetchBildirimlerList: fetchBildirimlerListMock,
  markBildirimOkundu: vi.fn(),
  requestBildirimCorrection: vi.fn(),
  submitBildirim: vi.fn(),
  updateBildirim: vi.fn()
}));

vi.mock("../../src/api/personeller.api", () => ({
  fetchPersonellerList: fetchPersonellerListMock
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    session: {
      active_sube_id: 1,
      user: { id: 10 }
    }
  })
}));

function resetAppDataCache(): void {
  window.appData = {
    schemaVersion: APP_DATA_SCHEMA_VERSION,
    revision: 0,
    updatedAt: null,
    cache: {}
  };
}

function paginated<T>(items: T[]): PaginatedResult<T> {
  return {
    items,
    pagination: {
      page: 1,
      limit: 10,
      total: items.length,
      total_pages: 1,
      hasNextPage: false
    }
  };
}

function makeSurec(overrides: Partial<Surec> = {}): Surec {
  return {
    id: 501,
    personel_id: 1,
    surec_turu: "IZIN",
    alt_tur: "YILLIK_IZIN",
    baslangic_tarihi: "2026-04-10",
    bitis_tarihi: "2026-04-11",
    ucretli_mi: true,
    ilk_iki_gun_firma_oder_mi: null,
    aciklama: "Test",
    state: "AKTIF",
    ...overrides
  };
}

function makeFinansItem(overrides: Partial<FinansKalem> = {}): FinansKalem {
  return {
    id: 901,
    personel_id: 1,
    donem: "2026-04",
    kalem_turu: "AVANS",
    tutar: 2500,
    aciklama: "Test",
    state: "AKTIF",
    ...overrides
  };
}

function makeBildirim(overrides: Partial<Bildirim> = {}): Bildirim {
  return {
    id: 701,
    tarih: "2026-04-09",
    departman_id: 3,
    personel_id: 1,
    bildirim_turu: "GEC_GELDI",
    aciklama: "Test",
    state: "TASLAK",
    okundu_mi: false,
    created_by: 10,
    ...overrides
  };
}

describe("S93-E3B cancel dialog hooks", () => {
  beforeEach(() => {
    resetAppDataCache();
    vi.clearAllMocks();
    fetchSureclerListMock.mockResolvedValue(paginated([makeSurec()]));
    fetchSurecTuruOptionsMock.mockResolvedValue([]);
    fetchFinansKalemListMock.mockResolvedValue(paginated([makeFinansItem()]));
    fetchBildirimlerListMock.mockResolvedValue(paginated([makeBildirim()]));
    fetchDepartmanOptionsMock.mockResolvedValue([]);
    fetchBildirimTuruOptionsMock.mockResolvedValue([]);
    fetchPersonellerListMock.mockResolvedValue(paginated([]));
    cancelSurecMock.mockResolvedValue({ id: 501, state: "IPTAL" });
    cancelFinansKalemMock.mockResolvedValue({ id: 901, state: "IPTAL" });
    cancelBildirimMock.mockResolvedValue({ id: 701, state: "IPTAL" });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("useSurecler: dialog açar, vazgeç API çağırmaz, onay tek cancel çağırır", async () => {
    const { result } = renderHook(() => useSurecler());

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    const surec = makeSurec();
    act(() => {
      result.current.openCancelSurecDialog(surec, true);
    });

    expect(result.current.pendingCancelSurec).toEqual(surec);
    expect(cancelSurecMock).not.toHaveBeenCalled();

    act(() => {
      result.current.closeCancelSurecDialog();
    });
    expect(result.current.pendingCancelSurec).toBeNull();

    act(() => {
      result.current.openCancelSurecDialog(surec, true);
    });

    await act(async () => {
      await result.current.confirmCancelSurec();
    });

    expect(cancelSurecMock).toHaveBeenCalledTimes(1);
    expect(cancelSurecMock).toHaveBeenCalledWith(501);
    expect(result.current.pendingCancelSurec).toBeNull();
  });

  it("useSurecler: cancel hatasında dialog açık kalır ve hata mesajı korunur", async () => {
    cancelSurecMock.mockRejectedValueOnce(new Error("İptal başarısız"));
    const { result } = renderHook(() => useSurecler());
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    const surec = makeSurec();
    act(() => {
      result.current.openCancelSurecDialog(surec, true);
    });

    await act(async () => {
      await result.current.confirmCancelSurec();
    });

    expect(result.current.pendingCancelSurec).toEqual(surec);
    expect(result.current.cancelDialogError).toBeTruthy();
  });

  it("useFinans: dialog açar ve confirm tek cancelFinansKalem çağırır", async () => {
    const { result } = renderHook(() => useFinans());
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    const item = makeFinansItem();
    act(() => {
      result.current.openCancelFinansDialog(item, true);
    });

    expect(result.current.pendingCancelItem).toEqual(item);

    await act(async () => {
      await result.current.confirmCancelFinans();
    });

    expect(cancelFinansKalemMock).toHaveBeenCalledTimes(1);
    expect(cancelFinansKalemMock).toHaveBeenCalledWith(901);
    expect(result.current.pendingCancelItem).toBeNull();
  });

  it("useFinans: yetkisiz açılışta dialog açılmaz", async () => {
    const { result } = renderHook(() => useFinans());
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.openCancelFinansDialog(makeFinansItem(), false);
    });

    expect(result.current.pendingCancelItem).toBeNull();
    expect(result.current.errorMessage).toContain("yetkin");
  });

  it("useBildirimler: dialog açar ve confirm tek cancelBildirim çağırır", async () => {
    const { result } = renderHook(() => useBildirimler());
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    const bildirim = makeBildirim();
    act(() => {
      result.current.openCancelBildirimDialog(bildirim, true);
    });

    expect(result.current.pendingCancelBildirim).toEqual(bildirim);

    await act(async () => {
      await result.current.confirmCancelBildirim();
    });

    expect(cancelBildirimMock).toHaveBeenCalledTimes(1);
    expect(cancelBildirimMock).toHaveBeenCalledWith(701);
    expect(result.current.pendingCancelBildirim).toBeNull();
  });

  it("useBildirimler: vazgeç API çağırmaz", async () => {
    const { result } = renderHook(() => useBildirimler());
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => {
      result.current.openCancelBildirimDialog(makeBildirim(), true);
      result.current.closeCancelBildirimDialog();
    });

    expect(cancelBildirimMock).not.toHaveBeenCalled();
    expect(result.current.pendingCancelBildirim).toBeNull();
  });
});
