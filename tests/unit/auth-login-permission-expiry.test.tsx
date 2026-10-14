// @vitest-environment jsdom
import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { login } from "../../src/api/auth.api";
import { MEDISA_AUTH_SESSION_KEY } from "../../src/auth/auth-constants";
import { useRoleAccess } from "../../src/hooks/use-role-access";
import { AuthProvider } from "../../src/state/auth.store";

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock("../../src/api/api-client", async (original) => ({
  ...await original<typeof import("../../src/api/api-client")>(), apiRequest: request
}));
vi.mock("../../src/config/app-env", async (original) => ({
  ...await original<typeof import("../../src/config/app-env")>(), isRealBackendOnlyMode: () => true
}));
vi.mock("../../src/data/data-manager", () => ({
  bumpAppDataRevision: vi.fn(), clearAllAppPersistence: vi.fn(), loadDataFromServer: vi.fn(async () => {})
}));
vi.mock("../../src/audit/audit-service", () => ({ logAction: vi.fn() }));
vi.mock("../../src/realtime/realtime-manager", () => ({ disconnect: vi.fn() }));
vi.mock("../../src/logging/client-telemetry", () => ({ flushPreAuthTelemetry: vi.fn() }));

const start = Date.parse("2026-10-14T12:00:00Z");
function snapshot() {
  return {
    effective_permissions: ["puantaj.view", "finans.view"],
    kaynaklar: { "puantaj.view": "ROLE_DEFAULT", "finans.view": "USER_ALLOW" },
    istisnalar: [{ permission: "finans.view", etki: "ALLOW", gecerlilik_bitis: "2026-10-14 12:00:02" }]
  };
}
function Probe() {
  const { hasPermission } = useRoleAccess();
  return <>
    <button disabled={!hasPermission("finans.view")}>Finans</button>
    <button disabled={!hasPermission("puantaj.view")}>Puantaj</button>
  </>;
}
async function mountLogin(complete = true) {
  request.mockResolvedValueOnce({ data: {
    token: "login-expiry-token", user: {
      id: 3, ad_soyad: "Test", rol: "BIRIM_AMIRI",
      ...(complete ? snapshot() : { effective_permissions: ["puantaj.view", "finans.view"] })
    }
  } });
  const session = await login({ username: "test", password: "test-password" });
  window.sessionStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify(session));
  render(<AuthProvider><Probe /></AuthProvider>);
  await act(async () => {});
}

describe("login snapshot with failed first permission refresh", () => {
  beforeEach(() => {
    vi.useFakeTimers(); vi.setSystemTime(start);
    window.localStorage.clear(); window.sessionStorage.clear();
    request.mockReset(); request.mockRejectedValue(new TypeError("offline"));
  });
  afterEach(() => { cleanup(); vi.useRealTimers(); window.sessionStorage.clear(); });

  it("expires login ALLOW despite failed first and subsequent reads, while retaining role access", async () => {
    await mountLogin();
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(false);
    expect((screen.getByText("Puantaj") as HTMLButtonElement).disabled).toBe(false);
    await act(async () => { await vi.advanceTimersByTimeAsync(2_000); });
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByText("Puantaj") as HTMLButtonElement).disabled).toBe(false);
    await act(async () => { await vi.advanceTimersByTimeAsync(60_000); });
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(true);
    expect(request.mock.calls.length).toBeGreaterThanOrEqual(4);
  });

  it("keeps unknown login expiry closed and recovers from a later complete read", async () => {
    await mountLogin(false);
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(true);
    request.mockResolvedValueOnce({ data: snapshot() });
    await act(async () => { window.dispatchEvent(new Event("focus")); });
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(false);
    expect((screen.getByText("Puantaj") as HTMLButtonElement).disabled).toBe(false);
    await act(async () => { await vi.advanceTimersByTimeAsync(2_000); });
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(true);
  });

  it("starts the login expiry timer even while the first refresh is unresolved", async () => {
    let resolveRead!: (value: unknown) => void;
    request.mockImplementation(() => new Promise((resolve) => { resolveRead = resolve; }));
    await mountLogin();
    await act(async () => { await vi.advanceTimersByTimeAsync(2_000); });
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(true);
    await act(async () => { resolveRead({ data: snapshot() }); });
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(true);
  });

  it("does not trust a previous-version stored login list without expiry metadata", async () => {
    window.sessionStorage.setItem(MEDISA_AUTH_SESSION_KEY, JSON.stringify({
      token: "old-token", ui_profile: "birim_amiri", active_sube_id: null,
      permissions_hydrated: true, permissions_source: "server",
      user: { id: 3, ad_soyad: "Test", rol: "BIRIM_AMIRI", sube_ids: [], effective_permissions: ["finans.view"] }
    }));
    render(<AuthProvider><Probe /></AuthProvider>);
    await act(async () => {});
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(true);
    request.mockResolvedValueOnce({ data: snapshot() });
    await act(async () => { window.dispatchEvent(new Event("focus")); });
    expect((screen.getByText("Finans") as HTMLButtonElement).disabled).toBe(false);
  });
});
