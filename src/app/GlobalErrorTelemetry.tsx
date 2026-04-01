import { useEffect } from "react";
import { logError } from "../logging/error-logger";

declare global {
  interface WindowEventMap {
    unhandledrejection: PromiseRejectionEvent;
  }
}

/**
 * window duzeyi hatalar + reddedilen sozler — ErrorBoundary disinda kalanlar.
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
        prevOnError.call(window, message, source, lineno, colno, error);
      }
      const msg = typeof message === "string" ? message : "window.onerror";
      logError({
        message: msg,
        stack: error?.stack ?? (source ? `${String(source)}:${lineno}:${colno}` : undefined),
        source: "window.onerror"
      });
      return false;
    };

    window.onunhandledrejection = (event) => {
      if (typeof prevOnRejection === "function") {
        prevOnRejection.call(window, event);
      }
      const reason = event.reason;
      if (reason instanceof Error) {
        logError({
          message: reason.message || "unhandledrejection",
          stack: reason.stack,
          source: "window.onunhandledrejection"
        });
      } else {
        logError({
          message: typeof reason === "string" ? reason : "unhandledrejection (non-Error)",
          stack: undefined,
          source: "window.onunhandledrejection"
        });
      }
    };

    return () => {
      window.onerror = prevOnError ?? null;
      window.onunhandledrejection = prevOnRejection ?? null;
    };
  }, []);

  return null;
}
