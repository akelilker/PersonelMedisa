/** @vitest-environment jsdom */
import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useHaftalikBildirimMutabakat } from "../../src/hooks/useHaftalikBildirimMutabakat";
import type { HaftalikBildirimMutabakatOzet } from "../../src/types/haftalik-bildirim-mutabakat";

const fetchOzetMock = vi.hoisted(() => vi.fn());
const approveMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/haftalik-bildirim-mutabakatlari.api", () => ({
  fetchHaftalikBildirimMutabakatOzet: fetchOzetMock,
  approveHaftalikBildirimMutabakat: approveMock
}));

function makeOzet(overrides: Partial<HaftalikBildirimMutabakatOzet> = {}): HaftalikBildirimMutabakatOzet {
  return {
    hafta_baslangic: "2026-04-06",
    hafta_bitis: "2026-04-12",
    sube_id: 1,
    birim_amiri_user_id: 1,
    counts: {
      toplam: 1,
      taslak: 0,
      gonderildi: 1,
      duzeltme_istendi: 0,
      haftalik_mutabakata_alindi: 0,
      iptal: 0
    },
    onaylanabilir_mi: true,
    blok_nedeni: null,
    mevcut_mutabakat_id: null,
    ...overrides
  };
}

describe("useHaftalikBildirimMutabakat", () => {
  beforeEach(() => {
    fetchOzetMock.mockReset();
    approveMock.mockReset();
    fetchOzetMock.mockResolvedValue(makeOzet());
    approveMock.mockResolvedValue({ mutabakat: { id: 1 } });
  });

  it("baglam tamamlanmadan ozet istegi gondermez", () => {
    const { result } = renderHook(() =>
      useHaftalikBildirimMutabakat({ enabled: false, subeId: null, birimAmiriUserId: null })
    );

    expect(fetchOzetMock).not.toHaveBeenCalled();
    expect(result.current.ozet).toBeNull();
  });

  it("secilen panel baglamini ozet istegine tasir", async () => {
    renderHook(() =>
      useHaftalikBildirimMutabakat({ enabled: true, subeId: 2, birimAmiriUserId: 7 })
    );

    await waitFor(() => expect(fetchOzetMock).toHaveBeenCalled());
    expect(fetchOzetMock.mock.calls[0]?.[1]).toEqual({ subeId: 2, birimAmiriUserId: 7 });
  });

  it("gecerli Pazartesi seciminde ozet yukler", async () => {
    const { result } = renderHook(() => useHaftalikBildirimMutabakat());

    await waitFor(() => expect(result.current.ozet).not.toBeNull());
    expect(fetchOzetMock).toHaveBeenCalled();
  });

  it("Pazartesi olmayan tarihte uyari gosterir ve ozet cekmez", async () => {
    const { result } = renderHook(() => useHaftalikBildirimMutabakat());

    await waitFor(() => expect(fetchOzetMock).toHaveBeenCalled());

    act(() => {
      result.current.setHaftaBaslangic("2026-04-07");
    });

    expect(result.current.weekWarning).toContain("Pazartesi");
    expect(result.current.ozet).toBeNull();
  });

  it("approveWeek sonrasi ozeti yeniler", async () => {
    fetchOzetMock
      .mockResolvedValueOnce(makeOzet())
      .mockResolvedValueOnce(
        makeOzet({
          onaylanabilir_mi: false,
          mevcut_mutabakat_id: 9,
          counts: {
            toplam: 1,
            taslak: 0,
            gonderildi: 0,
            duzeltme_istendi: 0,
            haftalik_mutabakata_alindi: 1,
            iptal: 0
          }
        })
      );

    const onApproved = vi.fn();
    const { result } = renderHook(() =>
      useHaftalikBildirimMutabakat({ onApproved })
    );

    await waitFor(() => expect(result.current.ozet?.onaylanabilir_mi).toBe(true));

    await act(async () => {
      await result.current.approveWeek();
    });

    expect(approveMock).toHaveBeenCalled();
    expect(fetchOzetMock).toHaveBeenCalledTimes(2);
    expect(onApproved).toHaveBeenCalled();
    expect(result.current.ozet?.mevcut_mutabakat_id).toBe(9);
  });
});
