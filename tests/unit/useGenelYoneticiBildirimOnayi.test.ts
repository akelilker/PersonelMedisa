/** @vitest-environment jsdom */
import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import { useGenelYoneticiBildirimOnayi } from "../../src/hooks/useGenelYoneticiBildirimOnayi";
import type { GenelYoneticiBildirimOnayiOzet } from "../../src/types/genel-yonetici-bildirim-onayi";

const fetchMock = vi.hoisted(() => vi.fn());
const approveMock = vi.hoisted(() => vi.fn());
vi.mock("../../src/api/genel-yonetici-bildirim-onaylari.api", () => ({
  fetchGenelYoneticiBildirimOnayiOzet: fetchMock,
  approveGenelYoneticiBildirimOnayi: approveMock
}));

function makeOzet(overrides: Partial<GenelYoneticiBildirimOnayiOzet> = {}): GenelYoneticiBildirimOnayiOzet {
  return {
    ay: "2026-06", ay_baslangic: "2026-06-01", ay_bitis: "2026-06-30",
    sube_id: 1, birim_amiri_user_id: 3,
    counts: { toplam_bildirim: 1, mutabakata_alinan: 1, eksik_hafta: 0 },
    aylik_bildirim_onayi: { id: 2, state: "TAMAMLANDI", onaylandi_at: null },
    genel_yonetici_bildirim_onayi: null,
    onay_verilebilir_mi: true, blok_nedeni: null, ...overrides
  };
}

const ready = { canView: true, canApprove: true, ay: "2026-06", subeId: 1, birimAmiriUserId: 3 };

describe("useGenelYoneticiBildirimOnayi", () => {
  beforeEach(() => {
    fetchMock.mockReset().mockResolvedValue(makeOzet());
    approveMock.mockReset().mockResolvedValue({ id: 10 });
  });

  it.each([
    [{ ...ready, canView: false }, "canView false"],
    [{ ...ready, subeId: null }, "sube eksik"],
    [{ ...ready, birimAmiriUserId: null }, "BA eksik"]
  ])("%s durumda fetch yapmaz", (options) => {
    const { result } = renderHook(() => useGenelYoneticiBildirimOnayi(options));
    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.ozet).toBeNull();
  });

  it("hazir baglamda fetch yapar ve context degisince eski sonucu temizler", async () => {
    const { result, rerender } = renderHook(
      ({ options }) => useGenelYoneticiBildirimOnayi(options),
      { initialProps: { options: ready } }
    );
    await waitFor(() => expect(result.current.ozet).not.toBeNull());
    rerender({ options: { ...ready, subeId: null } });
    expect(result.current.ozet).toBeNull();
  });

  it("eski response yeni baglama yazilmaz", async () => {
    let resolveFirst!: (value: GenelYoneticiBildirimOnayiOzet) => void;
    fetchMock
      .mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }))
      .mockResolvedValueOnce(makeOzet({ sube_id: 2, birim_amiri_user_id: 4 }));
    const { result, rerender } = renderHook(
      ({ options }) => useGenelYoneticiBildirimOnayi(options),
      { initialProps: { options: ready } }
    );
    rerender({ options: { ...ready, subeId: 2, birimAmiriUserId: 4 } });
    await waitFor(() => expect(result.current.ozet?.sube_id).toBe(2));
    await act(async () => resolveFirst(makeOzet()));
    expect(result.current.ozet?.sube_id).toBe(2);
  });

  it("approve basarisinda refetch yapar ve cift submiti engeller", async () => {
    let resolveApprove!: () => void;
    approveMock.mockImplementation(() => new Promise<void>((resolve) => { resolveApprove = resolve; }));
    const { result } = renderHook(() => useGenelYoneticiBildirimOnayi(ready));
    await waitFor(() => expect(result.current.ozet).not.toBeNull());
    let first!: Promise<void>;
    act(() => { first = result.current.approve(); void result.current.approve(); });
    expect(approveMock).toHaveBeenCalledTimes(1);
    await act(async () => { resolveApprove(); await first; });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(result.current.successMessage).toBe("Genel Yönetici bildirim onayı tamamlandı.");
  });

  it("duplicate 409 sonrasinda summary refetch yapar", async () => {
    approveMock.mockRejectedValueOnce(
      new ApiRequestError("duplicate", 409, { code: "GENEL_YONETICI_BILDIRIM_ONAYI_MEVCUT" })
    );
    fetchMock
      .mockResolvedValueOnce(makeOzet())
      .mockResolvedValueOnce(makeOzet({
        onay_verilebilir_mi: false,
        blok_nedeni: "ZATEN_ONAYLANDI",
        genel_yonetici_bildirim_onayi: {
          id: 9, state: "TAMAMLANDI", onaylayan_user_id: 1, onaylandi_at: null, aciklama: null
        }
      }));
    const { result } = renderHook(() => useGenelYoneticiBildirimOnayi(ready));
    await waitFor(() => expect(result.current.ozet?.onay_verilebilir_mi).toBe(true));
    await act(async () => { await result.current.approve(); });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(result.current.ozet?.genel_yonetici_bildirim_onayi?.id).toBe(9);
    expect(result.current.error).toBeNull();
  });
});
