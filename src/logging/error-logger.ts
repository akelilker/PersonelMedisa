import { MEDISA_AUTH_SESSION_KEY } from "../auth/auth-constants";
import type { AuthSession } from "../types/auth";
import { getAppEnv, getAppVersion, isDevRuntime } from "../config/app-env";
import {
  API_FAIL_STORE_KEY,
  ERROR_STORE_KEY,
  TELEMETRY_SCHEMA_KEY,
  TELEMETRY_SCHEMA_VERSION,
  buildBaseTelemetryFields,
  buildErrorFingerprint,
  sanitizeStackForLocal,
  sanitizeTelemetryText,
  sendPrivacySafeTelemetry,
  toEndpointTemplate,
  toRouteTemplate
} from "./client-telemetry";

const MAX_ERRORS = 50;
const MAX_API_FAILS = 50;

export type LogUserContext = {
  user_id: number | null;
  active_sube_id: number | null;
  ui_profile: string | null;
};

export type ClientErrorLogEntry = {
  kind: "client_error";
  message: string;
  /** Sanitized error stack (never raw secrets). */
  error_stack?: string;
  /** Sanitized React component stack — separate from error_stack. */
  component_stack?: string;
  source?: string;
  error_fingerprint?: string;
  error_code?: string;
  user_id: number | null;
  active_sube_id: number | null;
  ui_profile: string | null;
  route: string;
  route_template?: string;
  app_version: string;
  app_env: string;
  timestamp: string;
};

export type ApiFailureLogEntry = {
  kind: "api_fail";
  endpoint: string;
  endpoint_template?: string;
  status: number;
  method: string;
  error_fingerprint?: string;
  user_id: number | null;
  active_sube_id: number | null;
  ui_profile: string | null;
  route: string;
  route_template?: string;
  app_version: string;
  app_env: string;
  timestamp: string;
  attempt_count?: number;
};

function readRoute(): string {
  if (typeof window === "undefined") {
    return "";
  }
  try {
    return window.location?.pathname ?? "";
  } catch {
    return "";
  }
}

function isAuthSession(value: unknown): value is AuthSession {
  if (typeof value === "object" && value !== null) {
    const s = value as Partial<AuthSession>;
    return (
      typeof s.token === "string" &&
      typeof s.ui_profile === "string" &&
      typeof s.user === "object" &&
      s.user !== null
    );
  }
  return false;
}

export function readAuthContextForLogging(): LogUserContext {
  if (typeof window === "undefined") {
    return { user_id: null, active_sube_id: null, ui_profile: null };
  }
  try {
    const raw =
      window.sessionStorage.getItem(MEDISA_AUTH_SESSION_KEY) ??
      window.localStorage.getItem(MEDISA_AUTH_SESSION_KEY);
    if (!raw?.trim()) {
      return { user_id: null, active_sube_id: null, ui_profile: null };
    }
    const parsed = JSON.parse(raw) as unknown;
    if (!isAuthSession(parsed)) {
      return { user_id: null, active_sube_id: null, ui_profile: null };
    }
    const uid = parsed.user.id;
    return {
      user_id: typeof uid === "number" && Number.isFinite(uid) ? uid : null,
      active_sube_id:
        parsed.active_sube_id !== null &&
        parsed.active_sube_id !== undefined &&
        typeof parsed.active_sube_id === "number"
          ? parsed.active_sube_id
          : null,
      ui_profile: parsed.ui_profile
    };
  } catch {
    return { user_id: null, active_sube_id: null, ui_profile: null };
  }
}

let errorBuffer: ClientErrorLogEntry[] = [];
let apiFailBuffer: ApiFailureLogEntry[] = [];

/**
 * Idempotent purge of legacy unsafe local telemetry (schema v1 payload_summary era).
 * Does not print secret contents to console.
 */
export function purgeUnsafeLegacyLogs(): void {
  if (typeof window === "undefined") {
    return;
  }
  try {
    window.localStorage.removeItem(ERROR_STORE_KEY);
    window.localStorage.removeItem(API_FAIL_STORE_KEY);
    window.localStorage.setItem(TELEMETRY_SCHEMA_KEY, String(TELEMETRY_SCHEMA_VERSION));
  } catch {
    /* ignore quota errors */
  }
}

function loadBuffersFromStorage(): void {
  if (typeof window === "undefined") {
    return;
  }
  try {
    const storedVersion = Number.parseInt(window.localStorage.getItem(TELEMETRY_SCHEMA_KEY) ?? "0", 10);
    if (storedVersion < TELEMETRY_SCHEMA_VERSION) {
      purgeUnsafeLegacyLogs();
      errorBuffer = [];
      apiFailBuffer = [];
      return;
    }

    const e = window.localStorage.getItem(ERROR_STORE_KEY);
    if (e) {
      const p = JSON.parse(e) as unknown;
      if (Array.isArray(p)) {
        errorBuffer = (p.filter((x) => x && typeof x === "object") as ClientErrorLogEntry[]).slice(
          -MAX_ERRORS
        );
      }
    }
    const a = window.localStorage.getItem(API_FAIL_STORE_KEY);
    if (a) {
      const p = JSON.parse(a) as unknown;
      if (Array.isArray(p)) {
        apiFailBuffer = (p.filter((x) => x && typeof x === "object") as ApiFailureLogEntry[]).slice(
          -MAX_API_FAILS
        );
      }
    }
  } catch {
    errorBuffer = [];
    apiFailBuffer = [];
  }
}

function persistErrors(): void {
  if (typeof window === "undefined") {
    return;
  }
  try {
    window.localStorage.setItem(ERROR_STORE_KEY, JSON.stringify(errorBuffer.slice(-MAX_ERRORS)));
  } catch {
    /* quota */
  }
}

function persistApiFails(): void {
  if (typeof window === "undefined") {
    return;
  }
  try {
    window.localStorage.setItem(API_FAIL_STORE_KEY, JSON.stringify(apiFailBuffer.slice(-MAX_API_FAILS)));
  } catch {
    /* quota */
  }
}

loadBuffersFromStorage();

export type LogErrorInput = {
  message: string;
  /** @deprecated Prefer error_stack / component_stack. */
  stack?: string;
  error_stack?: string;
  component_stack?: string;
  source?: string;
  error_code?: string;
  user_id?: number | null;
  active_sube_id?: number | null;
  ui_profile?: string | null;
  route?: string;
};

/**
 * Client/uncaught errors — PII-free local buffer + best-effort central telemetry.
 * Never stores request bodies, tokens, or credentials.
 */
export function logError(input: LogErrorInput): void {
  const base = buildBaseTelemetryFields(input.source);
  const route = input.route ?? readRoute();
  const routeTemplate = toRouteTemplate(route);
  const message = sanitizeTelemetryText(input.message, 256) || "client_error";
  const errorStack = sanitizeStackForLocal(input.error_stack ?? input.stack);
  const componentStack = sanitizeStackForLocal(input.component_stack);
  const eventType =
    input.source === "ErrorBoundary" || input.source === "RootErrorBoundary"
      ? "react_boundary"
      : input.source === "window.onerror"
        ? "window_error"
        : input.source === "window.onunhandledrejection"
          ? "unhandled_rejection"
          : "client_error";
  const fingerprint = buildErrorFingerprint({
    event_type: eventType,
    error_code: input.error_code,
    source: input.source,
    route_template: routeTemplate
  });

  const entry: ClientErrorLogEntry = {
    kind: "client_error",
    message,
    error_stack: errorStack,
    component_stack: componentStack,
    source: input.source,
    error_fingerprint: fingerprint,
    error_code: input.error_code,
    user_id: input.user_id ?? base.user_id,
    active_sube_id: input.active_sube_id ?? base.active_sube_id,
    ui_profile: input.ui_profile ?? base.ui_profile,
    route,
    route_template: routeTemplate,
    app_version: base.app_version,
    app_env: base.app_env,
    timestamp: base.timestamp
  };

  errorBuffer = [...errorBuffer.slice(-(MAX_ERRORS - 1)), entry];
  persistErrors();

  sendPrivacySafeTelemetry({
    event_type: eventType,
    error_fingerprint: fingerprint,
    error_code: input.error_code,
    source: input.source,
    route_template: routeTemplate,
    app_version: entry.app_version,
    app_env: entry.app_env,
    timestamp: entry.timestamp,
    user_id: entry.user_id,
    active_sube_id: entry.active_sube_id,
    ui_profile: entry.ui_profile
  });

  if (isDevRuntime()) {
    console.error("[medisa-error]", {
      message: entry.message,
      source: entry.source,
      error_fingerprint: entry.error_fingerprint,
      route_template: entry.route_template
    });
  }
}

/**
 * 5xx API failures — endpoint/status/method metadata only (no bodies).
 */
export function logApiFailure5xx(input: {
  endpoint: string;
  status: number;
  method?: string;
  attempt_count?: number;
}): void {
  const base = buildBaseTelemetryFields("api_fail");
  const method = (input.method ?? "GET").toUpperCase();
  const endpointTemplate = toEndpointTemplate(input.endpoint);
  const fingerprint = buildErrorFingerprint({
    event_type: "api_fail",
    source: "api_fail",
    endpoint_template: endpointTemplate,
    status: input.status
  });

  const entry: ApiFailureLogEntry = {
    kind: "api_fail",
    endpoint: sanitizeTelemetryText(input.endpoint, 200),
    endpoint_template: endpointTemplate,
    status: input.status,
    method,
    error_fingerprint: fingerprint,
    user_id: base.user_id,
    active_sube_id: base.active_sube_id,
    ui_profile: base.ui_profile,
    route: readRoute(),
    route_template: base.route_template,
    app_version: base.app_version,
    app_env: base.app_env,
    timestamp: base.timestamp,
    ...(typeof input.attempt_count === "number" ? { attempt_count: input.attempt_count } : {})
  };

  apiFailBuffer = [...apiFailBuffer.slice(-(MAX_API_FAILS - 1)), entry];
  persistApiFails();

  sendPrivacySafeTelemetry({
    event_type: "api_fail",
    error_fingerprint: fingerprint,
    source: "api_fail",
    endpoint_template: endpointTemplate,
    status: input.status,
    method,
    attempt_count: input.attempt_count,
    app_version: entry.app_version,
    app_env: entry.app_env,
    timestamp: entry.timestamp,
    user_id: entry.user_id,
    active_sube_id: entry.active_sube_id,
    ui_profile: entry.ui_profile,
    route_template: entry.route_template
  });

  if (isDevRuntime()) {
    console.warn("[medisa-api-fail]", {
      endpoint_template: entry.endpoint_template,
      status: entry.status,
      method: entry.method,
      attempt_count: entry.attempt_count,
      error_fingerprint: entry.error_fingerprint
    });
  }
}

export function getRecentClientErrors(): readonly ClientErrorLogEntry[] {
  return errorBuffer;
}

export function getRecentApiFailures(): readonly ApiFailureLogEntry[] {
  return apiFailBuffer;
}

/** Test helper: clear in-memory buffers without touching unrelated storage. */
export function __resetErrorLoggerBuffersForTests(): void {
  errorBuffer = [];
  apiFailBuffer = [];
}
