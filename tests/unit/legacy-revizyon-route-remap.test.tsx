// @vitest-environment jsdom

import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { MemoryRouter, Navigate, Route, Routes, useLocation } from "react-router-dom";
import { MEDISA_AUTH_SESSION_KEY } from "../../src/auth/auth-constants";
import { finalizeAuthSessionSube } from "../../src/auth/auth-session-sube";
import { ProtectedRoute } from "../../src/router/ProtectedRoute";
import { AuthProvider } from "../../src/state/auth.store";
import { ROUTE_PERMISSION } from "../../src/lib/authorization/role-permissions";
import type { AuthSession, UserRole } from "../../src/types/auth";

const ROUTER_FUTURE_FLAGS = {
  v7_startTransition: true,
  v7_relativeSplatPath: true
} as const;

function buildSession(role: UserRole): AuthSession {
  const sube_ids =
    role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : role === "BOLUM_YONETICISI" ? [1] : [];
  return finalizeAuthSessionSube({
    token: "test-token",
    ui_profile: role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim",
    active_sube_id: null,
    user: {
      id: 1,
      ad_soyad: "Test Kullanici",
      rol: role,
      sube_ids
    }
  });
}

function LocationProbe() {
  const location = useLocation();
  return (
    <div>
      <span data-testid="pathname">{location.pathname}</span>
      <span data-testid="history-idx">{String(window.history.state?.idx ?? "n/a")}</span>
    </div>
  );
}

function RemapFixture({ initialEntry }: { initialEntry: string }) {
  return (
    <MemoryRouter initialEntries={[initialEntry]} future={ROUTER_FUTURE_FLAGS}>
      <AuthProvider>
        <Routes>
          <Route
            path="/revizyon-merkezi"
            element={
              <ProtectedRoute requirePermission={ROUTE_PERMISSION.haftalikKapanisPage}>
                <Navigate to="/haftalik-kapanis/revizyonlar" replace />
              </ProtectedRoute>
            }
          />
          <Route
            path="/haftalik-kapanis/revizyonlar"
            element={
              <ProtectedRoute requirePermission={ROUTE_PERMISSION.haftalikKapanisPage}>
                <div>
                  <div data-testid="revizyon-merkezi-page">Canonical Revizyon</div>
                  <LocationProbe />
                </div>
              </ProtectedRoute>
            }
          />
          <Route path="/yetkisiz" element={<div data-testid="yetkisiz-page">Yetkisiz</div>} />
          <Route path="/login" element={<div>Login</div>} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  );
}

describe("legacy revizyon-merkezi route remap", () => {
  beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
  });

  afterEach(() => {
    cleanup();
    window.localStorage.clear();
    window.sessionStorage.clear();
  });

  it("replaces legacy path with canonical Revizyon Merkezi", () => {
    window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(buildSession("GENEL_YONETICI")));

    render(<RemapFixture initialEntry="/revizyon-merkezi" />);

    expect(screen.getByTestId("revizyon-merkezi-page")).not.toBeNull();
    expect(screen.getByTestId("pathname").textContent).toBe("/haftalik-kapanis/revizyonlar");
  });

  it("does not permission-bypass unauthorized roles via the legacy alias", () => {
    window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(buildSession("PERSONEL")));

    render(<RemapFixture initialEntry="/revizyon-merkezi" />);

    expect(screen.getByTestId("yetkisiz-page")).not.toBeNull();
    expect(screen.queryByTestId("revizyon-merkezi-page")).toBeNull();
  });
});
