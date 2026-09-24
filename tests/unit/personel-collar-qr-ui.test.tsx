// @vitest-environment jsdom

/**
 * Focused render-level proof for the role-independent QR/kart entitlement gate.
 *
 * Owner chain under test:
 *   session.user.personel_id + session.user.personel_tipi_ad (DB mirror, backend-only)
 *     → useRoleAccess → hasUserPermission (rol bağımsız collar gate)
 *       → SelfServiceQrShortcuts (tek QR CTA owner'ı)
 *         → PersonelSelfServiceHomePage / BirimAmiriOperationalHomePage / HomeIndex
 *       → ProtectedRoute QR routes (permission-based, role condition YOK).
 *
 * The backend 403 (RolePermissions::assert) remains the authority; these tests
 * prove the fail-closed UX mirror for E) / F) / H) and that non-QR own
 * self-service survives for every collar (G).
 */

import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import type { AttendanceTodayResponse } from "../../src/api/attendance-mobile.api";
import type { AuthSession } from "../../src/types/auth";
import { ProtectedRoute } from "../../src/router/ProtectedRoute";
import { BirimAmiriOperationalHomePage } from "../../src/features/self-service/pages/BirimAmiriOperationalHomePage";
import { PersonelSelfServiceHomePage } from "../../src/features/self-service/pages/PersonelSelfServiceHomePage";
import { SelfServiceQrShortcuts } from "../../src/features/self-service/components/SelfServiceQrShortcuts";

const sessionState = vi.hoisted(() => ({ current: null as unknown }));

vi.mock("../../src/state/auth.store", () => ({
  useAuth: () => ({
    session: sessionState.current,
    isAuthenticated: sessionState.current !== null
  })
}));

vi.mock("../../src/api/api-client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/api/api-client")>();
  return { ...actual, shouldPreferDemoApi: () => false };
});

const attendance = {
  business_date: "2026-09-19",
  capabilities: { qr_scan: true, attendance_correct: true, coming_soon_message: "Hazırlanıyor" },
  personel: {
    id: 158,
    ad_soyad: "Self Personel",
    sube_ad: "Merkez",
    bolum_ad: "Operasyon",
    birim_ad: null,
    gorev_ad: null
  },
  giris: null,
  cikis: null,
  can_scan_giris: true,
  can_scan_cikis: false,
  pending_giris_correction: null,
  pending_cikis_correction: null
} as unknown as AttendanceTodayResponse;

vi.mock("../../src/api/attendance-mobile.api", () => ({
  fetchAttendanceToday: vi.fn(async () => attendance),
  fetchInboxNotifications: vi.fn(async () => ({ items: [], pending_popups: [] })),
  createAttendanceCorrection: vi.fn(),
  decideAttendanceCorrection: vi.fn(),
  ackInboxPopup: vi.fn()
}));

vi.mock("../../src/api/me.api", () => ({
  fetchMe: vi.fn(async () => ({ completeness: { missing_count: 0 }, last_qr_event: null }))
}));

vi.mock("../../src/api/bildirimler.api", () => ({
  fetchBirimGunlukDurum: vi.fn(async () => ({
    tarih: "2026-09-19",
    bolum_ad: "Operasyon",
    birim_ad: "Vardiya",
    ozet: { toplam: 0, gelen: 0, gelmeyen: 0, izinli: 0, eksik_giris: 0 },
    tamamlandi_mi: false,
    bildirim: null,
    pazar_mesai_prompt: { show: false, message: "" }
  }))
}));

function setSession(rol: string, personelTipiAd: string | null, personelId: number | null = 158) {
  sessionState.current = {
    token: "test-token",
    ui_profile: "yonetim",
    must_change_password: false,
    active_sube_id: 1,
    sube_list: [{ id: 1, ad: "Merkez" }],
    user: {
      id: 7,
      ad_soyad: "Test Kullanıcı",
      rol,
      personel_id: personelId,
      personel_tipi_ad: personelTipiAd,
      sube_ids: [1],
      sirket_ids: [],
      sgk_isveren_ids: []
    }
  } as unknown as AuthSession;
}

afterEach(() => {
  cleanup();
  sessionState.current = null;
});

async function renderHome() {
  render(
    <MemoryRouter initialEntries={["/"]}>
      <PersonelSelfServiceHomePage />
    </MemoryRouter>
  );
  await screen.findByTestId("personel-attendance-boxes");
}

describe("PERSONEL collar → QR UI entitlement (render level)", () => {
  it("E) PERSONEL + Mavi Yaka: QR actions are visible", async () => {
    setSession("PERSONEL", "Mavi Yaka");
    await renderHome();

    expect(screen.getByTestId("giris-scan")).toBeInTheDocument();
    expect(screen.queryByTestId("giris-scan-not-entitled")).toBeNull();
    expect(screen.getByTestId("self-qr-scan-link")).toBeInTheDocument();
    expect(screen.getByTestId("self-qr-history-link")).toBeInTheDocument();
  });

  it("E) PERSONEL + Beyaz Yaka: QR actions hidden, own info preserved", async () => {
    setSession("PERSONEL", "Beyaz Yaka");
    await renderHome();

    expect(screen.queryByTestId("giris-scan")).toBeNull();
    expect(screen.queryByTestId("self-qr-scan-link")).toBeNull();
    expect(screen.queryByTestId("self-qr-history-link")).toBeNull();
    expect(screen.getByTestId("giris-scan-not-entitled")).toBeInTheDocument();
    // G) non-QR own self-service surfaces stay available.
    expect(screen.getByTestId("attendance-box-cikis")).toBeInTheDocument();
    expect(screen.getByTestId("personel-mobile-header")).toHaveTextContent("Self Personel");
  });

  it("E) PERSONEL + Diğer: QR actions hidden, own info preserved", async () => {
    setSession("PERSONEL", "Diğer");
    await renderHome();

    expect(screen.queryByTestId("giris-scan")).toBeNull();
    expect(screen.queryByTestId("self-qr-scan-link")).toBeNull();
    expect(screen.getByTestId("giris-scan-not-entitled")).toBeInTheDocument();
    expect(screen.getByTestId("attendance-box-cikis")).toBeInTheDocument();
  });

  it("E) PERSONEL + null/unknown collar: QR fails closed", async () => {
    for (const collar of [null, "", "Bilinmeyen Statu"]) {
      setSession("PERSONEL", collar);
      await renderHome();

      expect(screen.queryByTestId("giris-scan"), `collar=${collar}`).toBeNull();
      expect(screen.getByTestId("giris-scan-not-entitled")).toBeInTheDocument();
      expect(screen.getByTestId("attendance-box-cikis")).toBeInTheDocument();
      cleanup();
    }
  });

  it("H) bound manager roles follow the same role-independent collar gate", async () => {
    for (const role of ["BIRIM_AMIRI", "BOLUM_YONETICISI", "SUBE_YONETICISI"] as const) {
      setSession(role, "Mavi Yaka");
      await renderHome();

      expect(screen.getByTestId("self-qr-scan-link"), role).toBeInTheDocument();
      expect(screen.getByTestId("self-qr-history-link"), role).toBeInTheDocument();
      expect(screen.getByTestId("giris-scan"), role).toBeInTheDocument();
      cleanup();
    }
  });

  it("H2) bound Beyaz Yaka manager keeps own info but gets no QR", async () => {
    for (const role of ["BIRIM_AMIRI", "BOLUM_YONETICISI"] as const) {
      setSession(role, "Beyaz Yaka");
      await renderHome();

      expect(screen.queryByTestId("self-qr-scan-link"), role).toBeNull();
      expect(screen.queryByTestId("self-qr-history-link"), role).toBeNull();
      expect(screen.queryByTestId("giris-scan"), role).toBeNull();
      // G) kendi bilgisi ve non-QR self-service korunur.
      expect(screen.getByTestId("personel-mobile-header"), role).toHaveTextContent("Self Personel");
      expect(screen.getByTestId("giris-scan-not-entitled"), role).toBeInTheDocument();
      cleanup();
    }
  });

  it("H3) unbound manager never gets QR even with a canonical collar value", async () => {
    setSession("BIRIM_AMIRI", "Mavi Yaka", null);
    await renderHome();

    expect(screen.queryByTestId("self-qr-scan-link")).toBeNull();
    expect(screen.queryByTestId("giris-scan")).toBeNull();
    expect(screen.getByTestId("giris-scan-not-entitled")).toBeInTheDocument();
  });
});

describe("H4) BIRIM_AMIRI operational home QR reachability (render level)", () => {
  async function renderManagerHome() {
    render(
      <MemoryRouter initialEntries={["/"]}>
        <BirimAmiriOperationalHomePage />
      </MemoryRouter>
    );
    await screen.findByTestId("birim-amiri-operational-home");
  }

  it("shows the shared QR CTA and GİRİŞ/ÇIKIŞ boxes for a bound Mavi Yaka manager", async () => {
    setSession("BIRIM_AMIRI", "Mavi Yaka");
    await renderManagerHome();

    expect(screen.getByTestId("self-service-qr-section")).toBeInTheDocument();
    expect(screen.getByTestId("self-qr-scan-link")).toHaveAttribute("href", "/self/qr-okut");
    expect(screen.getByTestId("self-qr-history-link")).toHaveAttribute("href", "/self/qr-hareketleri");
    // Pilot UX parity: amir home own boxes expose the same GİRİŞ CTA as PERSONEL.
    expect(screen.getByTestId("birim-amiri-own-attendance")).toBeInTheDocument();
    expect(screen.getByTestId("giris-scan")).toBeInTheDocument();
    expect(screen.getByTestId("qr-puantaj-expectation-note")).toBeInTheDocument();
    // Yönetim yüzeyi kaybolmaz.
    expect(screen.getByTestId("birim-amiri-edit-daily")).toBeInTheDocument();
  });

  it("renders no QR CTA or scan boxes for a bound Beyaz Yaka manager", async () => {
    setSession("BIRIM_AMIRI", "Beyaz Yaka");
    await renderManagerHome();

    expect(screen.queryByTestId("self-service-qr-section")).toBeNull();
    expect(screen.queryByTestId("self-qr-scan-link")).toBeNull();
    expect(screen.queryByTestId("giris-scan")).toBeNull();
    expect(screen.getByTestId("birim-amiri-edit-daily")).toBeInTheDocument();
  });
});

describe("H5) BOLUM_YONETICISI self-service/QR entry via the shared owner", () => {
  it("bound Mavi Yaka manager reaches /self and the QR surface", () => {
    setSession("BOLUM_YONETICISI", "Mavi Yaka");
    render(
      <MemoryRouter initialEntries={["/"]}>
        <SelfServiceQrShortcuts title="Kendi QR / Kart Okutmam" showSelfServiceHomeLink />
      </MemoryRouter>
    );

    expect(screen.getByTestId("self-service-home-link")).toHaveAttribute("href", "/self");
    expect(screen.getByTestId("self-qr-scan-link")).toHaveAttribute("href", "/self/qr-okut");
    expect(screen.getByTestId("self-qr-history-link")).toHaveAttribute("href", "/self/qr-hareketleri");
  });

  it("bound Beyaz Yaka manager gets no QR entry", () => {
    setSession("BOLUM_YONETICISI", "Beyaz Yaka");
    const { container } = render(
      <MemoryRouter initialEntries={["/"]}>
        <SelfServiceQrShortcuts title="Kendi QR / Kart Okutmam" showSelfServiceHomeLink />
      </MemoryRouter>
    );

    expect(container).toBeEmptyDOMElement();
  });
});

describe("F) direct QR route follows the same collar gate", () => {
  function renderScanRoute() {
    render(
      <MemoryRouter initialEntries={["/self/qr-okut"]}>
        <Routes>
          <Route
            path="/self/qr-okut"
            element={
              <ProtectedRoute requirePermission="self_service.qr.scan">
                <div>QR-SCAN-ALLOWED</div>
              </ProtectedRoute>
            }
          />
          <Route path="/yetkisiz" element={<div>YETKISIZ</div>} />
        </Routes>
      </MemoryRouter>
    );
  }

  it("allows Mavi Yaka and denies Beyaz Yaka / Diğer / unknown", () => {
    setSession("PERSONEL", "Mavi Yaka");
    renderScanRoute();
    expect(screen.getByText("QR-SCAN-ALLOWED")).toBeInTheDocument();
    cleanup();

    for (const collar of ["Beyaz Yaka", "Diğer", null]) {
      setSession("PERSONEL", collar);
      renderScanRoute();
      expect(screen.queryByText("QR-SCAN-ALLOWED"), `collar=${collar}`).toBeNull();
      expect(screen.getByText("YETKISIZ")).toBeInTheDocument();
      cleanup();
    }
  });

  it("F2) manager roles use the same permission-based guard (no role condition)", () => {
    setSession("BOLUM_YONETICISI", "Mavi Yaka");
    renderScanRoute();
    expect(screen.getByText("QR-SCAN-ALLOWED")).toBeInTheDocument();
    cleanup();

    setSession("BIRIM_AMIRI", "Mavi Yaka");
    renderScanRoute();
    expect(screen.getByText("QR-SCAN-ALLOWED")).toBeInTheDocument();
    cleanup();

    setSession("BOLUM_YONETICISI", "Beyaz Yaka");
    renderScanRoute();
    expect(screen.queryByText("QR-SCAN-ALLOWED")).toBeNull();
    expect(screen.getByText("YETKISIZ")).toBeInTheDocument();
  });
});
