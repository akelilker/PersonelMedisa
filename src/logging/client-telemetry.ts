/**
 * Privacy-safe client telemetry owner.
 *
 * Security: never send passwords, tokens, Authorization, bodies, or HR PII.
 * Delivery: best-effort, bounded, recursion-guarded; failures must not re-enter logging.
 */

import { getAppEnv, getAppVersion, isDevRuntime, isProductionBuild } from "../config/app-env";
import { getAuthTokenForApi } from "../auth/auth-token-provider";
import { MEDISA_AUTH_SESSION_KEY } from "../auth/auth-constants";
import type { AuthSession } from "../types/auth";

export const TELEMETRY_SCHEMA_VERSION = 3;
export const TELEMETRY_SCHEMA_KEY = "medisa_telemetry_schema_version";
export const ERROR_STORE_KEY = "medisa_client_errors";
export const API_FAIL_STORE_KEY = "medisa_client_api_fails";

const MAX_PENDING_PREAUTH = 10;
const MAX_STRING_LEN = 256;
const MAX_STACK_LOCAL = 1500;
const DEDUPE_WINDOW_MS = 15_000;
const GLOBAL_WINDOW_MS = 60_000;
const GLOBAL_MAX_EVENTS = 20;

export type TelemetryEventType = "client_error" | "api_fail" | "react_boundary" | "window_error" | "unhandled_rejection";

/** Strict allowlist contract for central + local telemetry metadata. */
export type PrivacySafeTelemetryPayload = {
  event_type: TelemetryEventType;
  error_fingerprint: string;
  error_code?: string;
  source?: string;
  route_template?: string;
  endpoint_template?: string;
  status?: number;
  method?: string;
  app_version: string;
  app_env: string;
  release_sha?: string;
  timestamp: string;
  request_id?: string;
  attempt_count?: number;
  user_id: number | null;
  active_sube_id: number | null;
  ui_profile: string | null;
};

const ALLOWED_PAYLOAD_KEYS = new Set([
  "event_type",
  "error_fingerprint",
  "error_code",
  "source",
  "route_template",
  "endpoint_template",
  "status",
  "method",
  "app_version",
  "app_env",
  "release_sha",
  "timestamp",
  "request_id",
  "attempt_count",
  "user_id",
  "active_sube_id",
  "ui_profile"
]);

type ClockFn = () => number;
let nowFn: ClockFn = () => Date.now();

/** Test-only clock injection (no real sleeps in rate-limit tests). */
export function __setTelemetryNowForTests(fn: ClockFn | null): void {
  nowFn = fn ?? (() => Date.now());
}

let deliveryInFlight = false;
let recursionDepth = 0;
let muteTelemetryUntil = 0;
const recentFingerprints = new Map<string, number>();
let globalWindowStart = 0;
let globalWindowCount = 0;
const preAuthBuffer: PrivacySafeTelemetryPayload[] = [];

const SENSITIVE_PATTERNS: RegExp[] = [
  /Bearer\s+[A-Za-z0-9._\-+=/]+/gi,
  /password["']?\s*[:=]\s*["'][^"']{0,200}/gi,
  /current_password["']?\s*[:=]\s*["'][^"']{0,200}/gi,
  /new_password["']?\s*[:=]\s*["'][^"']{0,200}/gi,
  /Authorization["']?\s*[:=]\s*["'][^"']{0,400}/gi,
  /Cookie["']?\s*[:=]\s*["'][^"']{0,400}/gi,
  /\btc[_-]?kimlik[_-]?no\b["']?\s*[:=]\s*["']?\d{5,15}/gi,
  /\b\d{11}\b/g,
  /eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/g
];

export function sanitizeTelemetryText(input: string | undefined | null, maxLen = MAX_STRING_LEN): string {
  if (!input) {
    return "";
  }
  let out = String(input);
  for (const re of SENSITIVE_PATTERNS) {
    out = out.replace(re, "[REDACTED]");
  }
  out = out.replace(/\s+/g, " ").trim();
  if (out.length > maxLen) {
    out = out.slice(0, maxLen);
  }
  return out;
}

/** Local diagnostics only — never send raw stack to central telemetry. */
export function sanitizeStackForLocal(stack: string | undefined | null): string | undefined {
  if (!stack) {
    return undefined;
  }
  const cleaned = sanitizeTelemetryText(stack, MAX_STACK_LOCAL);
  return cleaned.length > 0 ? cleaned : undefined;
}

export function toRouteTemplate(pathname: string): string {
  const cleaned = sanitizeTelemetryText(pathname, 200);
  return cleaned
    .replace(/\/\d+/g, "/:id")
    .replace(/\/[0-9a-f]{8,}(?:-[0-9a-f]{4,})+/gi, "/:id");
}

export function toEndpointTemplate(path: string): string {
  const cleaned = sanitizeTelemetryText(path.split("?")[0] ?? path, 200);
  return cleaned.replace(/\/\d+/g, "/:id");
}

function djb2(input: string): string {
  let hash = 5381;
  for (let i = 0; i < input.length; i += 1) {
    hash = (hash * 33) ^ input.charCodeAt(i);
  }
  return (hash >>> 0).toString(16);
}

export function buildErrorFingerprint(parts: {
  event_type: string;
  error_code?: string;
  source?: string;
  route_template?: string;
  endpoint_template?: string;
  status?: number | string;
}): string {
  const normalized = [
    sanitizeTelemetryText(parts.event_type, 64),
    sanitizeTelemetryText(parts.error_code, 64),
    sanitizeTelemetryText(parts.source, 64),
    sanitizeTelemetryText(parts.route_template, 128),
    sanitizeTelemetryText(parts.endpoint_template, 128),
    String(parts.status ?? "")
  ].join("|");
  return `fp_${djb2(normalized)}`;
}

export function pickAllowlistedTelemetry(
  input: Record<string, unknown>
): PrivacySafeTelemetryPayload | null {
  const eventType = input.event_type;
  if (
    eventType !== "client_error" &&
    eventType !== "api_fail" &&
    eventType !== "react_boundary" &&
    eventType !== "window_error" &&
    eventType !== "unhandled_rejection"
  ) {
    return null;
  }
  if (typeof input.error_fingerprint !== "string" || !input.error_fingerprint.trim()) {
    return null;
  }

  const out: PrivacySafeTelemetryPayload = {
    event_type: eventType,
    error_fingerprint: sanitizeTelemetryText(input.error_fingerprint, 64),
    app_version: sanitizeTelemetryText(String(input.app_version ?? getAppVersion()), 32),
    app_env: sanitizeTelemetryText(String(input.app_env ?? getAppEnv()), 32),
    timestamp:
      typeof input.timestamp === "string" && input.timestamp
        ? sanitizeTelemetryText(input.timestamp, 40)
        : new Date(nowFn()).toISOString(),
    user_id: typeof input.user_id === "number" && Number.isFinite(input.user_id) ? input.user_id : null,
    active_sube_id:
      typeof input.active_sube_id === "number" && Number.isFinite(input.active_sube_id)
        ? input.active_sube_id
        : null,
    ui_profile:
      typeof input.ui_profile === "string" ? sanitizeTelemetryText(input.ui_profile, 64) || null : null
  };

  if (typeof input.error_code === "string") {
    out.error_code = sanitizeTelemetryText(input.error_code, 64) || undefined;
  }
  if (typeof input.source === "string") {
    out.source = sanitizeTelemetryText(input.source, 64) || undefined;
  }
  if (typeof input.route_template === "string") {
    out.route_template = sanitizeTelemetryText(input.route_template, 128) || undefined;
  }
  if (typeof input.endpoint_template === "string") {
    out.endpoint_template = sanitizeTelemetryText(input.endpoint_template, 128) || undefined;
  }
  if (typeof input.status === "number" && Number.isFinite(input.status)) {
    out.status = input.status;
  }
  if (typeof input.method === "string") {
    out.method = sanitizeTelemetryText(input.method, 16).toUpperCase() || undefined;
  }
  if (typeof input.release_sha === "string") {
    out.release_sha = sanitizeTelemetryText(input.release_sha, 64) || undefined;
  }
  if (typeof input.request_id === "string") {
    out.request_id = sanitizeTelemetryText(input.request_id, 64) || undefined;
  }
  if (typeof input.attempt_count === "number" && Number.isFinite(input.attempt_count)) {
    out.attempt_count = Math.max(0, Math.floor(input.attempt_count));
  }

  for (const key of Object.keys(out)) {
    if (!ALLOWED_PAYLOAD_KEYS.has(key)) {
      delete (out as Record<string, unknown>)[key];
    }
  }

  return out;
}

function shouldAcceptByRateLimit(fingerprint: string): boolean {
  const now = nowFn();
  const last = recentFingerprints.get(fingerprint);
  if (last !== undefined && now - last < DEDUPE_WINDOW_MS) {
    return false;
  }

  if (now - globalWindowStart > GLOBAL_WINDOW_MS) {
    globalWindowStart = now;
    globalWindowCount = 0;
  }
  if (globalWindowCount >= GLOBAL_MAX_EVENTS) {
    return false;
  }

  recentFingerprints.set(fingerprint, now);
  globalWindowCount += 1;

  if (recentFingerprints.size > 200) {
    for (const [fp, ts] of recentFingerprints) {
      if (now - ts > DEDUPE_WINDOW_MS) {
        recentFingerprints.delete(fp);
      }
    }
  }

  return true;
}

function resolveTelemetryPostUrl(): string {
  if (typeof window === "undefined") {
    return "/api/client-telemetry";
  }
  try {
    const path = window.location?.pathname ?? "";
    if (path.startsWith("/personelmedisa")) {
      return "/personelmedisa/api/client-telemetry";
    }
  } catch {
    /* ignore */
  }
  return "/api/client-telemetry";
}

async function postTelemetry(payload: PrivacySafeTelemetryPayload): Promise<void> {
  const token = getAuthTokenForApi();
  if (!token) {
    if (preAuthBuffer.length < MAX_PENDING_PREAUTH) {
      preAuthBuffer.push(payload);
    }
    return;
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 4_000);
  try {
    await fetch(resolveTelemetryPostUrl(), {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`
      },
      body: JSON.stringify(payload),
      signal: controller.signal
    });
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Central telemetry sender. Never throws to callers. Never re-enters logError/logApiFailure5xx.
 */
export function sendPrivacySafeTelemetry(raw: Record<string, unknown>): boolean {
  if (recursionDepth > 0 || deliveryInFlight) {
    return false;
  }
  if (nowFn() < muteTelemetryUntil) {
    return false;
  }

  const payload = pickAllowlistedTelemetry(raw);
  if (!payload) {
    return false;
  }

  if (!shouldAcceptByRateLimit(payload.error_fingerprint)) {
    return false;
  }

  recursionDepth += 1;
  deliveryInFlight = true;
  try {
    void postTelemetry(payload).catch(() => {
      // Swallow + mute briefly so delivery failures cannot recurse via unhandledrejection.
      muteTelemetryUntil = nowFn() + 3_000;
    });
    return true;
  } finally {
    deliveryInFlight = false;
    recursionDepth -= 1;
  }
}

/** After successful login: flush privacy-safe pre-auth buffer (still allowlisted only). */
export function flushPreAuthTelemetry(): void {
  if (preAuthBuffer.length === 0) {
    return;
  }
  const pending = preAuthBuffer.splice(0, preAuthBuffer.length);
  for (const item of pending) {
    sendPrivacySafeTelemetry(item as unknown as Record<string, unknown>);
  }
}

export function __resetTelemetryGuardsForTests(): void {
  deliveryInFlight = false;
  recursionDepth = 0;
  muteTelemetryUntil = 0;
  recentFingerprints.clear();
  globalWindowStart = 0;
  globalWindowCount = 0;
  preAuthBuffer.length = 0;
  nowFn = () => Date.now();
}

export function readOpaqueAuthContext(): {
  user_id: number | null;
  active_sube_id: number | null;
  ui_profile: string | null;
} {
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
    if (typeof parsed !== "object" || parsed === null) {
      return { user_id: null, active_sube_id: null, ui_profile: null };
    }
    const s = parsed as Partial<AuthSession>;
    if (typeof s.token !== "string" || typeof s.ui_profile !== "string" || typeof s.user !== "object" || !s.user) {
      return { user_id: null, active_sube_id: null, ui_profile: null };
    }
    const uid = s.user.id;
    return {
      user_id: typeof uid === "number" && Number.isFinite(uid) ? uid : null,
      active_sube_id:
        s.active_sube_id !== null && s.active_sube_id !== undefined && typeof s.active_sube_id === "number"
          ? s.active_sube_id
          : null,
      ui_profile: s.ui_profile
    };
  } catch {
    return { user_id: null, active_sube_id: null, ui_profile: null };
  }
}

export function buildBaseTelemetryFields(source?: string): {
  app_version: string;
  app_env: string;
  timestamp: string;
  user_id: number | null;
  active_sube_id: number | null;
  ui_profile: string | null;
  route_template: string;
  source?: string;
} {
  const ctx = readOpaqueAuthContext();
  let route = "";
  if (typeof window !== "undefined") {
    try {
      route = window.location?.pathname ?? "";
    } catch {
      route = "";
    }
  }
  return {
    app_version: getAppVersion(),
    app_env: getAppEnv(),
    timestamp: new Date(nowFn()).toISOString(),
    user_id: ctx.user_id,
    active_sube_id: ctx.active_sube_id,
    ui_profile: ctx.ui_profile,
    route_template: toRouteTemplate(route),
    ...(source ? { source: sanitizeTelemetryText(source, 64) } : {})
  };
}

export function shouldAttachLocalStack(): boolean {
  return !isProductionBuild() || isDevRuntime();
}
