// @vitest-environment jsdom

/**
 * PERSONELMEDISA — login sonrasi yetkisiz yonlendirme regresyonu (integration).
 *
 * Kabul kriterleri:
 *  - PERSONEL basarili login → `/` → self-service ekrani; yonetim bootstrap
 *    cagrisi ve `/yetkisiz` yonlendirmesi YOK.
 *  - Bagli mavi yaka BOLUM_YONETICISI → ana ekran (MainMenu) + QR kisayolu
 *    erisilebilir; rol dusurulmez.
 *  - Dogrudan yetkisiz bir modul rotasina gidilirse `/yetkisiz` korumasi calisir.
 *  - Zorunlu sifre degisimi yonlendirmesi bozulmaz.
 *
 * Gercek `AppRoutes` render edilir; boylece index → HomeIndexMainMenu →
 * self-service/MainMenu karari ve ProtectedRoute guard'i birlikte dogrulanir.
 */

import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router-dom";
import { AppRoutes } from "../../src/app/routes";
import { MEDISA_AUTH_SESSION_KEY } from "../../src/auth/auth-constants";
import { finalizeAuthSessionSube } from "../../src/auth/auth-session-sube";
import { AuthProvider } from "../../src/state/auth.store";
import type { AuthSession } from "../../src/types/auth";

const ROUTER_FUTURE_FLAGS = {
  v7_startTransition: true,
  v7_relativeSplatPath: true
} as const;

const {
  fetchPersonellerList,
  fetchGunlukTamamlamalariHeader,
  fetchDepartmanOptions,
  fetchSurecTuruOptions,
  fetchBildirimTuruOptions,
  fetchMeYillikIzinBakiye
} = vi.hoisted(() => ({
  fetchPersonellerList: vi.fn(async () => ({ items: [], meta: { page: 1, limit: 10, total: 0 } })),
  fetchGunlukTamamlamalariHeader: vi.fn(async () => ({
    items: [],
    meta: { page: 1, limit: 8, total: 0 }
  })),
  fetchDepartmanOptions: vi.fn(async () => []),
  fetchSurecTuruOptions: vi.fn(async () => []),
  fetchBildirimTuruOptions: vi.fn(async () => []),
  fetchMeYillikIzinBakiye: vi.fn(async () => ({
    personel_id: 158,
    contract_version: "s2c-v1",
    ise_giris_tarihi: "2020-01-01",
    referans_tarih: "2026-09-22",
    kidem_yil: 6,
    yas: null,
    yas_istisna_uygulandi: false,
    mevcut_yillik_hak_gun: 20,
    birikmis_yasal_hak_gun: 94,
    yasal_hak_gun: 94,
    manuel_duzeltme_gun: 0,
    efektif_hak_gun: 94,
    kullanilan_gun: 10,
    ham_kalan_gun: 84,
    kalan_gun: 84,
    takvim_dogrulandi_mi: true,
    eksik_takvim_tarihleri: [],
    sayilan_normal_gun: 10,
    haric_tutulan_hafta_tatili_gun: 0,
    haric_tutulan_ubgt_gun: 0,
    duzeltme_adet: 0
  }))
}));

vi.mock("../../src/api/api-client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/api/api-client")>();
  return { ...actual, shouldPreferDemoApi: () => false };
});

vi.mock("../../src/api/attendance-mobile.api", () => ({
  fetchAttendanceToday: vi.fn(async () => ({
    business_date: "2026-09-22",
    capabilities: { qr_scan: true, attendance_correct: true, coming_soon_message: "Hazırlanıyor" },
    personel: { id: 158, ad_soyad: "Self Personel", sube_ad: "Merkez", bolum_ad: null, birim_ad: null, gorev_ad: null },
    giris: null,
    cikis: null,
    can_scan_giris: true,
    can_scan_cikis: true,
    pending_giris_correction: null,
    pending_cikis_correction: null
  })),
  fetchInboxNotifications: vi.fn(async () => ({ items: [], pending_popups: [] })),
  createAttendanceCorrection: vi.fn(),
  decideAttendanceCorrection: vi.fn(),
  ackInboxPopup: vi.fn()
}));

vi.mock("../../src/api/me.api", () => ({
  fetchMe: vi.fn(async () => ({ completeness: { missing_count: 0 }, last_qr_event: null })),
  fetchMeYillikIzinBakiye
}));

vi.mock("../../src/api/personeller.api", () => ({
  fetchPersonellerList,
  createPersonel: vi.fn(),
  updatePersonel: vi.fn()
}));

vi.mock("../../src/api/bildirimler.api", () => ({
  fetchGunlukTamamlamalariHeader,
  fetchBildirimlerList: vi.fn(),
  createBildirim: vi.fn(),
  updateBildirim: vi.fn(),
  cancelBildirim: vi.fn()
}));

vi.mock("../../src/api/referans.api", () => ({
  fetchDepartmanOptions,
  fetchBolumOptions: vi.fn(async () => []),
  fetchBirimOptions: vi.fn(async () => []),
  fetchGorevOptions: vi.fn(async () => []),
  fetchPozisyonOptions: vi.fn(async () => []),
  fetchPersonelTipiOptions: vi.fn(async () => []),
  fetchSgkIsverenOptions: vi.fn(async () => []),
  fetchCalismaLokasyonuOptions: vi.fn(async () => []),
  fetchBagliAmirOptions: vi.fn(async () => []),
  fetchUcretTipiOptions: vi.fn(async () => []),
  fetchPrimKuraliOptions: vi.fn(async () => []),
  fetchSurecTuruOptions,
  fetchBildirimTuruOptions
}));

vi.mock("../../src/api/finans.api", () => ({
  createFinansKalem: vi.fn(),
  updateFinansKalem: vi.fn(),
  cancelFinansKalem: vi.fn()
}));

vi.mock("../../src/api/puantaj.api", () => ({
  upsertGunlukPuantaj: vi.fn()
}));

vi.mock("../../src/api/surecler.api", () => ({
  createSurec: vi.fn(),
  updateSurec: vi.fn(),
  cancelSurec: vi.fn()
}));

function buildSession(options: {
  rol: AuthSession["user"]["rol"];
  personelId?: number | null;
  personelTipiAd?: string | null;
  mustChangePassword?: boolean;
}): AuthSession {
  return finalizeAuthSessionSube({
    token: "test-token",
    ui_profile: "yonetim",
    active_sube_id: 1,
    must_change_password: options.mustChangePassword ?? false,
    sube_list: [{ id: 1, ad: "Merkez" }],
    user: {
      id: 7,
      ad_soyad: "Test Kullanici",
      rol: options.rol,
      sube_ids: [1],
      personel_id: options.personelId ?? null,
      personel_tipi_ad: options.personelTipiAd ?? null
    }
  });
}

function renderAt(entry: string) {
  return render(
    <MemoryRouter initialEntries={[entry]} future={ROUTER_FUTURE_FLAGS}>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </MemoryRouter>
  );
}

function storeSession(session: AuthSession) {
  window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(session));
}

describe("login sonrasi rol ana ekrani", () => {
  beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
    // jsdom does not implement matchMedia; AppFooter/PwaInstallBar probe it.
    vi.stubGlobal(
      "matchMedia",
      vi.fn().mockImplementation((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn()
      }))
    );
    fetchPersonellerList.mockClear();
    fetchGunlukTamamlamalariHeader.mockClear();
    fetchDepartmanOptions.mockClear();
    fetchSurecTuruOptions.mockClear();
    fetchBildirimTuruOptions.mockClear();
    fetchMeYillikIzinBakiye.mockClear();
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.clear();
    window.sessionStorage.clear();
  });

  it("PERSONEL login → '/' → self-service ekrani; yonetim bootstrap yok, /yetkisiz yok", async () => {
    storeSession(buildSession({ rol: "PERSONEL", personelId: 158, personelTipiAd: "Mavi Yaka" }));

    renderAt("/");

    await screen.findByTestId("personel-self-service-page");
    expect(screen.queryByTestId("yetkisiz-page")).toBeNull();

    // Bootstrap yetkisiz yonetim verisini hic istemez.
    expect(fetchPersonellerList).not.toHaveBeenCalled();
    expect(fetchGunlukTamamlamalariHeader).not.toHaveBeenCalled();
    expect(fetchDepartmanOptions).not.toHaveBeenCalled();
    expect(fetchSurecTuruOptions).not.toHaveBeenCalled();
    expect(fetchBildirimTuruOptions).not.toHaveBeenCalled();
  });

  it("PERSONEL izin bakiye hatasi ana ozet ekranini dusurmez", async () => {
    fetchMeYillikIzinBakiye.mockRejectedValueOnce(new Error("izin unavailable"));
    storeSession(buildSession({ rol: "PERSONEL", personelId: 158, personelTipiAd: "Mavi Yaka" }));

    renderAt("/");

    await screen.findByTestId("personel-attendance-boxes");
    expect(screen.queryByTestId("personel-self-service-error")).toBeNull();
    await waitFor(() => {
      expect(fetchMeYillikIzinBakiye).toHaveBeenCalled();
    });
    await waitFor(() => {
      expect(screen.queryByTestId("personel-leave-row")).toBeNull();
    });
  });

  it("bagli mavi yaka BOLUM_YONETICISI login → ana ekran + QR kisayolu erisilebilir", async () => {
    storeSession(buildSession({ rol: "BOLUM_YONETICISI", personelId: 158, personelTipiAd: "Mavi Yaka" }));

    renderAt("/");

    // Ana ekran (yönetim MainMenu) korunur.
    expect(await screen.findByTestId("menu-kayit-surec")).toBeInTheDocument();
    // Rol dusurulmeden kendi QR self-service yuzeyine ulasir.
    const qrLink = await screen.findByTestId("self-qr-scan-link");
    expect(qrLink).toHaveAttribute("href", "/self/qr-okut");
    expect(screen.getByTestId("self-qr-history-link")).toHaveAttribute("href", "/self/qr-hareketleri");
    expect(screen.getByTestId("self-service-home-link")).toHaveAttribute("href", "/self");
    expect(screen.queryByTestId("yetkisiz-page")).toBeNull();
    expect(screen.queryByTestId("personel-self-service-page")).toBeNull();
  });

  it("dogrudan yetkisiz modul rotasi → /yetkisiz korumasi hala calisir", async () => {
    storeSession(buildSession({ rol: "PERSONEL", personelId: 158, personelTipiAd: "Mavi Yaka" }));

    renderAt("/yonetim-paneli");

    await waitFor(() => {
      expect(screen.getByTestId("yetkisiz-page")).toBeInTheDocument();
    });
    expect(screen.queryByTestId("personel-self-service-page")).toBeNull();
  });

  it("zorunlu sifre degisimi yonlendirmesi bozulmaz", async () => {
    storeSession(
      buildSession({
        rol: "GENEL_YONETICI",
        mustChangePassword: true
      })
    );

    renderAt("/");

    await waitFor(() => {
      expect(screen.getByTestId("change-password-page")).toBeInTheDocument();
    });
  });
});
