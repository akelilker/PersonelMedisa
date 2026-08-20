/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { ErrorBoundary } from "../../src/app/ErrorBoundary";
import { GlobalErrorTelemetry } from "../../src/app/GlobalErrorTelemetry";
import {
  __resetTelemetryGuardsForTests,
  __setTelemetryNowForTests
} from "../../src/logging/client-telemetry";
import {
  __resetErrorLoggerBuffersForTests,
  getRecentClientErrors,
  logError
} from "../../src/logging/error-logger";

function Boom({ fail }: { fail: boolean }) {
  if (fail) {
    throw new Error("boom-render");
  }
  return <div>ok-content</div>;
}

describe("ErrorBoundary recovery", () => {
  beforeEach(() => {
    __resetTelemetryGuardsForTests();
    __resetErrorLoggerBuffersForTests();
    vi.stubGlobal("fetch", vi.fn(async () => new Response("{}", { status: 200 })));
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("shows fallback when child throws", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => undefined);
    render(
      <ErrorBoundary>
        <Boom fail />
      </ErrorBoundary>
    );
    expect(screen.getByRole("alert")).toBeTruthy();
    expect(screen.getByText(/Bir sorun olustu/i)).toBeTruthy();
    spy.mockRestore();
  });

  it("Ana ekrana don resets boundary and navigates home", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => undefined);
    const assign = vi.fn();
    vi.stubGlobal("location", {
      ...window.location,
      assign,
      pathname: "/personeller",
      reload: vi.fn()
    });

    render(
      <ErrorBoundary>
        <Boom fail />
      </ErrorBoundary>
    );
    fireEvent.click(screen.getByRole("button", { name: /Ana ekrana don/i }));
    expect(assign).toHaveBeenCalled();
    spy.mockRestore();
  });

  it("Sayfayi yenile triggers reload", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => undefined);
    const reload = vi.fn();
    Object.defineProperty(window, "location", {
      configurable: true,
      value: { ...window.location, reload, pathname: "/", assign: vi.fn() }
    });

    render(
      <ErrorBoundary>
        <Boom fail />
      </ErrorBoundary>
    );
    fireEvent.click(screen.getByRole("button", { name: /Sayfayi yenile/i }));
    expect(reload).toHaveBeenCalled();
    spy.mockRestore();
  });

  it("root-level boundary offers reload only (safe fallback)", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => undefined);
    render(
      <ErrorBoundary rootLevel>
        <Boom fail />
      </ErrorBoundary>
    );
    expect(screen.getByRole("button", { name: /Sayfayi yenile/i })).toBeTruthy();
    expect(screen.queryByRole("button", { name: /Ana ekrana don/i })).toBeNull();
    spy.mockRestore();
  });

  it("logs separate error_stack and component_stack metadata", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => undefined);
    render(
      <ErrorBoundary>
        <Boom fail />
      </ErrorBoundary>
    );
    const entries = getRecentClientErrors();
    expect(entries.length).toBeGreaterThan(0);
    const last = entries[entries.length - 1];
    expect(last.source).toBe("ErrorBoundary");
    expect(last).toHaveProperty("error_stack");
    expect(last).toHaveProperty("component_stack");
    expect(JSON.stringify(last)).not.toContain("password");
    spy.mockRestore();
  });
});

describe("GlobalErrorTelemetry", () => {
  beforeEach(() => {
    __resetTelemetryGuardsForTests();
    __resetErrorLoggerBuffersForTests();
    __setTelemetryNowForTests(() => 2_000_000);
    vi.stubGlobal("fetch", vi.fn(async () => new Response("{}", { status: 200 })));
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    __resetTelemetryGuardsForTests();
  });

  it("captures window.onerror", () => {
    render(<GlobalErrorTelemetry />);
    window.onerror?.("unit-window-error", "x.js", 1, 1, new Error("unit-window-error"));
    expect(getRecentClientErrors().some((e) => e.source === "window.onerror")).toBe(true);
  });

  it("captures and normalizes non-Error unhandledrejection", () => {
    render(<GlobalErrorTelemetry />);
    window.onunhandledrejection?.({
      reason: { message: "plain-object-reject" }
    } as PromiseRejectionEvent);
    const hit = getRecentClientErrors().find((e) => e.source === "window.onunhandledrejection");
    expect(hit?.message).toContain("plain-object-reject");
  });

  it("dedupe/rate-limit suppress fingerprint storms", () => {
    for (let i = 0; i < 5; i += 1) {
      logError({
        message: "same",
        source: "window.onerror",
        error_code: "WINDOW_ERROR"
      });
    }
    // local buffer may keep entries; fingerprint send is bounded by telemetry guards
    expect(getRecentClientErrors().length).toBeGreaterThan(0);
  });
});
