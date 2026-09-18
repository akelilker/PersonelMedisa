// @vitest-environment jsdom

/**
 * Focused render-level proof for the PERSONEL collar → QR entitlement gate.
 *
 * Owner chain under test:
 *   session.user.personel_tipi_ad (DB mirror, written by the backend only)
 *     → useRoleAccess → hasUserPermission (collar gate)
 *       → PersonelSelfServiceHomePage QR UI + ProtectedRoute QR routes.
 *
 * The backend 403 (RolePermissions::assert) remains the authority; these tests
 * prove the fail-closed UX mirror for E) / F) and that non-QR own self-service
 * survives for every collar (G).
 */

import "@testing-library/jest-dom/vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import type { AttendanceTodayResponse } from "../../src/api/attendance-mobile.api";
import type { AuthSession } from "../../src/types/auth";
import { ProtectedRoute } from "../../src/router/ProtectedRoute";
import { PersonelSelfServiceHomePage } from "../../src/features/self-service/pages/PersonelSelfServiceHomePage";

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

function setSession(rol: string, personelTipiAd: string | null) {
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
      personel_id: 158,
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

  it("H) management role keeps the collar-independent personnel-linked contract", async () => {
    setSession("BOLUM_YONETICISI", "Beyaz Yaka");
    await renderHome();

    expect(screen.getByTestId("giris-scan")).toBeInTheDocument();
    expect(screen.getByTestId("self-qr-scan-link")).toBeInTheDocument();
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

  it("keeps the management route contract unchanged", () => {
    setSession("BOLUM_YONETICISI", "Beyaz Yaka");
    renderScanRoute();
    expect(screen.getByText("QR-SCAN-ALLOWED")).toBeInTheDocument();
  });
});
