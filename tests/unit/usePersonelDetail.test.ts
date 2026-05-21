/** @vitest-environment jsdom */
import { act, renderHook, waitFor } from "@testing-library/react";
import { createElement, type ReactNode } from "react";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { APP_DATA_SCHEMA_VERSION } from "../../src/data/app-data.types";
import { usePersonelDetail } from "../../src/hooks/usePersoneller";
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
});
