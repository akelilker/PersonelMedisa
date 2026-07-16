/** @vitest-environment jsdom */
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { EtkiAdayiRaporuPage } from "../../src/features/raporlar/pages/EtkiAdayiRaporuPage";

const useRoleAccessMock = vi.hoisted(() => vi.fn());
const useBildirimEtkiRaporMock = vi.hoisted(() => vi.fn());

vi.mock("../../src/hooks/use-role-access", () => ({
  useRoleAccess: useRoleAccessMock
}));

vi.mock("../../src/hooks/useBildirimEtkiRapor", () => ({
  useBildirimEtkiRapor: useBildirimEtkiRaporMock
}));

vi.mock("../../src/api/yonetim.api", () => ({
  fetchYonetimSubeleri: vi.fn().mockResolvedValue([{ id: 1, ad: "Merkez" }])
}));

vi.mock("../../src/api/referans.api", () => ({
  fetchDepartmanOptions: vi.fn().mockResolvedValue([{ id: 3, label: "Operasyon" }])
}));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    session: {
      active_sube_id: 1,
      sube_list: [{ id: 1, ad: "Merkez" }],
      user: { id: 1, rol: "MUHASEBE", sube_ids: [1, 2] }
    }
  })
}));

const sampleRow = {
  id: 3,
  personel_id: 1,
  personel_ad_soyad: "Ayse Yilmaz",
  sicil_no: "P-001",
  sube_ad: "Merkez",
  departman_ad: "Operasyon",
  tarih: "2026-06-04",
  bildirim_turu: "GEC_KALMA",
  etki_turu: "GEC_KALMA_DAKIKA",
  effective_miktar: 15,
  effective_birim: "DAKIKA",
  state: "HAZIR",
  conflict_code: null,
  mevcut_puantaj_ozet: null,
  uygulanan_puantaj_ozet: null,
  uygulama_modu: "OTOMATIK",
  karar_turu: null,
  karar_veren: null,
  karar_zamani: null,
  projection_version: "S74_V1",
  source_integrity: "OK",
  audit_integrity: "PENDING"
};

function makeHookState(overrides: Record<string, unknown> = {}) {
  return {
    rows: [sampleRow],
    summary: {
      toplam_aday: 1,
      otomatik_uygulanan: 0,
      manuel_uygulanan: 0,
      koru: 0,
      revize: 0,
      yok_sayilan: 0,
      bekleyen: 1,
      conflict_dagilimi: {},
      toplam_gec_kalma_dakika: 15,
      toplam_erken_cikis_dakika: 0,
      toplam_devamsizlik_gun: 0
    },
    page: 1,
    totalPages: 1,
    hasNextPage: false,
    hasPrevPage: false,
    isLoading: false,
    errorMessage: null as string | null,
    hasSearched: true,
    load: vi.fn(),
    ...overrides
  };
}

function renderPage() {
  return render(
    <MemoryRouter>
      <EtkiAdayiRaporuPage />
    </MemoryRouter>
  );
}

describe("EtkiAdayiRaporuPage frontend", () => {
  beforeEach(() => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: (permission: string) =>
        permission === "puantaj.bildirim_etki.rapor.view" || permission === "puantaj.bildirim_etki.rapor.export"
    });
    useBildirimEtkiRaporMock.mockReturnValue(makeHookState());
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it("renders report table and summary cards for authorized users", () => {
    renderPage();
    expect(screen.getByTestId("etki-adayi-rapor-page")).toBeTruthy();
    expect(screen.getByTestId("etki-adayi-rapor-summary")).toBeTruthy();
    expect(screen.getByTestId("etki-adayi-rapor-table")).toBeTruthy();
    expect(screen.getByTestId("etki-adayi-rapor-row-3").textContent).toContain("HAZIR");
  });

  it("shows export button only with export permission", () => {
    renderPage();
    expect(screen.getByTestId("etki-adayi-rapor-export-csv")).toBeTruthy();
  });

  it("hides export button without export permission", () => {
    useRoleAccessMock.mockReturnValue({
      hasPermission: (permission: string) => permission === "puantaj.bildirim_etki.rapor.view"
    });
    renderPage();
    expect(screen.queryByTestId("etki-adayi-rapor-export-csv")).toBeNull();
  });

  it("renders loading and error states", () => {
    useBildirimEtkiRaporMock.mockReturnValue(makeHookState({ isLoading: true, rows: [], hasSearched: false }));
    const { rerender } = renderPage();
    expect(screen.getByText("Etki adayı raporu yükleniyor...")).toBeTruthy();

    useBildirimEtkiRaporMock.mockReturnValue(
      makeHookState({ isLoading: false, errorMessage: "Rapor yuklenemedi", rows: [], hasSearched: true })
    );
    rerender(
      <MemoryRouter>
        <EtkiAdayiRaporuPage />
      </MemoryRouter>
    );
    expect(screen.getByText("Rapor yuklenemedi")).toBeTruthy();
  });

  it("shows empty state when no rows match filters", () => {
    useBildirimEtkiRaporMock.mockReturnValue(
      makeHookState({
        rows: [],
        hasSearched: true,
        summary: {
          toplam_aday: 0,
          otomatik_uygulanan: 0,
          manuel_uygulanan: 0,
          koru: 0,
          revize: 0,
          yok_sayilan: 0,
          bekleyen: 0,
          conflict_dagilimi: {},
          toplam_gec_kalma_dakika: 0,
          toplam_erken_cikis_dakika: 0,
          toplam_devamsizlik_gun: 0
        }
      })
    );
    renderPage();
    expect(screen.getByText("Kayıt bulunamadı")).toBeTruthy();
  });
});
