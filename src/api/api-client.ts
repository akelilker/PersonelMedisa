import { getApiMode, isDemoApiFallbackEnabled } from "../config/app-env";
import { getAppPublicPath } from "../config/public-base";
import { shouldEmitGlobalAuthForbidden } from "../lib/api-forbidden-policy";
import { emitApiServerError } from "../lib/storage/api-global-events";
import { emitAuthForbidden, emitAuthUnauthorized } from "../lib/storage/auth-events";
import type { ApiError, ApiResponse } from "../types/api";
import { getActiveSubeIdForApiHeader } from "../auth/auth-manager";
import { getAuthTokenForApi } from "../auth/auth-token-provider";
import { logApiFailure5xx } from "../logging/error-logger";
import { resolveDemoApiResponse } from "./mock-demo";

const ENV_META = (import.meta as ImportMeta & { env?: Record<string, string | boolean | undefined> }).env;
const ENV_API_BASE_URL = typeof ENV_META?.VITE_API_BASE_URL === "string" ? ENV_META.VITE_API_BASE_URL : undefined;

function normalizeBase(base: string) {
  const trimmed = base.trim();
  if (!trimmed) {
    return "";
  }

  return trimmed.endsWith("/") ? trimmed.slice(0, -1) : trimmed;
}

function readWindowPathname() {
  if (typeof window === "undefined") {
    return "";
  }

  const maybeLocation = (window as Window & { location?: Location }).location;
  return typeof maybeLocation?.pathname === "string" ? maybeLocation.pathname : "";
}

function isLocalDemoHost() {
  if (typeof window === "undefined") {
    return false;
  }

  const maybeLocation = (window as Window & { location?: Location }).location;
  const hostname = maybeLocation?.hostname ?? "";
  const port = maybeLocation?.port ?? "";
  return (hostname === "localhost" || hostname === "127.0.0.1") && port !== "4173";
}

export function shouldPreferDemoApi() {
  if (!isDemoApiFallbackEnabled()) {
    return false;
  }

  const mode = getApiMode();
  if (mode === "real") {
    return false;
  }

  if (mode === "demo") {
    return true;
  }

  // auto mode logic
  const envBase = normalizeBase(ENV_API_BASE_URL ?? "");
  if (envBase) {
    return false;
  }

  const pub = getAppPublicPath();
  const path = readWindowPathname();
  if (isLocalDemoHost()) {
    return true;
  }

  if (pub && path.startsWith(pub)) {
    return true;
  }
  return path.startsWith("/personelmedisa");
}

function resolveApiBaseCandidates() {
  const candidates: string[] = [];
  const envBase = normalizeBase(ENV_API_BASE_URL ?? "");
  const pathname = readWindowPathname();
  const publicPath = getAppPublicPath();
  const subPath = publicPath || "/personelmedisa";
  const isSubfolderDeployment =
    (publicPath.length > 0 && pathname.startsWith(publicPath)) || pathname.startsWith("/personelmedisa");

  if (envBase) {
    candidates.push(envBase);
  }

  if (isSubfolderDeployment) {
    candidates.push(`${subPath}/api`);
    candidates.push("/api");
  } else {
    candidates.push("/api");
  }

  return candidates.filter((candidate, index) => candidates.indexOf(candidate) === index);
}

export function buildApiUrl(path: string, baseUrl = resolveApiBaseCandidates()[0] ?? "/api") {
  return `${baseUrl}${path.startsWith("/") ? path : `/${path}`}`;
}

export class ApiRequestError extends Error {
  status: number;
  code?: string;
  field?: string;

  constructor(message: string, status: number, detail?: Pick<ApiError, "code" | "field">) {
    super(message);
    this.name = "ApiRequestError";
    this.status = status;
    this.code = detail?.code;
    this.field = detail?.field;
  }
}

export function shouldQueueOfflineMutation(error: unknown): boolean {
  if (typeof navigator !== "undefined" && navigator.onLine === false) {
    return true;
  }

  return error instanceof ApiRequestError && error.status === 0;
}

export type ApiErrorDetail = {
  message: string;
  status?: number;
  code?: string;
  field?: string;
};

export type GetApiErrorMessageOptions = {
  context?: "personel-create";
};

export function isApiRequestError(error: unknown): error is ApiRequestError {
  return error instanceof ApiRequestError;
}

const DUPLICATE_TC_MESSAGE = "Bu T.C. Kimlik No ile kayıt açılamaz.";
const PERSONEL_CREATE_FORBIDDEN_SUBE_YETKI_MESSAGE = "Seçili şube için yetkiniz yok.";
const PERSONEL_CREATE_FORBIDDEN_SUBE_SCOPE_MESSAGE =
  "Seçilen şube aktif şube filtresiyle uyuşmuyor.";

function resolvePersonelCreateForbiddenMessage(backendMessage: string, fallbackMessage: string): string {
  const normalized = backendMessage.trim().toLowerCase();
  if (!normalized) {
    return fallbackMessage;
  }

  if (normalized.includes("secili sube") && normalized.includes("yetkiniz")) {
    return PERSONEL_CREATE_FORBIDDEN_SUBE_YETKI_MESSAGE;
  }

  if (
    normalized.includes("aktif sube") ||
    normalized.includes("baglaminda") ||
    normalized.includes("goruntulenemiyor")
  ) {
    return PERSONEL_CREATE_FORBIDDEN_SUBE_SCOPE_MESSAGE;
  }

  return backendMessage.trim();
}

function resolveApiErrorDetail(
  error: unknown,
  fallbackMessage: string,
  options?: GetApiErrorMessageOptions
): ApiErrorDetail {
  if (error instanceof ApiRequestError) {
    const rawMessage = error.message.trim();
    const baseDetail: ApiErrorDetail = {
      message: rawMessage || fallbackMessage,
      status: error.status,
      code: error.code,
      field: error.field
    };

    if (error.code === "DUPLICATE_TC_KIMLIK_NO") {
      return {
        ...baseDetail,
        message: rawMessage || DUPLICATE_TC_MESSAGE,
        field: error.field ?? "tc_kimlik_no"
      };
    }

    if (error.code === "FORBIDDEN" && options?.context === "personel-create") {
      return {
        ...baseDetail,
        message: resolvePersonelCreateForbiddenMessage(rawMessage, fallbackMessage)
      };
    }

    return baseDetail;
  }

  if (error instanceof Error && error.message.trim()) {
    return { message: error.message.trim() };
  }

  return { message: fallbackMessage };
}

export function getApiErrorDetail(
  error: unknown,
  fallbackMessage: string,
  options?: GetApiErrorMessageOptions
): ApiErrorDetail {
  return resolveApiErrorDetail(error, fallbackMessage, options);
}

export function getApiErrorMessage(
  error: unknown,
  fallbackMessage: string,
  options?: GetApiErrorMessageOptions
): string {
  return getApiErrorDetail(error, fallbackMessage, options).message;
}

function extractFirstApiError(payload: unknown): ApiError | null {
  if (typeof payload !== "object" || payload === null) {
    return null;
  }

  const maybeResponse = payload as Partial<ApiResponse<unknown>>;
  if (!Array.isArray(maybeResponse.errors) || maybeResponse.errors.length === 0) {
    return null;
  }

  const first = maybeResponse.errors[0];
  if (!first || typeof first.message !== "string") {
    return null;
  }

  return {
    code: typeof first.code === "string" ? first.code : "UNKNOWN_ERROR",
    field: typeof first.field === "string" ? first.field : undefined,
    message: first.message
  };
}

async function parseResponseBody(response: Response): Promise<unknown> {
  const raw = await response.text();
  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw);
  } catch {
    return raw;
  }
}

function shouldAttachAuthHeader(path: string) {
  return !path.startsWith("/auth/login");
}

function isUnauthorizedStatus(status: number) {
  return status === 401;
}

function isForbiddenStatus(status: number) {
  return status === 403;
}

export type ApiRequestInit = RequestInit & {
  /** Caller-owned stable key; transport never generates one. */
  idempotencyKey?: string;
  /** Per-request timeout override (ms). */
  timeoutMs?: number;
};

const DEFAULT_REQUEST_TIMEOUT_MS = 30_000;
const MAX_SAFE_TRANSPORT_ATTEMPTS = 3;
const MAX_RETRY_DELAY_MS = 2_000;

export function isSafeHttpMethod(method: string): boolean {
  const normalized = method.trim().toUpperCase();
  return normalized === "GET" || normalized === "HEAD";
}

export function isTransientReadStatus(status: number): boolean {
  return status === 408 || status === 429 || status === 502 || status === 503 || status === 504;
}

export function resolveRequestTimeoutMs(init?: Pick<ApiRequestInit, "timeoutMs">): number {
  if (typeof init?.timeoutMs === "number" && Number.isFinite(init.timeoutMs) && init.timeoutMs > 0) {
    return Math.floor(init.timeoutMs);
  }
  return DEFAULT_REQUEST_TIMEOUT_MS;
}

type SleepFn = (ms: number) => Promise<void>;

let transportSleep: SleepFn = (ms) =>
  new Promise((resolve) => {
    setTimeout(resolve, ms);
  });

/** Test-only: replace sleep to keep retry tests fast. Pass null to restore. */
export function __setTransportSleepForTests(fn: SleepFn | null): void {
  transportSleep =
    fn ??
    ((ms) =>
      new Promise((resolve) => {
        setTimeout(resolve, ms);
      }));
}

function parseRetryAfterMs(response: Response): number | null {
  const raw = response.headers.get("Retry-After");
  if (!raw) {
    return null;
  }

  const asSeconds = Number(raw);
  if (Number.isFinite(asSeconds) && asSeconds >= 0) {
    return Math.min(asSeconds * 1000, MAX_RETRY_DELAY_MS);
  }

  const asDate = Date.parse(raw);
  if (!Number.isNaN(asDate)) {
    return Math.min(Math.max(0, asDate - Date.now()), MAX_RETRY_DELAY_MS);
  }

  return null;
}

export function computeTransportBackoffMs(attemptIndex: number, retryAfterMs: number | null = null): number {
  if (retryAfterMs != null) {
    return retryAfterMs;
  }
  const exponential = Math.min(100 * 2 ** Math.max(0, attemptIndex), MAX_RETRY_DELAY_MS);
  const jitter = Math.floor(Math.random() * 50);
  return Math.min(exponential + jitter, MAX_RETRY_DELAY_MS);
}

function composeTimeoutSignal(
  external: AbortSignal | null | undefined,
  timeoutMs: number
): { signal: AbortSignal; didTimeout: () => boolean; cleanup: () => void } {
  const controller = new AbortController();
  let timedOut = false;

  const timeoutId = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, timeoutMs);

  const onExternalAbort = () => {
    controller.abort();
  };

  if (external) {
    if (external.aborted) {
      controller.abort();
    } else {
      external.addEventListener("abort", onExternalAbort, { once: true });
    }
  }

  return {
    signal: controller.signal,
    didTimeout: () => timedOut,
    cleanup: () => {
      clearTimeout(timeoutId);
      if (external) {
        external.removeEventListener("abort", onExternalAbort);
      }
    }
  };
}

function classifyFetchAbortError(
  error: unknown,
  didTimeout: boolean,
  externalSignal: AbortSignal | null | undefined
): ApiRequestError {
  if (didTimeout) {
    return new ApiRequestError("İstek zaman aşımına uğradı.", 0, { code: "REQUEST_TIMEOUT" });
  }

  if (externalSignal?.aborted) {
    return new ApiRequestError("İstek iptal edildi.", 0, { code: "REQUEST_ABORTED" });
  }

  const message =
    error instanceof Error && error.message.trim() ? error.message : "API request failed.";
  return new ApiRequestError(message, 0);
}

function isAbortError(error: unknown): boolean {
  if (!error || typeof error !== "object") {
    return false;
  }
  const name = (error as { name?: string }).name;
  return name === "AbortError" || name === "TimeoutError";
}

function buildRequestHeaders(path: string, init?: ApiRequestInit): Headers {
  const headers = new Headers(init?.headers ?? {});
  const hasJsonBody = init?.body !== undefined && !(init.body instanceof FormData);

  if (hasJsonBody && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  if (shouldAttachAuthHeader(path) && !headers.has("Authorization")) {
    const token = getAuthTokenForApi();
    if (token) {
      headers.set("Authorization", `Bearer ${token}`);
    }
  }

  if (shouldAttachAuthHeader(path)) {
    const subeHeader = getActiveSubeIdForApiHeader();
    if (subeHeader && !headers.has("X-Active-Sube-Id")) {
      headers.set("X-Active-Sube-Id", subeHeader);
    }
  }

  // Preserve caller Idempotency-Key; never mint one in transport.
  if (init?.idempotencyKey && !headers.has("Idempotency-Key")) {
    headers.set("Idempotency-Key", init.idempotencyKey);
  }

  return headers;
}

function toFetchInit(init: ApiRequestInit | undefined, headers: Headers, signal: AbortSignal): RequestInit {
  const { idempotencyKey: _key, timeoutMs: _timeout, signal: _signal, ...rest } = init ?? {};
  return {
    ...rest,
    headers,
    signal
  };
}

function emitStatusSideEffects(
  path: string,
  method: string,
  status: number,
  payload: unknown,
  options: { logServerError: boolean; attemptCount: number }
): void {
  if (isUnauthorizedStatus(status)) {
    if (getAuthTokenForApi()) {
      emitAuthUnauthorized({ status, path });
    }
    return;
  }

  if (isForbiddenStatus(status)) {
    if (shouldEmitGlobalAuthForbidden(path, method)) {
      emitAuthForbidden({ status, path });
    }
    return;
  }

  if (status >= 500 && options.logServerError) {
    logApiFailure5xx({
      endpoint: path,
      status,
      method,
      attempt_count: options.attemptCount
    });
    const apiError = extractFirstApiError(payload);
    emitApiServerError({
      message: apiError?.message ?? "Sunucu hatasi. Lutfen daha sonra tekrar deneyin.",
      status
    });
  }
}

/**
 * Transport owner for all HTTP API calls.
 *
 * Security: attaches Authorization + X-Active-Sube-Id except `/auth/login`; never mints Idempotency-Key.
 * Retry: GET/HEAD only — bounded base failover + transient status retry (408/429/502/503/504).
 * Mutations (POST/PUT/PATCH/DELETE): fail-closed — no blind cross-base retry after uncertain network.
 * Timeout/abort: AbortController timeout → REQUEST_TIMEOUT; caller AbortSignal → REQUEST_ABORTED (no retry).
 * Privacy: 5xx telemetry is metadata-only (no request/response bodies).
 */
export async function apiRequest<T>(path: string, init?: ApiRequestInit): Promise<T> {
  if (shouldPreferDemoApi()) {
    const mock = resolveDemoApiResponse(path, init);
    if (mock !== null) {
      return mock as T;
    }
  }

  const method = (init?.method ?? "GET").toUpperCase();
  const safe = isSafeHttpMethod(method);
  const baseCandidates = resolveApiBaseCandidates();
  const bases = safe ? baseCandidates : baseCandidates.slice(0, 1);
  const requestHeaders = buildRequestHeaders(path, init);
  const timeoutMs = resolveRequestTimeoutMs(init);
  const externalSignal = init?.signal ?? undefined;

  let lastError: ApiRequestError | null = null;
  let attemptCount = 0;
  let pendingServerError: { status: number; payload: unknown } | null = null;

  const flushFinalServerError = () => {
    if (!pendingServerError) {
      return;
    }
    emitStatusSideEffects(path, method, pendingServerError.status, pendingServerError.payload, {
      logServerError: true,
      attemptCount
    });
    pendingServerError = null;
  };

  for (let baseIndex = 0; baseIndex < bases.length; baseIndex += 1) {
    const base = bases[baseIndex];
    let retryOnSameBase = 0;

    while (true) {
      if (attemptCount >= MAX_SAFE_TRANSPORT_ATTEMPTS && safe) {
        flushFinalServerError();
        throw lastError ?? new ApiRequestError("API request failed.", 500);
      }

      attemptCount += 1;
      const composed = composeTimeoutSignal(externalSignal, timeoutMs);

      let response: Response;
      try {
        response = await fetch(buildApiUrl(path, base), toFetchInit(init, requestHeaders, composed.signal));
        composed.cleanup();
      } catch (error) {
        composed.cleanup();
        const classified = isAbortError(error)
          ? classifyFetchAbortError(error, composed.didTimeout(), externalSignal)
          : new ApiRequestError(
              error instanceof Error && error.message.trim() ? error.message : "API request failed.",
              0
            );

        lastError = classified;

        if (classified.code === "REQUEST_ABORTED" || classified.code === "REQUEST_TIMEOUT") {
          flushFinalServerError();
          throw classified;
        }

        // Mutation: never blind-failover to another base after uncertain network.
        if (!safe) {
          flushFinalServerError();
          throw classified;
        }

        // Safe: move to next base candidate (bounded by outer loop + attempt cap).
        break;
      }

      const payload = await parseResponseBody(response);

      if (response.ok) {
        return payload as T;
      }

      const apiError = extractFirstApiError(payload);
      lastError = new ApiRequestError(
        apiError?.message ?? `API request failed: ${response.status}`,
        response.status,
        apiError ?? undefined
      );

      if (isUnauthorizedStatus(response.status) || isForbiddenStatus(response.status)) {
        emitStatusSideEffects(path, method, response.status, payload, {
          logServerError: false,
          attemptCount
        });
        throw lastError;
      }

      if (!safe) {
        // Mutations: no cross-base failover (including 404 discovery).
        if (response.status >= 500) {
          emitStatusSideEffects(path, method, response.status, payload, {
            logServerError: true,
            attemptCount
          });
        }
        throw lastError;
      }

      // SAFE path from here.
      if (response.status === 404) {
        // Subfolder API discovery: try next base.
        break;
      }

      if (isTransientReadStatus(response.status)) {
        if (response.status >= 500) {
          pendingServerError = { status: response.status, payload };
        }

        const canRetrySameBase =
          retryOnSameBase + 1 < MAX_SAFE_TRANSPORT_ATTEMPTS &&
          attemptCount < MAX_SAFE_TRANSPORT_ATTEMPTS;

        if (canRetrySameBase) {
          const delayMs = computeTransportBackoffMs(retryOnSameBase, parseRetryAfterMs(response));
          retryOnSameBase += 1;
          await transportSleep(delayMs);
          continue;
        }

        // Exhausted same-base retries → failover to next candidate if any.
        if (baseIndex + 1 < bases.length && attemptCount < MAX_SAFE_TRANSPORT_ATTEMPTS) {
          break;
        }

        flushFinalServerError();
        throw lastError;
      }

      if (response.status >= 500) {
        emitStatusSideEffects(path, method, response.status, payload, {
          logServerError: true,
          attemptCount
        });
      }

      throw lastError;
    }
  }

  flushFinalServerError();

  if (isDemoApiFallbackEnabled()) {
    const mock = resolveDemoApiResponse(path, init);
    if (mock !== null) {
      return mock as T;
    }
  }

  throw lastError ?? new ApiRequestError("API request failed.", 500);
}
