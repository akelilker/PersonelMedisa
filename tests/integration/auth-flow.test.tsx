// @vitest-environment jsdom

import { act, cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { AppProviders } from "../../src/app/providers";
import { MEDISA_AUTH_SESSION_KEY } from "../../src/auth/auth-constants";
import { emitAuthForbidden, emitAuthUnauthorized } from "../../src/lib/storage/auth-events";
import { ProtectedRoute } from "../../src/router/ProtectedRoute";
import { AuthProvider, useAuth } from "../../src/state/auth.store";
import type { AuthSession, UserRole } from "../../src/types/auth";

const ROUTER_FUTURE_FLAGS = {
  v7_startTransition: true,
  v7_relativeSplatPath: true
} as const;

function buildSession(role: UserRole): AuthSession {
  const sube_ids =
    role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : role === "BOLUM_YONETICISI" ? [1] : [];
  return {
    token: "test-token",
    ui_profile: role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim",
    user: {
      id: 1,
      ad_soyad: "Test Kullanici",
      rol: role,
      sube_ids
    }
  };
}

function AuthStateProbe() {
  const { isAuthenticated, session } = useAuth();
  return <div>{isAuthenticated ? `auth:${session?.user.rol}` : "anon"}</div>;
}

describe("auth flow integration", () => {
  beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
  });

  afterEach(() => {
    cleanup();
    window.localStorage.clear();
    window.sessionStorage.clear();
  });

  it("loads existing session from storage and authenticates provider state", () => {
    window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(buildSession("GENEL_YONETICI")));

    render(
      <AuthProvider>
        <AuthStateProbe />
      </AuthProvider>
    );

    expect(screen.getByText("auth:GENEL_YONETICI")).not.toBeNull();
  });

  it("force-logs out when unauthorized event is emitted", async () => {
    window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(buildSession("MUHASEBE")));

    render(
      <AuthProvider>
        <AuthStateProbe />
      </AuthProvider>
    );

    expect(screen.getByText("auth:MUHASEBE")).not.toBeNull();

    act(() => {
      emitAuthUnauthorized({
        status: 401,
        path: "/personeller"
      });
    });

    await waitFor(() => {
      expect(screen.getByText("anon")).not.toBeNull();
    });

    expect(window.localStorage.getItem(MEDISA_AUTH_SESSION_KEY)).toBeNull();
    expect(window.sessionStorage.getItem(MEDISA_AUTH_SESSION_KEY)).toBeNull();
  });

  it("redirects unauthenticated user to login route", () => {
    render(
      <MemoryRouter initialEntries={["/secured"]} future={ROUTER_FUTURE_FLAGS}>
        <AuthProvider>
          <Routes>
            <Route
              path="/secured"
              element={
                <ProtectedRoute>
                  <div>Secure Page</div>
                </ProtectedRoute>
              }
            />
            <Route path="/login" element={<div>Login Page</div>} />
          </Routes>
        </AuthProvider>
      </MemoryRouter>
    );

    expect(screen.getByText("Login Page")).not.toBeNull();
    expect(screen.queryByText("Secure Page")).toBeNull();
  });

  it("redirects authenticated but unauthorized permission to yetkisiz route", () => {
    window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(buildSession("BIRIM_AMIRI")));

    render(
      <MemoryRouter initialEntries={["/secured"]} future={ROUTER_FUTURE_FLAGS}>
        <AuthProvider>
          <Routes>
            <Route
              path="/secured"
              element={
                <ProtectedRoute requirePermission="personeller.create">
                  <div>Secure Page</div>
                </ProtectedRoute>
              }
            />
            <Route path="/yetkisiz" element={<div>Yetkisiz Page</div>} />
          </Routes>
        </AuthProvider>
      </MemoryRouter>
    );

    expect(screen.getByText("Yetkisiz Page")).not.toBeNull();
    expect(screen.queryByText("Secure Page")).toBeNull();
  });

  it("navigates to yetkisiz page on forbidden auth event", async () => {
    window.localStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(buildSession("GENEL_YONETICI")));
    window.history.pushState({}, "", "/surecler");

    render(
      <AppProviders>
        <Routes>
          <Route path="/surecler" element={<div>Surecler Page</div>} />
          <Route path="/yetkisiz" element={<div>Yetkisiz Page</div>} />
        </Routes>
      </AppProviders>
    );

    expect(screen.getByText("Surecler Page")).not.toBeNull();

    act(() => {
      emitAuthForbidden({
        status: 403,
        path: "/surecler"
      });
    });

    await waitFor(() => {
      expect(screen.getByText("Yetkisiz Page")).not.toBeNull();
    });
  });
});
