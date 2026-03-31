import { emitAuthForbidden, emitAuthUnauthorized } from "../lib/storage/auth-events";
import { getStoredAuthToken } from "../lib/storage/auth-session";
import type { ApiError, ApiResponse } from "../types/api";
import { resolveDemoApiResponse } from "./mock-demo";

const ENV_API_BASE_URL = (
  import.meta as ImportMeta & { env?: Record<string, string | undefined> }
).env?.VITE_API_BASE_URL;
const DEMO_API_FALLBACK_ENABLED =
  (
    (import.meta as ImportMeta & { env?: Record<string, string | undefined> }).env
      ?.VITE_DEMO_API_FALLBACK ?? "true"
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

function resolveApiBaseCandidates() {
  const candidates: string[] = [];
  const envBase = normalizeBase(ENV_API_BASE_URL ?? "");
  const pathname = readWindowPathname();
  const isSubfolderDeployment = pathname.startsWith("/personelmedisa");

  if (envBase) {
    candidates.push(envBase);
  }

  if (isSubfolderDeployment) {
    candidates.push("/personelmedisa/api");
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
    const token = getStoredAuthToken();
    if (token) {
      headers.set("Authorization", `Bearer ${token}`);
    }
  }

  return headers;
}

export async function apiRequest<T>(path: string, init?: RequestInit): Promise<T> {
  const baseCandidates = resolveApiBaseCandidates();
  const requestHeaders = buildRequestHeaders(path, init);

  let lastError: ApiRequestError | null = null;

  for (const base of baseCandidates) {
    const response = await fetch(buildApiUrl(path, base), {
      ...init,
      headers: requestHeaders
    });

    const payload = await parseResponseBody(response);

    if (response.ok) {
      return payload as T;
    }

    if (isUnauthorizedStatus(response.status)) {
      emitAuthUnauthorized({ status: response.status, path });
    } else if (isForbiddenStatus(response.status)) {
      emitAuthForbidden({ status: response.status, path });
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
