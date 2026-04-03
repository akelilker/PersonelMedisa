import { getActiveSubeIdForApiHeader } from "../auth/auth-manager";
import { getAuthTokenForApi } from "../auth/auth-token-provider";
import { emitAuthForbidden, emitAuthUnauthorized } from "../lib/storage/auth-events";
import { emitApiServerError } from "../lib/storage/api-global-events";
import type { ApiError, ApiResponse } from "../types/api";
import { resolveDemoApiResponse } from "./mock-demo";
import { logApiFailure5xx, summarizeRequestBodyForLogs } from "../logging/error-logger";
import { getAppPublicPath } from "../config/public-base";

const ENV_API_BASE_URL = (
  import.meta as ImportMeta & { env?: Record<string, string | undefined> }
).env?.VITE_API_BASE_URL;
const ENV_API_MODE = (
  import.meta as ImportMeta & { env?: Record<string, string | undefined> }
).env?.VITE_API_MODE;
const DEMO_API_FALLBACK_ENABLED =
  (
    (import.meta as ImportMeta & { env?: Record<string, string | undefined> }).env?.VITE_DEMO_API_FALLBACK ?? "true"
  ).toLowerCase() !== "false";

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

function resolveApiMode() {
  const normalized = (ENV_API_MODE ?? "").trim().toLowerCase();
  if (normalized === "real") {
    return "real";
  }

  if (normalized === "demo" || normalized === "mock") {
    return "demo";
  }

  return "auto";
}

function shouldPreferDemoApi() {
  if (!DEMO_API_FALLBACK_ENABLED) {
    return false;
  }

  const mode = resolveApiMode();
  if (mode === "real") {
    return false;
  }

  if (mode === "demo") {
    return true;
  }

  const envBase = normalizeBase(ENV_API_BASE_URL ?? "");
  if (envBase) {
    return false;
  }

  const pub = getAppPublicPath();
  const path = readWindowPathname();
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

export function getApiErrorMessage(error: unknown, fallbackMessage: string): string {
  if (error instanceof ApiRequestError && error.message.trim()) {
    return error.message;
  }

  if (error instanceof Error && error.message.trim()) {
    return error.message;
  }

  return fallbackMessage;
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

function buildRequestHeaders(path: string, init?: RequestInit): Headers {
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

  return headers;
}

export async function apiRequest<T>(path: string, init?: RequestInit): Promise<T> {
  if (shouldPreferDemoApi()) {
    const mock = resolveDemoApiResponse(path, init);
    if (mock !== null) {
      return mock as T;
    }
  }

  const baseCandidates = resolveApiBaseCandidates();
  const requestHeaders = buildRequestHeaders(path, init);

  let lastError: ApiRequestError | null = null;

  for (const base of baseCandidates) {
    let response: Response;
    try {
      response = await fetch(buildApiUrl(path, base), {
        ...init,
        headers: requestHeaders
      });
    } catch (error) {
      lastError = new ApiRequestError(
        error instanceof Error ? error.message : "API request failed.",
        0
      );
      continue;
    }

    const payload = await parseResponseBody(response);

    if (response.ok) {
      return payload as T;
    }

    if (isUnauthorizedStatus(response.status)) {
      emitAuthUnauthorized({ status: response.status, path });
    } else if (isForbiddenStatus(response.status)) {
      emitAuthForbidden({ status: response.status, path });
    } else if (response.status >= 500) {
      const method = (init?.method ?? "GET").toUpperCase();
      logApiFailure5xx({
        endpoint: path,
        status: response.status,
        method,
        payload_summary: summarizeRequestBodyForLogs(init)
      });
      const apiError = extractFirstApiError(payload);
      emitApiServerError({
        message: apiError?.message ?? "Sunucu hatasi. Lutfen daha sonra tekrar deneyin.",
        status: response.status
      });
    }

    const apiError = extractFirstApiError(payload);
    lastError = new ApiRequestError(
      apiError?.message ?? `API request failed: ${response.status}`,
      response.status,
      apiError ?? undefined
    );

    if (response.status !== 404) {
      throw lastError;
    }
  }

  if (DEMO_API_FALLBACK_ENABLED) {
    const mock = resolveDemoApiResponse(path, init);
    if (mock !== null) {
      return mock as T;
    }
  }

  throw lastError ?? new ApiRequestError("API request failed.", 500);
}
