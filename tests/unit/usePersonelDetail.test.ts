/** @vitest-environment jsdom */
import { act, renderHook, waitFor } from "@testing-library/react";
import { createElement, type ReactNode } from "react";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { APP_DATA_SCHEMA_VERSION } from "../../src/data/app-data.types";
import { dataCacheKeys, setCacheEntry } from "../../src/data/data-manager";
import { usePersonelDetail } from "../../src/hooks/usePersonelDetail";
import type { Personel } from "../../src/types/personel";

const personellerApiMock = vi.hoisted(() => ({
  createPersonel: vi.fn(),
  fetchPersonelDetail: vi.fn(),
  fetchPersonellerList: vi.fn(),
  updatePersonel: vi.fn()
}));

vi.mock("../../src/api/personeller.api", () => personellerApiMock);

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    session: {
      active_sube_id: 1
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

function makePersonel(id: number, ad: string): Personel {
  return {
    id,
    tc_kimlik_no: String(id).padStart(11, "0"),
    ad,
    soyad: "Test",
    aktif_durum: "AKTIF"
  };
}

function createDeferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, reject, resolve };
}

function wrapper({ children }: { children: ReactNode }) {
  return createElement(MemoryRouter, null, children);
}

describe("usePersonelDetail", () => {
  beforeEach(() => {
    resetAppDataCache();
    vi.clearAllMocks();
  });

  it("gec gelen eski personel cevabi guncel personel state'ini ezmez", async () => {
    const firstPersonelRequest = createDeferred<Personel>();
    const secondPersonelRequest = createDeferred<Personel>();

    personellerApiMock.fetchPersonelDetail.mockImplementation((personelId: number) => {
      if (personelId === 1) {
        return firstPersonelRequest.promise;
      }
      if (personelId === 2) {
        return secondPersonelRequest.promise;
      }
      throw new Error(`Beklenmeyen personel id: ${personelId}`);
    });

    const { result, rerender } = renderHook(
      ({ personelId }) => usePersonelDetail(personelId, true),
      {
        initialProps: { personelId: 1 },
        wrapper
      }
    );

    await waitFor(() => {
      expect(personellerApiMock.fetchPersonelDetail).toHaveBeenCalledWith(1);
    });

    rerender({ personelId: 2 });

    await waitFor(() => {
      expect(personellerApiMock.fetchPersonelDetail).toHaveBeenCalledWith(2);
    });

    await act(async () => {
      secondPersonelRequest.resolve(makePersonel(2, "Guncel"));
      await secondPersonelRequest.promise;
    });

    await waitFor(() => {
      expect(result.current.personel?.id).toBe(2);
    });

    await act(async () => {
      firstPersonelRequest.resolve(makePersonel(1, "Eski"));
      await firstPersonelRequest.promise;
    });

    expect(result.current.personel?.id).toBe(2);
    expect(result.current.editForm.ad).toBe("Guncel");
  });

  it("P1 den P2 ye geciste P2 fetch beklerken eski P1 personel state te kalmaz", async () => {
    const firstPersonelRequest = createDeferred<Personel>();
    const secondPersonelRequest = createDeferred<Personel>();

    personellerApiMock.fetchPersonelDetail.mockImplementation((personelId: number) => {
      if (personelId === 10) {
        return firstPersonelRequest.promise;
      }
      if (personelId === 11) {
        return secondPersonelRequest.promise;
      }
      throw new Error(`Beklenmeyen personel id: ${personelId}`);
    });

    const { result, rerender } = renderHook(
      ({ personelId }) => usePersonelDetail(personelId, true),
      {
        initialProps: { personelId: 10 },
        wrapper
      }
    );

    await act(async () => {
      firstPersonelRequest.resolve(makePersonel(10, "Birinci"));
      await firstPersonelRequest.promise;
    });

    await waitFor(() => {
      expect(result.current.personel?.id).toBe(10);
    });

    rerender({ personelId: 11 });

    await waitFor(() => {
      expect(personellerApiMock.fetchPersonelDetail).toHaveBeenCalledWith(11);
    });

    expect(result.current.personel).toBeNull();
    expect(result.current.editForm.ad).toBe("");

    await act(async () => {
      secondPersonelRequest.resolve(makePersonel(11, "Ikinci"));
      await secondPersonelRequest.promise;
    });
  });

  it("detailKey cache id parsedPersonelId ile uyusmuyorsa state e yazmaz", async () => {
    const personelTwentyRequest = createDeferred<Personel>();

    setCacheEntry(dataCacheKeys.personelDetail(1, 20), makePersonel(1, "YanlisId"));

    personellerApiMock.fetchPersonelDetail.mockImplementation((personelId: number) => {
      if (personelId === 20) {
        return personelTwentyRequest.promise;
      }
      throw new Error(`Beklenmeyen personel id: ${personelId}`);
    });

    const { result } = renderHook(() => usePersonelDetail(20, true), { wrapper });

    await waitFor(() => {
      expect(personellerApiMock.fetchPersonelDetail).toHaveBeenCalledWith(20);
    });

    expect(result.current.personel).toBeNull();
    expect(result.current.editForm.ad).toBe("");

    await act(async () => {
      personelTwentyRequest.resolve(makePersonel(20, "Dogru"));
      await personelTwentyRequest.promise;
    });
  });

  it("ayrilmis hook yuzeyinde veri, duzenleme ve zimmet modal alanlarini bir arada doner", async () => {
    personellerApiMock.fetchPersonelDetail.mockResolvedValue(makePersonel(5, "Test"));

    const { result } = renderHook(() => usePersonelDetail(5, true), { wrapper });

    await waitFor(() => {
      expect(result.current.personel?.id).toBe(5);
    });

    expect(result.current).toMatchObject({
      isLoading: false,
      isEditing: false,
      isZimmetModalOpen: false,
      surecHistory: [],
      zimmetHistory: []
    });
    expect(result.current).not.toHaveProperty("isSurecModalOpen");
    expect(result.current).not.toHaveProperty("openSurecModal");
  });
});
