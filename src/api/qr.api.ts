import type { ApiResponse } from "../types/api";
import type {
  MeQrAttendanceEvent,
  MeQrHareketleriResponse,
  MeQrScanResponse,
  QrEventType,
  QrKioskTokenResponse
} from "../types/self-service";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest, shouldPreferDemoApi } from "./api-client";
import { endpoints } from "./endpoints";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }
  return value as Record<string, unknown>;
}

function readString(value: unknown): string | null {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return trimmed ? trimmed : null;
}

function readNumber(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }
  return null;
}

function unwrapData(response: ApiResponse<unknown>, fallback: string): unknown {
  if (response && typeof response === "object" && "data" in response) {
    return response.data;
  }
  throw new ApiRequestError(fallback, 500, { code: "INVALID_RESPONSE" });
}

function demoUnavailable(): never {
  throw new ApiRequestError("Demo ortaminda QR kullanilamaz.", 403, {
    code: "SELF_SERVICE_BINDING_REQUIRED"
  });
}

function normalizeEvent(raw: unknown): MeQrAttendanceEvent {
  const row = toRecord(raw);
  if (!row) {
    throw new ApiRequestError("QR event yaniti gecersiz.", 500, { code: "INVALID_RESPONSE" });
  }
  const sube = toRecord(row.sube) ?? {};
  const eventType = readString(row.event_type);
  if (eventType !== "GIRIS" && eventType !== "CIKIS") {
    throw new ApiRequestError("QR event_type gecersiz.", 500, { code: "INVALID_RESPONSE" });
  }
  const id = readNumber(row.id);
  const occurredAt = readString(row.occurred_at);
  const subeId = readNumber(sube.id);
  if (id == null || !occurredAt || subeId == null) {
    throw new ApiRequestError("QR event alanlari eksik.", 500, { code: "INVALID_RESPONSE" });
  }
  return {
    id,
    event_type: eventType,
    occurred_at: occurredAt,
    sube: {
      id: subeId,
      ad: readString(sube.ad) ?? ""
    }
  };
}

export function createQrRequestNonce(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  const bytes = new Uint8Array(16);
  if (typeof crypto !== "undefined" && typeof crypto.getRandomValues === "function") {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i += 1) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export async function postMeQrScan(input: {
  token: string;
  event_type: QrEventType;
  request_nonce: string;
}): Promise<MeQrScanResponse> {
  if (shouldPreferDemoApi()) {
    demoUnavailable();
  }
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.me.qrScan, {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({
      token: input.token,
      event_type: input.event_type,
      request_nonce: input.request_nonce
    })
  });
  const data = toRecord(unwrapData(response, "/me/qr-scan yaniti gecersiz."));
  if (!data) {
    throw new ApiRequestError("/me/qr-scan yaniti gecersiz.", 500, { code: "INVALID_RESPONSE" });
  }
  return {
    event: normalizeEvent(data.event),
    idempotent: Boolean(data.idempotent)
  };
}

export async function fetchMeQrHareketleri(params?: {
  from?: string;
  to?: string;
}): Promise<MeQrHareketleriResponse> {
  if (shouldPreferDemoApi()) {
    demoUnavailable();
  }
  const path = appendQueryParams(endpoints.me.qrHareketleri, {
    from: params?.from,
    to: params?.to
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const data = toRecord(unwrapData(response, "/me/qr-hareketleri yaniti gecersiz."));
  if (!data) {
    throw new ApiRequestError("/me/qr-hareketleri yaniti gecersiz.", 500, {
      code: "INVALID_RESPONSE"
    });
  }
  const itemsRaw = Array.isArray(data.items) ? data.items : [];
  return {
    from: readString(data.from) ?? "",
    to: readString(data.to) ?? "",
    items: itemsRaw.map((item) => normalizeEvent(item))
  };
}

export async function fetchQrKioskToken(): Promise<QrKioskTokenResponse> {
  if (shouldPreferDemoApi()) {
    demoUnavailable();
  }
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.qrKiosk.token);
  const data = toRecord(unwrapData(response, "/qr-kiosk/token yaniti gecersiz."));
  if (!data) {
    throw new ApiRequestError("/qr-kiosk/token yaniti gecersiz.", 500, {
      code: "INVALID_RESPONSE"
    });
  }
  const sube = toRecord(data.sube) ?? {};
  const token = readString(data.token);
  const issuedAt = readNumber(data.issued_at);
  const expiresAt = readNumber(data.expires_at);
  const ttl = readNumber(data.ttl_seconds);
  const subeId = readNumber(sube.id);
  if (!token || issuedAt == null || expiresAt == null || ttl == null || subeId == null) {
    throw new ApiRequestError("/qr-kiosk/token alanlari eksik.", 500, {
      code: "INVALID_RESPONSE"
    });
  }
  return {
    token,
    issued_at: issuedAt,
    expires_at: expiresAt,
    ttl_seconds: ttl,
    sube: {
      id: subeId,
      ad: readString(sube.ad) ?? ""
    }
  };
}
