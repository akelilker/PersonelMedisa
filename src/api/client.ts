import { emitAuthForbidden, emitAuthUnauthorized } from "../lib/storage/auth-events";
import { getStoredAuthToken } from "../lib/storage/auth-session";
import type { ApiError, ApiResponse } from "../types/api";

const API_BASE_URL = "/api";

export function buildApiUrl(path: string) {
  return `${API_BASE_URL}${path.startsWith("/") ? path : `/${path}`}`;
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
  const response = await fetch(buildApiUrl(path), {
    ...init,
    headers: buildRequestHeaders(path, init)
  });

  const payload = await parseResponseBody(response);

  if (!response.ok) {
    if (isUnauthorizedStatus(response.status)) {
      emitAuthUnauthorized({ status: response.status, path });
    } else if (isForbiddenStatus(response.status)) {
      emitAuthForbidden({ status: response.status, path });
    }

    const apiError = extractFirstApiError(payload);
    throw new ApiRequestError(
      apiError?.message ?? `API request failed: ${response.status}`,
      response.status,
      apiError ?? undefined
    );
  }

  return payload as T;
}
