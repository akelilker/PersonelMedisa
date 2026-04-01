import { MEDISA_AUTH_SESSION_KEY } from "../auth/auth-constants";
import type { AuthSession } from "../types/auth";
import { getAppEnv, getAppVersion, isDevRuntime } from "../config/app-env";

const ERROR_STORE_KEY = "medisa_client_errors";
const API_FAIL_STORE_KEY = "medisa_client_api_fails";
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
  stack?: string;
  source?: string;
  user_id: number | null;
  active_sube_id: number | null;
  ui_profile: string | null;
  route: string;
  app_version: string;
  app_env: string;
  timestamp: string;
};

export type ApiFailureLogEntry = {
  kind: "api_fail";
  endpoint: string;
  status: number;
  method: string;
  payload_summary?: string;
  user_id: number | null;
  active_sube_id: number | null;
  ui_profile: string | null;
  route: string;
  app_version: string;
  app_env: string;
  timestamp: string;
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
  if (typeof value !== "object" || value === null) {
    return false;
  }
  const s = value as Partial<AuthSession>;
  return typeof s.token === "string" && typeof s.ui_profile === "string" && typeof s.user === "object" && s.user !== null;
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

function loadBuffersFromStorage(): void {
  if (typeof window === "undefined") {
    return;
  }
  try {
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
  stack?: string;
  source?: string;
  user_id?: number | null;
  active_sube_id?: number | null;
  ui_profile?: string | null;
  route?: string;
};

/**
 * Islem (audit) kanalindan ayri: istemci ve yakalanmamis hatalar.
 */
export function logError(input: LogErrorInput): void {
  const ctx = readAuthContextForLogging();
  const entry: ClientErrorLogEntry = {
    kind: "client_error",
    message: input.message,
    stack: input.stack,
    source: input.source,
    user_id: input.user_id ?? ctx.user_id,
    active_sube_id: input.active_sube_id ?? ctx.active_sube_id,
    ui_profile: input.ui_profile ?? ctx.ui_profile,
    route: input.route ?? readRoute(),
    app_version: getAppVersion(),
    app_env: getAppEnv(),
    timestamp: new Date().toISOString()
  };

  errorBuffer = [...errorBuffer.slice(-(MAX_ERRORS - 1)), entry];
  persistErrors();

  if (isDevRuntime()) {
    console.error("[medisa-error]", entry);
  }
}

export function logApiFailure5xx(input: {
  endpoint: string;
  status: number;
  method?: string;
  payload_summary?: string;
}): void {
  const ctx = readAuthContextForLogging();
  const entry: ApiFailureLogEntry = {
    kind: "api_fail",
    endpoint: input.endpoint,
    status: input.status,
    method: (input.method ?? "GET").toUpperCase(),
    payload_summary: input.payload_summary,
    user_id: ctx.user_id,
    active_sube_id: ctx.active_sube_id,
    ui_profile: ctx.ui_profile,
    route: readRoute(),
    app_version: getAppVersion(),
    app_env: getAppEnv(),
    timestamp: new Date().toISOString()
  };

  apiFailBuffer = [...apiFailBuffer.slice(-(MAX_API_FAILS - 1)), entry];
  persistApiFails();

  if (isDevRuntime()) {
    console.warn("[medisa-api-fail]", entry);
  }
}

export function getRecentClientErrors(): readonly ClientErrorLogEntry[] {
  return errorBuffer;
}

export function getRecentApiFailures(): readonly ApiFailureLogEntry[] {
  return apiFailBuffer;
}

export function summarizeRequestBodyForLogs(init?: RequestInit): string | undefined {
  if (!init?.body) {
    return undefined;
  }
  if (typeof init.body === "string") {
    const t = init.body.trim();
    if (!t) {
      return undefined;
    }
    return t.length > 400 ? `${t.slice(0, 400)}…` : t;
  }
  return "[binary-or-form-body]";
}
