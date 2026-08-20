import { useEffect } from "react";
import { sanitizeTelemetryText } from "../logging/client-telemetry";
import { logError } from "../logging/error-logger";

declare global {
  interface WindowEventMap {
    unhandledrejection: PromiseRejectionEvent;
  }
}

function normalizeRejectionReason(reason: unknown): { message: string; error_stack?: string } {
  if (reason instanceof Error) {
    return {
      message: sanitizeTelemetryText(reason.message || "unhandledrejection", 256) || "unhandledrejection",
      error_stack: reason.stack
    };
  }
  if (typeof reason === "string") {
    return { message: sanitizeTelemetryText(reason, 256) || "unhandledrejection" };
  }
  if (reason && typeof reason === "object") {
    const maybeMessage = (reason as { message?: unknown }).message;
    if (typeof maybeMessage === "string" && maybeMessage.trim()) {
      return { message: sanitizeTelemetryText(maybeMessage, 256) || "unhandledrejection (non-Error)" };
    }
  }
  return { message: "unhandledrejection (non-Error)" };
}

/**
 * window-level errors + unhandled rejections outside ErrorBoundary.
 * Delivery goes through logError → privacy-safe sender (dedupe / rate-limit / recursion guard).
 */
export function GlobalErrorTelemetry() {
  useEffect(() => {
    if (typeof window === "undefined") {
      return undefined;
    }

    const prevOnError = window.onerror;
    const prevOnRejection = window.onunhandledrejection;

    window.onerror = (message, source, lineno, colno, error) => {
      if (typeof prevOnError === "function") {
        try {
          prevOnError.call(window, message, source, lineno, colno, error);
        } catch {
          /* ignore previous handler failures */
        }
      }
      const msg =
        sanitizeTelemetryText(typeof message === "string" ? message : "window.onerror", 256) ||
        "window.onerror";
      logError({
        message: msg,
        error_stack: error?.stack ?? (source ? `${String(source)}:${lineno}:${colno}` : undefined),
        source: "window.onerror",
        error_code: "WINDOW_ERROR"
      });
      return false;
    };

    window.onunhandledrejection = (event) => {
      if (typeof prevOnRejection === "function") {
        try {
          prevOnRejection.call(window, event);
        } catch {
          /* ignore */
        }
      }
      const normalized = normalizeRejectionReason(event.reason);
      logError({
        message: normalized.message,
        error_stack: normalized.error_stack,
        source: "window.onunhandledrejection",
        error_code: "UNHANDLED_REJECTION"
      });
    };

    return () => {
      window.onerror = prevOnError ?? null;
      window.onunhandledrejection = prevOnRejection ?? null;
    };
  }, []);

  return null;
}
