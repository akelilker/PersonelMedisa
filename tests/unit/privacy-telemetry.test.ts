/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiRequest } from "../../src/api/api-client";
import {
  TELEMETRY_SCHEMA_KEY,
  TELEMETRY_SCHEMA_VERSION,
  __resetTelemetryGuardsForTests,
  __setTelemetryNowForTests,
  buildErrorFingerprint,
  pickAllowlistedTelemetry,
  sanitizeTelemetryText,
  sendPrivacySafeTelemetry
} from "../../src/logging/client-telemetry";
import {
  __resetErrorLoggerBuffersForTests,
  getRecentApiFailures,
  getRecentClientErrors,
  logApiFailure5xx,
  logError,
  purgeUnsafeLegacyLogs
} from "../../src/logging/error-logger";

const storage: Record<string, string> = {};

describe("privacy-safe telemetry", () => {
  beforeEach(() => {
    Object.keys(storage).forEach((k) => delete storage[k]);
    __resetTelemetryGuardsForTests();
    __resetErrorLoggerBuffersForTests();
    __setTelemetryNowForTests(() => 1_000_000);
    vi.stubGlobal("window", {
      localStorage: {
        getItem: (k: string) => storage[k] ?? null,
        setItem: (k: string, v: string) => {
          storage[k] = v;
        },
        removeItem: (k: string) => {
          delete storage[k];
        }
      },
      sessionStorage: {
        getItem: () => null,
        setItem: () => undefined,
        removeItem: () => undefined
      },
      location: { pathname: "/personeller/12" },
      dispatchEvent: () => true,
      addEventListener: () => undefined,
      removeEventListener: () => undefined
    });
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response(JSON.stringify({ data: { accepted: true } }), { status: 200 }))
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    __resetTelemetryGuardsForTests();
  });

  it("login 503 does not store password in telemetry/storage", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response("fail", { status: 503 })));
    const password = "super-secret-login-password-xyz";
    await expect(
      apiRequest("/auth/login", {
        method: "POST",
        body: JSON.stringify({ username: "u", password })
      })
    ).rejects.toMatchObject({ status: 503 });

    const blob = JSON.stringify({ fails: getRecentApiFailures(), errors: getRecentClientErrors(), storage });
    expect(blob).not.toContain(password);
    expect(getRecentApiFailures()[0]).not.toHaveProperty("payload_summary");
  });

  it("change-password 500 does not store current/new password", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response("fail", { status: 500 })));
    const currentPassword = "cur-pass-aaa";
    const newPassword = "new-pass-bbb";
    await expect(
      apiRequest("/auth/change-password", {
        method: "POST",
        body: JSON.stringify({ current_password: currentPassword, new_password: newPassword })
      })
    ).rejects.toMatchObject({ status: 500 });
    const blob = JSON.stringify({ fails: getRecentApiFailures(), storage });
    expect(blob).not.toContain(currentPassword);
    expect(blob).not.toContain(newPassword);
  });

  it("personel 500 does not store TC/telefon", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response("fail", { status: 500 })));
    const tc = "12345678901";
    const telefon = "5559876543";
    await expect(
      apiRequest("/personeller", {
        method: "POST",
        body: JSON.stringify({ ad: "A", soyad: "B", tc_kimlik_no: tc, telefon })
      })
    ).rejects.toMatchObject({ status: 500 });
    const blob = JSON.stringify(getRecentApiFailures());
    expect(blob).not.toContain(tc);
    expect(blob).not.toContain(telefon);
  });

  it("finans 500 does not store amount/maas fixture body", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response("fail", { status: 500 })));
    const amount = "987654.32";
    await expect(
      apiRequest("/finans", {
        method: "POST",
        body: JSON.stringify({ tutar: amount, maas: amount, aciklama: "prim" })
      })
    ).rejects.toMatchObject({ status: 500 });
    const blob = JSON.stringify(getRecentApiFailures());
    expect(blob).not.toContain(amount);
  });

  it("central telemetry payload is allowlisted only and omits client user_id", () => {
    const picked = pickAllowlistedTelemetry({
      event_type: "api_fail",
      error_fingerprint: "fp_abc",
      status: 500,
      method: "POST",
      endpoint_template: "/personeller",
      password: "nope",
      token: "secret-token",
      Authorization: "Bearer x",
      body: { tc_kimlik_no: "123" },
      app_version: "1.0.0",
      app_env: "test",
      timestamp: "2026-01-01T00:00:00.000Z",
      user_id: 99,
      active_sube_id: 2,
      ui_profile: "yonetim"
    });
    expect(picked).not.toBeNull();
    expect(picked).not.toHaveProperty("password");
    expect(picked).not.toHaveProperty("token");
    expect(picked).not.toHaveProperty("Authorization");
    expect(picked).not.toHaveProperty("body");
    expect(picked).not.toHaveProperty("user_id");
    expect(picked).not.toHaveProperty("active_sube_id");
    expect(picked).not.toHaveProperty("ui_profile");
    expect(picked!.client_active_sube_id).toBe(2);
    expect(picked!.client_ui_profile).toBe("yonetim");
    expect(Object.keys(picked!).sort()).toEqual(
      [
        "app_env",
        "app_version",
        "client_active_sube_id",
        "client_ui_profile",
        "endpoint_template",
        "error_fingerprint",
        "event_type",
        "method",
        "status",
        "timestamp"
      ].sort()
    );
  });

  it("telemetry sender failure does not affect business throw path", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response("fail", { status: 500 }))
      .mockRejectedValue(new Error("telemetry down"));
    vi.stubGlobal("fetch", fetchMock);

    await expect(apiRequest("/personeller")).rejects.toMatchObject({ status: 500 });
    // business path completed independently of telemetry delivery attempts
    expect(getRecentApiFailures()).toHaveLength(1);
  });

  it("telemetry sender failure does not recurse into infinite logging", async () => {
    let sendCount = 0;
    const fetchMock = vi.fn(async () => {
      sendCount += 1;
      throw new Error("delivery boom");
    });
    vi.stubGlobal("fetch", fetchMock);

    expect(
      sendPrivacySafeTelemetry({
        event_type: "client_error",
        error_fingerprint: "fp_loop",
        app_version: "1",
        app_env: "test",
        timestamp: "t",
        client_active_sube_id: null,
        client_ui_profile: null
      })
    ).toBe(true);

    await Promise.resolve();
    await Promise.resolve();

    for (let i = 0; i < 5; i += 1) {
      logError({
        message: "delivery boom",
        source: "window.onunhandledrejection",
        error_code: "UNHANDLED_REJECTION"
      });
    }
    expect(sendCount).toBeLessThanOrEqual(2);
  });

  it("same fingerprint burst is deduped", () => {
    const fetchMock = vi.fn(async () => new Response("{}", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);
    const fp = buildErrorFingerprint({
      event_type: "client_error",
      source: "t",
      route_template: "/x"
    });
    const payload = {
      event_type: "client_error" as const,
      error_fingerprint: fp,
      app_version: "1",
      app_env: "test",
      timestamp: "t",
      client_active_sube_id: null,
      client_ui_profile: null
    };
    expect(sendPrivacySafeTelemetry(payload)).toBe(true);
    expect(sendPrivacySafeTelemetry(payload)).toBe(false);
    expect(sendPrivacySafeTelemetry(payload)).toBe(false);
  });

  it("global rate limit is bounded", () => {
    const fetchMock = vi.fn(async () => new Response("{}", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);
    let accepted = 0;
    for (let i = 0; i < 40; i += 1) {
      const ok = sendPrivacySafeTelemetry({
        event_type: "client_error",
        error_fingerprint: `fp_${i}`,
        app_version: "1",
        app_env: "test",
        timestamp: "t",
        client_active_sube_id: null,
        client_ui_profile: null
      });
      if (ok) accepted += 1;
    }
    expect(accepted).toBeLessThanOrEqual(20);
  });

  it("old unsafe localStorage telemetry is purged on schema migrate", () => {
    storage["medisa_client_errors"] = JSON.stringify([
      { kind: "client_error", message: "x", password: "leak-pass", tc_kimlik_no: "12345678901" }
    ]);
    storage["medisa_client_api_fails"] = JSON.stringify([
      { kind: "api_fail", payload_summary: { password: "leak-pass" } }
    ]);
    storage[TELEMETRY_SCHEMA_KEY] = "1";
    purgeUnsafeLegacyLogs();
    expect(storage["medisa_client_errors"]).toBeUndefined();
    expect(storage["medisa_client_api_fails"]).toBeUndefined();
    expect(storage[TELEMETRY_SCHEMA_KEY]).toBe(String(TELEMETRY_SCHEMA_VERSION));
    expect(JSON.stringify(storage)).not.toContain("leak-pass");
    expect(JSON.stringify(storage)).not.toContain("12345678901");
  });

  it("token/Authorization never appear in telemetry storage", () => {
    logApiFailure5xx({ endpoint: "/personeller", status: 500, method: "GET" });
    logError({
      message: "Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.aaa.bbb failed Authorization: Bearer secret-token",
      source: "unit",
      error_stack: "Authorization: Bearer secret-token"
    });
    const blob = JSON.stringify({
      errors: getRecentClientErrors(),
      fails: getRecentApiFailures(),
      storage
    });
    expect(blob).not.toContain("secret-token");
    expect(blob).not.toMatch(/Bearer\s+eyJ/);
    expect(sanitizeTelemetryText("Authorization: Bearer abc.def.ghi")).toContain("[REDACTED]");
  });
});
