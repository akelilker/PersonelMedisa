import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiRequest } from "../../src/api/api-client";
import {
  __resetTelemetryGuardsForTests
} from "../../src/logging/client-telemetry";
import {
  __resetErrorLoggerBuffersForTests,
  getRecentApiFailures
} from "../../src/logging/error-logger";

describe("security-telemetry", () => {
  beforeEach(() => {
    __resetTelemetryGuardsForTests();
    __resetErrorLoggerBuffersForTests();
    vi.stubGlobal("window", {
      localStorage: {
        getItem: vi.fn(() => null),
        setItem: vi.fn(),
        removeItem: vi.fn()
      },
      sessionStorage: {
        getItem: vi.fn(() => null),
        setItem: vi.fn(),
        removeItem: vi.fn()
      },
      location: {
        pathname: "/test-route"
      }
    });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    __resetTelemetryGuardsForTests();
    __resetErrorLoggerBuffersForTests();
  });

  it("does not log request body for login 500 error", async () => {
    const fetchMock = vi.fn(async () => new Response("Internal Server Error", { status: 500 }));
    vi.stubGlobal("fetch", fetchMock);

    const password = "my-secret-test-password";
    await expect(
      apiRequest("/auth/login", {
        method: "POST",
        body: JSON.stringify({ username: "user", password })
      })
    ).rejects.toThrow();

    const recentFailures = getRecentApiFailures();
    expect(recentFailures).toHaveLength(1);
    const logEntry = recentFailures[0];

    expect(logEntry.kind).toBe("api_fail");
    expect(logEntry.endpoint).toBe("/auth/login");
    expect(logEntry.status).toBe(500);
    expect(logEntry.method).toBe("POST");
    expect(logEntry).not.toHaveProperty("payload_summary");

    const stringifiedLog = JSON.stringify(logEntry);
    expect(stringifiedLog).not.toContain(password);
  });

  it("does not log request body for change-password 503 error", async () => {
    const fetchMock = vi.fn(async () => new Response("Service Unavailable", { status: 503 }));
    vi.stubGlobal("fetch", fetchMock);

    const currentPassword = "my-current-password";
    const newPassword = "my-super-secret-new-password";

    await expect(
      apiRequest("/auth/change-password", {
        method: "POST",
        body: JSON.stringify({ current_password: currentPassword, new_password: newPassword })
      })
    ).rejects.toThrow();

    const recentFailures = getRecentApiFailures();
    expect(recentFailures).toHaveLength(1);
    const logEntry = recentFailures[0];

    expect(logEntry).not.toHaveProperty("payload_summary");
    const stringifiedLog = JSON.stringify(logEntry);
    expect(stringifiedLog).not.toContain(currentPassword);
    expect(stringifiedLog).not.toContain(newPassword);
  });

  it("does not log request body for personel create 500 error", async () => {
    const fetchMock = vi.fn(async () => new Response("Internal Server Error", { status: 500 }));
    vi.stubGlobal("fetch", fetchMock);

    const tcKimlikNo = "12345678901";
    const telefon = "5551234567";

    await expect(
      apiRequest("/personeller", {
        method: "POST",
        body: JSON.stringify({
          ad: "test",
          soyad: "user",
          tc_kimlik_no: tcKimlikNo,
          telefon: telefon
        })
      })
    ).rejects.toThrow();

    const recentFailures = getRecentApiFailures();
    expect(recentFailures).toHaveLength(1);
    const logEntry = recentFailures[0];

    expect(logEntry).not.toHaveProperty("payload_summary");
    const stringifiedLog = JSON.stringify(logEntry);
    expect(stringifiedLog).not.toContain(tcKimlikNo);
    expect(stringifiedLog).not.toContain(telefon);
  });
});
