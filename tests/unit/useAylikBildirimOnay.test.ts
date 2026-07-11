/** @vitest-environment jsdom */
import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useAylikBildirimOnay } from "../../src/hooks/useAylikBildirimOnay";
import type { AylikBildirimOnayOzet } from "../../src/types/aylik-bildirim-onay";

const fetchOzetMock = vi.hoisted(() => vi.fn());
const approveMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/aylik-bildirim-onaylari.api", () => ({
  fetchAylikBildirimOnayiOzet: fetchOzetMock,
  approveAylikBildirimOnayi: approveMock
}));

function makeOzet(overrides: Partial<AylikBildirimOnayOzet> = {}): AylikBildirimOnayOzet {
  return {
    ay: "2026-07",
    ay_baslangic: "2026-07-01",
    ay_bitis: "2026-07-31",
    sube_id: 1,
    birim_amiri_user_id: 1,
    haftalar: [],
    counts: {
      toplam_bildirim: 1,
      mutabakata_alinan: 1,
      mutabakatli_hafta: 1,
      eksik_hafta: 0,
      taslak: 0,
      duzeltme_istendi: 0,
      gonderildi: 0
    },
    onaylanabilir_mi: true,
    blok_nedeni: null,
    mevcut_onay_id: null,
    ...overrides
  };
}

describe("useAylikBildirimOnay", () => {
  beforeEach(() => {
    fetchOzetMock.mockReset();
    approveMock.mockReset();
    fetchOzetMock.mockResolvedValue(makeOzet());
    approveMock.mockResolvedValue({ onay: { id: 1 } });
  });

  it("gecerli ay seciminde ozet yukler", async () => {
    const { result } = renderHook(() => useAylikBildirimOnay());

    await waitFor(() => expect(result.current.ozet).not.toBeNull());
    expect(fetchOzetMock).toHaveBeenCalled();
  });

  it("gecersiz ayda uyari gosterir ve ozet cekmez", async () => {
    const { result } = renderHook(() => useAylikBildirimOnay());

    await waitFor(() => expect(fetchOzetMock).toHaveBeenCalled());

    act(() => {
      result.current.setAy("2026-13");
    });

    expect(result.current.ayWarning).toContain("YYYY-MM");
    expect(result.current.ozet).toBeNull();
  });

  it("mevcut_onay_id varken approveMonth cagrisi yapmaz", async () => {
    fetchOzetMock.mockResolvedValue(makeOzet({ mevcut_onay_id: 1, onaylanabilir_mi: false }));

    const { result } = renderHook(() => useAylikBildirimOnay());

    await waitFor(() => expect(result.current.ozet?.mevcut_onay_id).toBe(1));

    await act(async () => {
      await result.current.approveMonth();
    });

    expect(approveMock).not.toHaveBeenCalled();
  });

  it("onaylanabilir_mi true iken approveMonth ozeti yeniler", async () => {
    fetchOzetMock
      .mockResolvedValueOnce(makeOzet())
      .mockResolvedValueOnce(makeOzet({ mevcut_onay_id: 2, onaylanabilir_mi: false }));

    const onApproved = vi.fn();
    const { result } = renderHook(() => useAylikBildirimOnay({ onApproved }));

    await waitFor(() => expect(result.current.ozet?.onaylanabilir_mi).toBe(true));

    await act(async () => {
      await result.current.approveMonth();
    });

    expect(approveMock).toHaveBeenCalled();
    expect(fetchOzetMock).toHaveBeenCalledTimes(2);
    expect(onApproved).toHaveBeenCalled();
    expect(result.current.ozet?.mevcut_onay_id).toBe(2);
  });

  it("api hatasinda error state gosterir", async () => {
    fetchOzetMock.mockRejectedValueOnce(new Error("network"));

    const { result } = renderHook(() => useAylikBildirimOnay());

    await waitFor(() => expect(result.current.error).not.toBeNull());
    expect(result.current.ozet).toBeNull();
  });
});
