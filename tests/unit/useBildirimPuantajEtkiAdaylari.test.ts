/** @vitest-environment jsdom */
import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiRequestError } from "../../src/api/api-client";
import { useBildirimPuantajEtkiAdaylari } from "../../src/hooks/useBildirimPuantajEtkiAdaylari";

const fetchListMock = vi.hoisted(() => vi.fn());
const fetchOzetMock = vi.hoisted(() => vi.fn());
const fetchDetailMock = vi.hoisted(() => vi.fn());
const dismissMock = vi.hoisted(() => vi.fn());

const fetchGyOzetMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/api/bildirim-puantaj-etki-adaylari.api", () => ({
  fetchBildirimPuantajEtkiAdayList: fetchListMock,
  fetchBildirimPuantajEtkiAdayOzet: fetchOzetMock,
  fetchBildirimPuantajEtkiAdayDetail: fetchDetailMock,
  dismissBildirimPuantajEtkiAday: dismissMock
}));

vi.mock("../../src/api/genel-yonetici-bildirim-onaylari.api", () => ({
  fetchGenelYoneticiBildirimOnayiOzet: fetchGyOzetMock
}));

const listItem = {
  id: 3,
  genel_yonetici_bildirim_onayi_id: 10,
  gunluk_bildirim_id: 103,
  personel_id: 1,
  sube_id: 1,
  birim_amiri_user_id: 1,
  ay: "2026-06",
  tarih: "2026-06-04",
  bildirim_turu: "GELMEDI",
  etki_turu: "DEVAMSIZLIK",
  etki_miktari: null,
  etki_birimi: null,
  state: "INCELEME_GEREKLI" as const,
  conflict_code: "MEVCUT_PUANTAJ_VAR",
  source_priority: "BILDIRIM",
  created_at: "2026-06-10 10:05:00",
  karar_veren_user_id: null,
  karar_zamani: null,
  uygulanan_puantaj_id: null
};

const ready = {
  enabled: true,
  canDismiss: true,
  canResolveGyViaOnayApi: false,
  subeId: 1,
  birimAmiriUserId: 1,
  ay: "2026-06"
};

describe("useBildirimPuantajEtkiAdaylari", () => {
  beforeEach(() => {
    fetchGyOzetMock.mockReset().mockResolvedValue({
      genel_yonetici_bildirim_onayi: { id: 10, state: "TAMAMLANDI", onaylayan_user_id: 1, onaylandi_at: null, aciklama: null }
    });
    fetchListMock.mockReset().mockResolvedValue({
      items: [listItem],
      pagination: { page: 1, limit: 20, total: 1, totalPages: 1, hasNextPage: false, hasPreviousPage: false }
    });
    fetchOzetMock.mockReset().mockResolvedValue({
      context: { genel_yonetici_bildirim_onayi_id: 10, ay: "2026-06", ay_baslangic: null, ay_bitis: null, sube_id: 1, birim_amiri_user_id: 1, aylik_bildirim_onayi_id: 2, onaylandi_at: null },
      genel_yonetici_bildirim_onayi: null,
      kaynak_bildirim_sayisi: 1,
      aday_sayilari: { toplam: 1, hazir: 0, inceleme_gerekli: 1, uygulandi: 0, yok_sayildi: 0 },
      muhur_durumu: "ACIK" as const,
      hazirlanabilir_mi: false,
      blok_nedeni: null
    });
    fetchDetailMock.mockReset().mockResolvedValue({
      ...listItem,
      aylik_bildirim_onayi_id: 2,
      bildirim_alt_tur: null,
      bildirim_dakika: null,
      bildirim_aciklama: "Gelmedi",
      bildirim_created_at: "2026-06-04 09:00:00",
      bildirim_updated_at: "2026-06-04 09:00:00",
      conflict_detail: { message: "Mevcut puantaj var" },
      resmi_surec_id: null,
      resmi_surec_turu: null,
      resmi_surec_alt_tur: null,
      ucretli_mi_snapshot: null,
      mevcut_puantaj_id: 55,
      source_snapshot: null,
      source_hash: "hash",
      projection_version: "v1",
      updated_at: "2026-06-10 10:05:00",
      karar_gerekcesi: null,
      onceki_puantaj_snapshot: null,
      sonraki_puantaj_snapshot: null,
      uygulama_hash: null
    });
    dismissMock.mockReset().mockResolvedValue({
      id: 3,
      state: "YOK_SAYILDI",
      karar_veren_user_id: 5,
      karar_zamani: "2026-07-12 15:30:00",
      karar_gerekcesi: "Mevcut puantaj kaydıyla çakıştı.",
      uygulanan_puantaj_id: null,
      idempotent: false
    });
  });

  it("enabled false iken fetch yapmaz", () => {
    const { result } = renderHook(() =>
      useBildirimPuantajEtkiAdaylari({ ...ready, enabled: false })
    );
    expect(fetchListMock).not.toHaveBeenCalled();
    expect(result.current.items).toEqual([]);
  });

  it("canDismiss false iken dismiss POST olusturmaz", async () => {
    const { result } = renderHook(() =>
      useBildirimPuantajEtkiAdaylari({ ...ready, canDismiss: false })
    );
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    act(() => {
      result.current.openDismissModal(listItem);
      result.current.setDismissGerekce("Mevcut puantaj kaydıyla çakıştı.");
    });
    await act(async () => {
      await result.current.dismissAday();
    });
    expect(dismissMock).not.toHaveBeenCalled();
    expect(result.current.dismissTarget).toBeNull();
  });

  it("GY onay API yetkisi varken bos listede ozet icin GY ID cozer", async () => {
    fetchListMock.mockResolvedValueOnce({
      items: [],
      pagination: { page: 1, limit: 20, total: 0, totalPages: 1, hasNextPage: false, hasPreviousPage: false }
    });
    const { result } = renderHook(() =>
      useBildirimPuantajEtkiAdaylari({ ...ready, canResolveGyViaOnayApi: true })
    );
    await waitFor(() => expect(fetchGyOzetMock).toHaveBeenCalledWith("2026-06", 1, 1));
    await waitFor(() => expect(fetchOzetMock).toHaveBeenCalledWith(10, { subeId: 1 }));
    expect(result.current.items).toEqual([]);
    expect(result.current.ozet?.aday_sayilari.inceleme_gerekli).toBe(1);
  });

  it("hazir baglamda listeyi ve ozeti yukler", async () => {
    const { result } = renderHook(() => useBildirimPuantajEtkiAdaylari(ready));
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    expect(fetchOzetMock).toHaveBeenCalledWith(10, { subeId: 1 });
    expect(result.current.ozet?.aday_sayilari.inceleme_gerekli).toBe(1);
  });

  it("sube degisince stale veriyi temizler", async () => {
    const { result, rerender } = renderHook(
      ({ options }) => useBildirimPuantajEtkiAdaylari(options),
      { initialProps: { options: ready } }
    );
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    rerender({ options: { ...ready, subeId: 2, birimAmiriUserId: 4 } });
    expect(result.current.items).toEqual([]);
    expect(result.current.ozet).toBeNull();
  });

  it("dismiss basarisinda refetch yapar ve cift submiti engeller", async () => {
    let resolveDismiss!: () => void;
    dismissMock.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveDismiss = () =>
            resolve({
              id: 3,
              state: "YOK_SAYILDI",
              karar_veren_user_id: 5,
              karar_zamani: "2026-07-12 15:30:00",
              karar_gerekcesi: "Mevcut puantaj kaydıyla çakıştı.",
              uygulanan_puantaj_id: null,
              idempotent: false
            });
        })
    );
    const { result } = renderHook(() => useBildirimPuantajEtkiAdaylari(ready));
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    act(() => {
      result.current.openDismissModal(listItem);
      result.current.setDismissGerekce("Mevcut puantaj kaydıyla çakıştı.");
    });
    let first!: Promise<void>;
    act(() => {
      first = result.current.dismissAday();
      void result.current.dismissAday();
    });
    expect(dismissMock).toHaveBeenCalledTimes(1);
    await act(async () => {
      resolveDismiss();
      await first;
    });
    expect(fetchListMock.mock.calls.length).toBeGreaterThanOrEqual(2);
    expect(result.current.successMessage).toBe("Puantaj etki adayı yok sayıldı.");
  });

  it("idempotent success bilgi mesaji gosterir", async () => {
    dismissMock.mockResolvedValueOnce({
      id: 3,
      state: "YOK_SAYILDI",
      karar_veren_user_id: 5,
      karar_zamani: "2026-07-12 15:30:00",
      karar_gerekcesi: "Mevcut puantaj kaydıyla çakıştı.",
      uygulanan_puantaj_id: null,
      idempotent: true
    });
    const { result } = renderHook(() => useBildirimPuantajEtkiAdaylari(ready));
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    act(() => {
      result.current.openDismissModal(listItem);
      result.current.setDismissGerekce("Mevcut puantaj kaydıyla çakıştı.");
    });
    await act(async () => {
      await result.current.dismissAday();
    });
    expect(result.current.infoMessage).toContain("daha önce aynı gerekçeyle");
  });

  it("422 field error ve 409 stale refetch davranisi", async () => {
    dismissMock.mockRejectedValueOnce(
      new ApiRequestError("validation", 422, { code: "VALIDATION_ERROR", field: "gerekce" })
    );
    const { result } = renderHook(() => useBildirimPuantajEtkiAdaylari(ready));
    await waitFor(() => expect(result.current.items).toHaveLength(1));
    act(() => {
      result.current.openDismissModal(listItem);
      result.current.setDismissGerekce("Mevcut puantaj kaydıyla çakıştı.");
    });
    await act(async () => {
      await result.current.dismissAday();
    });
    expect(result.current.dismissFieldError).toBeTruthy();

    dismissMock.mockRejectedValueOnce(
      new ApiRequestError("stale", 409, { code: "STATE_STALE" })
    );
    act(() => {
      result.current.openDismissModal(listItem);
      result.current.setDismissGerekce("Mevcut puantaj kaydıyla çakıştı.");
    });
    await act(async () => {
      await result.current.dismissAday();
    });
    expect(result.current.infoMessage).toContain("Liste yenilendi");
    expect(fetchListMock.mock.calls.length).toBeGreaterThanOrEqual(2);
  });
});
