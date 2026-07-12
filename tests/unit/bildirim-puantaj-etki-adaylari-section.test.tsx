/** @vitest-environment jsdom */
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
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

const incelemeItem = {
  ...listItem,
  id: 3,
  state: "INCELEME_GEREKLI" as const
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
      context: {
        genel_yonetici_bildirim_onayi_id: 10,
        ay: "2026-06",
        ay_baslangic: null,
        ay_bitis: null,
        sube_id: 1,
        birim_amiri_user_id: 1,
        aylik_bildirim_onayi_id: 2,
        onaylandi_at: null
      },
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

function mockPermissions(permissions: string[]) {
  useRoleAccessMock.mockReturnValue({
    hasPermission: (permission: string) => permissions.includes(permission)
  });
}

function mockSession(overrides: Record<string, unknown> = {}) {
  useAuthMock.mockReturnValue({
    session: {
      active_sube_id: 1,
      user: { sube_ids: [1] },
      sube_list: [{ id: 1, ad: "Merkez" }],
      ...overrides
    }
  });
}

function mockAllSubeSession() {
  mockSession({
    active_sube_id: null,
    user: { sube_ids: [] },
    sube_list: [
      { id: 1, ad: "Merkez" },
      { id: 2, ad: "Depolama" }
    ]
  });
}

describe("BildirimPuantajEtkiAdaylariSection", () => {
  afterEach(() => {
    cleanup();
  });

  beforeEach(() => {
    vi.clearAllMocks();
    mockPermissions(["puantaj.bildirim_etki.view", "puantaj.bildirim_etki.dismiss"]);
    mockSession();
    fetchBirimAmiriMock.mockResolvedValue([{ user_id: 1, ad_soyad: "Merkez Birim Amiri", sube_id: 1 }]);
    useHookMock.mockReturnValue(makeHookState());
  });

  it("permission yoksa panel render edilmez ve birim amiri secenekleri cagrilmaz", async () => {
    mockPermissions([]);
    mockSession({ active_sube_id: 1, user: { sube_ids: [1] } });
    const { container } = render(<BildirimPuantajEtkiAdaylariSection />);
    expect(container.innerHTML).toBe("");
    await waitFor(() => expect(fetchBirimAmiriMock).not.toHaveBeenCalled());
  });

  it("BIRIM_AMIRI paneli render etmez ve birim amiri secenekleri cagrilmaz", async () => {
    mockPermissions([]);
    mockSession({ active_sube_id: 1, user: { rol: "BIRIM_AMIRI", sube_ids: [1] } });
    const { container } = render(<BildirimPuantajEtkiAdaylariSection />);
    expect(container.innerHTML).toBe("");
    await waitFor(() => expect(fetchBirimAmiriMock).not.toHaveBeenCalled());
  });

  it("PATRON paneli render etmez ve birim amiri secenekleri cagrilmaz", async () => {
    mockPermissions([]);
    mockSession({ active_sube_id: null, user: { rol: "PATRON", sube_ids: [] } });
    const { container } = render(<BildirimPuantajEtkiAdaylariSection />);
    expect(container.innerHTML).toBe("");
    await waitFor(() => expect(fetchBirimAmiriMock).not.toHaveBeenCalled());
  });

  it("MUHASEBE gorunurluk yetkisinde panel ve Yok Say aksiyonunu gosterir", async () => {
    render(<BildirimPuantajEtkiAdaylariSection />);
    await waitFor(() => expect(screen.getByTestId("puantaj-etki-aday-panel")).not.toBeNull());
    expect(screen.getByText("Onaylı Bildirim Puantaj Etki Adayları")).not.toBeNull();
    expect(screen.getAllByTestId("puantaj-etki-aday-dismiss-1").length).toBeGreaterThan(0);
    expect(screen.queryByText("Uygula")).toBeNull();
    expect(screen.queryByLabelText("Şube")).toBeNull();
  });

  it("GENEL_YONETICI tum sube oturumunda yerel sube secimi olmadan request atmaz", async () => {
    mockPermissions(["puantaj.bildirim_etki.view"]);
    mockAllSubeSession();
    useHookMock.mockReturnValue(makeHookState({ contextReady: false, items: [], ozet: null }));

    render(<BildirimPuantajEtkiAdaylariSection />);

    expect(screen.getByLabelText("Şube")).not.toBeNull();
    expect(screen.getByTestId("puantaj-etki-aday-context").textContent).toContain(
      "Verileri görüntülemek için şube seçin."
    );
    expect(fetchBirimAmiriMock).not.toHaveBeenCalled();
    expect(useHookMock).toHaveBeenCalledWith(
      expect.objectContaining({ subeId: null, enabled: true, canDismiss: false })
    );
    expect(screen.queryByRole("button", { name: "Yok Say" })).toBeNull();
  });

  it("GENEL_YONETICI yerel sube secince birim amiri secenekleri ve hook baglami guncellenir", async () => {
    mockPermissions(["puantaj.bildirim_etki.view"]);
    mockAllSubeSession();
    fetchBirimAmiriMock.mockResolvedValue([
      { user_id: 1, ad_soyad: "Merkez Birim Amiri", sube_id: 1 },
      { user_id: 4, ad_soyad: "Depolama Birim Amiri", sube_id: 2 }
    ]);
    useHookMock.mockReturnValue(makeHookState({ contextReady: false, items: [], ozet: null }));

    render(<BildirimPuantajEtkiAdaylariSection />);

    fireEvent.change(screen.getByLabelText("Şube"), { target: { value: "2" } });

    await waitFor(() => expect(fetchBirimAmiriMock).toHaveBeenCalledWith(2));
    expect(useHookMock).toHaveBeenCalledWith(expect.objectContaining({ subeId: 2 }));
  });

  it("BOLUM_YONETICISI tum sube oturumunda read-only panel ve yerel sube secimi gosterir", async () => {
    mockPermissions(["puantaj.bildirim_etki.view"]);
    mockAllSubeSession();
    useHookMock.mockReturnValue(makeHookState({ contextReady: false, items: [], ozet: null }));

    render(<BildirimPuantajEtkiAdaylariSection />);

    expect(screen.getByLabelText("Şube")).not.toBeNull();
    expect(screen.queryByRole("button", { name: "Yok Say" })).toBeNull();
  });

  it("MUHASEBE tum sube oturumunda yerel sube secimi sonrasi Yok Say aksiyonunu gosterir", async () => {
    mockAllSubeSession();
    useHookMock.mockReturnValue(makeHookState({ items: [incelemeItem] }));

    render(<BildirimPuantajEtkiAdaylariSection />);

    fireEvent.change(screen.getByLabelText("Şube"), { target: { value: "1" } });
    await waitFor(() => expect(fetchBirimAmiriMock).toHaveBeenCalledWith(1));
    await waitFor(() => expect(screen.getAllByTestId("puantaj-etki-aday-dismiss-3").length).toBeGreaterThan(0));
  });

  it("aktif subeli kullanicida ekstra yerel sube select gosterilmez", async () => {
    render(<BildirimPuantajEtkiAdaylariSection />);
    expect(screen.queryByLabelText("Şube")).toBeNull();
    await waitFor(() => expect(fetchBirimAmiriMock).toHaveBeenCalledWith(1));
  });

  it("yerel sube degisince birim amiri secenekleri yeniden yuklenir", async () => {
    mockAllSubeSession();
    fetchBirimAmiriMock
      .mockResolvedValueOnce([{ user_id: 1, ad_soyad: "Merkez Birim Amiri", sube_id: 1 }])
      .mockResolvedValueOnce([{ user_id: 4, ad_soyad: "Depolama Birim Amiri", sube_id: 2 }]);
    useHookMock.mockReturnValue(makeHookState({ contextReady: false, items: [], ozet: null }));

    render(<BildirimPuantajEtkiAdaylariSection />);

    fireEvent.change(screen.getByLabelText("Şube"), { target: { value: "1" } });
    await waitFor(() => expect(fetchBirimAmiriMock).toHaveBeenCalledWith(1));

    fetchBirimAmiriMock.mockClear();

    fireEvent.change(screen.getByLabelText("Şube"), { target: { value: "2" } });
    await waitFor(() => expect(fetchBirimAmiriMock).toHaveBeenCalledWith(2));
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
