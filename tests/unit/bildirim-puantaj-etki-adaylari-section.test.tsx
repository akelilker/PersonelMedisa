/** @vitest-environment jsdom */
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { BildirimPuantajEtkiAdaylariSection } from "../../src/features/puantaj/components/BildirimPuantajEtkiAdaylariSection";

const useRoleAccessMock = vi.hoisted(() => vi.fn());
const useAuthMock = vi.hoisted(() => vi.fn());
const useHookMock = vi.hoisted(() => vi.fn());
const fetchBirimAmiriMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: useRoleAccessMock
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: useAuthMock
}));

vi.mock("../../src/hooks/useBildirimPuantajEtkiAdaylari", () => ({
  useBildirimPuantajEtkiAdaylari: useHookMock
}));

vi.mock("../../src/api/bildirimler.api", () => ({
  fetchBirimAmiriSecenekleri: fetchBirimAmiriMock
}));

const listItem = {
  id: 1,
  genel_yonetici_bildirim_onayi_id: 10,
  gunluk_bildirim_id: 101,
  personel_id: 1,
  sube_id: 1,
  birim_amiri_user_id: 1,
  ay: "2026-06",
  tarih: "2026-06-03",
  bildirim_turu: "GEC_KALMA",
  etki_turu: "GEC_KALMA_DK",
  etki_miktari: 15,
  etki_birimi: "DK",
  state: "HAZIR" as const,
  conflict_code: null,
  source_priority: "BILDIRIM",
  created_at: "2026-06-10 10:00:00",
  karar_veren_user_id: null,
  karar_zamani: null,
  uygulanan_puantaj_id: null
};

function makeHookState(overrides: Record<string, unknown> = {}) {
  return {
    ay: "2026-06",
    setAy: vi.fn(),
    draftFilters: { personelId: "", state: "" },
    appliedFilters: { personelId: "", state: "" },
    updateDraftFilters: vi.fn(),
    submitFilters: vi.fn(),
    clearFilters: vi.fn(),
    page: 1,
    setPage: vi.fn(),
    pagination: {
      page: 1,
      limit: 20,
      total: 1,
      totalPages: 1,
      hasNextPage: false,
      hasPreviousPage: false
    },
    items: [listItem],
    ozet: {
      context: { genel_yonetici_bildirim_onayi_id: 10, ay: "2026-06", ay_baslangic: null, ay_bitis: null, sube_id: 1, birim_amiri_user_id: 1, aylik_bildirim_onayi_id: 2, onaylandi_at: null },
      genel_yonetici_bildirim_onayi: null,
      kaynak_bildirim_sayisi: 1,
      aday_sayilari: { toplam: 1, hazir: 1, inceleme_gerekli: 0, uygulandi: 0, yok_sayildi: 0 },
      muhur_durumu: "ACIK",
      hazirlanabilir_mi: false,
      blok_nedeni: null
    },
    detail: null,
    detailId: null,
    isLoading: false,
    isListLoading: false,
    isOzetLoading: false,
    isDetailLoading: false,
    listError: null,
    ozetError: null,
    detailError: null,
    successMessage: null,
    infoMessage: null,
    dismissTarget: null,
    dismissGerekce: "",
    setDismissGerekce: vi.fn(),
    dismissFieldError: null,
    dismissError: null,
    isDismissing: false,
    contextReady: true,
    refreshList: vi.fn(),
    refreshAll: vi.fn(),
    openDetail: vi.fn(),
    closeDetail: vi.fn(),
    openDismissModal: vi.fn(),
    closeDismissModal: vi.fn(),
    dismissAday: vi.fn(),
    ...overrides
  };
}

describe("BildirimPuantajEtkiAdaylariSection", () => {
  afterEach(() => {
    cleanup();
  });

  beforeEach(() => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: (permission: string) =>
        permission === "puantaj.bildirim_etki.view" || permission === "puantaj.bildirim_etki.dismiss"
    });
    useAuthMock.mockReturnValue({
      session: { active_sube_id: 1, user: { sube_ids: [1] }, sube_list: [{ id: 1, ad: "Merkez" }] }
    });
    fetchBirimAmiriMock.mockResolvedValue([{ user_id: 1, ad_soyad: "Merkez Birim Amiri", sube_id: 1 }]);
    useHookMock.mockReturnValue(makeHookState());
  });

  it("permission yoksa panel render edilmez", () => {
    useRoleAccessMock.mockReturnValue({ hasPermission: () => false });
    const { container } = render(<BildirimPuantajEtkiAdaylariSection />);
    expect(container.innerHTML).toBe("");
  });

  it("MUHASEBE gorunurluk yetkisinde panel ve Yok Say aksiyonunu gosterir", async () => {
    render(<BildirimPuantajEtkiAdaylariSection />);
    await waitFor(() => expect(screen.getByTestId("puantaj-etki-aday-panel")).not.toBeNull());
    expect(screen.getByText("Onaylı Bildirim Puantaj Etki Adayları")).not.toBeNull();
    expect(screen.getAllByTestId("puantaj-etki-aday-dismiss-1").length).toBeGreaterThan(0);
    expect(screen.queryByText("Uygula")).toBeNull();
  });

  it("terminal state satirinda aksiyon gostermez", () => {
    useHookMock.mockReturnValue(
      makeHookState({
        items: [{ ...listItem, id: 4, state: "UYGULANDI" }]
      })
    );
    render(<BildirimPuantajEtkiAdaylariSection />);
    expect(screen.queryAllByTestId("puantaj-etki-aday-dismiss-4")).toHaveLength(0);
    expect(screen.getAllByTestId("puantaj-etki-aday-detail-4").length).toBeGreaterThan(0);
  });

  it("Yok Say modalinda gerekce validasyonu submiti engeller", () => {
    useHookMock.mockReturnValue(
      makeHookState({
        dismissTarget: listItem,
        setDismissGerekce: vi.fn()
      })
    );
    render(<BildirimPuantajEtkiAdaylariSection />);
    const submit = screen.getByTestId("puantaj-etki-aday-dismiss-submit") as HTMLButtonElement;
    expect(submit.disabled).toBe(true);
    expect(screen.getByTestId("puantaj-etki-gerekce-counter").textContent).toBe("0 / 500");
  });
});
