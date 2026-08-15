import type { ApiResponse } from "../types/api";
import type {
  MeQrAraliklariResponse,
  MeQrAttendanceEvent,
  MeQrHareketleriResponse,
  MeQrInterval,
  MeQrIntervalAnomaly,
  MeQrScanResponse,
  ManagerQrAttendanceItem,
  ManagerQrAttendanceResponse,
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

function normalizeSube(raw: unknown): { id: number; ad: string } {
  const sube = toRecord(raw) ?? {};
  const id = readNumber(sube.id);
  if (id == null) {
    throw new ApiRequestError("QR sube yaniti gecersiz.", 500, { code: "INVALID_RESPONSE" });
  }
  return { id, ad: readString(sube.ad) ?? "" };
}

function normalizeInterval(raw: unknown): MeQrInterval {
  const row = toRecord(raw);
  if (!row) {
    throw new ApiRequestError("QR interval yaniti gecersiz.", 500, { code: "INVALID_RESPONSE" });
  }
  const entryEventId = readNumber(row.entry_event_id);
  const exitEventId = readNumber(row.exit_event_id);
  const entryAt = readString(row.entry_at);
  const exitAt = readString(row.exit_at);
  const entryLocalDate = readString(row.entry_local_date);
  const exitLocalDate = readString(row.exit_local_date);
  const durationSeconds = readNumber(row.duration_seconds);
  if (
    entryEventId == null ||
    exitEventId == null ||
    !entryAt ||
    !exitAt ||
    !entryLocalDate ||
    !exitLocalDate ||
    durationSeconds == null
  ) {
    throw new ApiRequestError("QR interval alanlari eksik.", 500, { code: "INVALID_RESPONSE" });
  }
  return {
    entry_event_id: entryEventId,
    exit_event_id: exitEventId,
    entry_at: entryAt,
    exit_at: exitAt,
    entry_local_date: entryLocalDate,
    exit_local_date: exitLocalDate,
    spans_local_midnight: Boolean(row.spans_local_midnight),
    duration_seconds: durationSeconds,
    sube: normalizeSube(row.sube)
  };
}

function normalizeAnomaly(raw: unknown): MeQrIntervalAnomaly {
  const row = toRecord(raw);
  if (!row) {
    throw new ApiRequestError("QR anomaly yaniti gecersiz.", 500, { code: "INVALID_RESPONSE" });
  }
  const type = readString(row.type);
  const reason = readString(row.reason) ?? type ?? "";
  const correctionHint = readString(row.correction_hint) ?? "GIRIS_CIKIS_DUZELTME";
  const occurredAt = readString(row.occurred_at) ?? "";
  const localDate = readString(row.local_date) ?? "";
  if (type === "BRANCH_MISMATCH") {
    const entryEventId = readNumber(row.entry_event_id);
    const exitEventId = readNumber(row.exit_event_id);
    if (entryEventId == null || exitEventId == null) {
      throw new ApiRequestError("BRANCH_MISMATCH alanlari eksik.", 500, {
        code: "INVALID_RESPONSE"
      });
    }
    return {
      type: "BRANCH_MISMATCH",
      reason,
      entry_event_id: entryEventId,
      exit_event_id: exitEventId,
      occurred_at: occurredAt,
      local_date: localDate,
      entry_sube: normalizeSube(row.entry_sube),
      exit_sube: normalizeSube(row.exit_sube),
      correction_hint: correctionHint
    };
  }
  if (type !== "MISSING_CIKIS" && type !== "MISSING_GIRIS") {
    throw new ApiRequestError("QR anomaly type gecersiz.", 500, { code: "INVALID_RESPONSE" });
  }
  const eventId = readNumber(row.event_id);
  if (eventId == null) {
    throw new ApiRequestError("QR anomaly event_id eksik.", 500, { code: "INVALID_RESPONSE" });
  }
  return {
    type,
    reason,
    event_id: eventId,
    event_type: readString(row.event_type) ?? "",
    occurred_at: occurredAt,
    local_date: localDate,
    sube: normalizeSube(row.sube),
    correction_hint: correctionHint
  };
}

function normalizeManagerQrAttendanceItem(raw: unknown): ManagerQrAttendanceItem {
  const row = toRecord(raw);
  if (!row) {
    throw new ApiRequestError("QR personel satiri gecersiz.", 500, { code: "INVALID_RESPONSE" });
  }

  const personelId = readNumber(row.personel_id);
  const subeId = readNumber(row.sube_id);
  const adSoyad = readString(row.ad_soyad);
  const dateFrom = readString(row.date_from);
  const dateTo = readString(row.date_to);
  const intervalCount = readNumber(row.interval_count);
  const matchedSeconds = readNumber(row.matched_seconds);
  const sourceEventCount = readNumber(row.source_event_count);
  if (
    personelId == null ||
    subeId == null ||
    !adSoyad ||
    !dateFrom ||
    !dateTo ||
    intervalCount == null ||
    matchedSeconds == null ||
    sourceEventCount == null ||
    typeof row.inside !== "boolean" ||
    typeof row.missing_entry !== "boolean" ||
    typeof row.missing_exit !== "boolean" ||
    typeof row.branch_mismatch !== "boolean" ||
    !Array.isArray(row.anomalies)
  ) {
    throw new ApiRequestError("QR personel satiri alanlari eksik.", 500, { code: "INVALID_RESPONSE" });
  }

  const lastMovementType =
    row.last_movement_type === null || row.last_movement_type === "GIRIS" || row.last_movement_type === "CIKIS"
      ? row.last_movement_type
      : null;

  return {
    personel_id: personelId,
    ad_soyad: adSoyad,
    sicil_no: row.sicil_no === null ? null : readString(row.sicil_no),
    sube_id: subeId,
    sube: readString(row.sube) ?? "",
    date_from: dateFrom,
    date_to: dateTo,
    first_entry: row.first_entry === null ? null : readString(row.first_entry),
    last_exit: row.last_exit === null ? null : readString(row.last_exit),
    last_movement: row.last_movement === null ? null : readString(row.last_movement),
    last_movement_type: lastMovementType,
    inside: row.inside,
    interval_count: intervalCount,
    missing_entry: row.missing_entry,
    missing_exit: row.missing_exit,
    branch_mismatch: row.branch_mismatch,
    anomalies: row.anomalies.filter((value): value is string => typeof value === "string"),
    matched_seconds: matchedSeconds,
    source_event_count: sourceEventCount
  };
}

export function normalizeManagerQrAttendanceResponse(response: ApiResponse<unknown>): ManagerQrAttendanceResponse {
  const data = toRecord(unwrapData(response, "/puantaj/qr-hareketleri yaniti gecersiz."));
  if (
    !data ||
    !readString(data.from) ||
    !readString(data.to) ||
    !Array.isArray(data.items) ||
    readNumber(data.total) == null ||
    readNumber(data.limit) == null ||
    readNumber(data.offset) == null ||
    typeof data.has_next !== "boolean" ||
    !readString(data.algorithm_version)
  ) {
    throw new ApiRequestError("/puantaj/qr-hareketleri yaniti gecersiz.", 500, {
      code: "INVALID_RESPONSE"
    });
  }

  return {
    from: readString(data.from) as string,
    to: readString(data.to) as string,
    items: data.items.map(normalizeManagerQrAttendanceItem),
    total: readNumber(data.total) as number,
    limit: readNumber(data.limit) as number,
    offset: readNumber(data.offset) as number,
    has_next: data.has_next,
    algorithm_version: readString(data.algorithm_version) as string
  };
}

export async function fetchMeQrAraliklari(params?: {
  from?: string;
  to?: string;
}): Promise<MeQrAraliklariResponse> {
  if (shouldPreferDemoApi()) {
    demoUnavailable();
  }
  const path = appendQueryParams(endpoints.me.qrAraliklari, {
    from: params?.from,
    to: params?.to
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const data = toRecord(unwrapData(response, "/me/qr-araliklari yaniti gecersiz."));
  if (!data) {
    throw new ApiRequestError("/me/qr-araliklari yaniti gecersiz.", 500, {
      code: "INVALID_RESPONSE"
    });
  }
  const summary = toRecord(data.summary) ?? {};
  const intervalsRaw = Array.isArray(data.intervals) ? data.intervals : [];
  const anomaliesRaw = Array.isArray(data.anomalies) ? data.anomalies : [];
  return {
    from: readString(data.from) ?? "",
    to: readString(data.to) ?? "",
    algorithm_version: readString(data.algorithm_version) ?? "QR_INTERVAL_V1",
    intervals: intervalsRaw.map((item) => normalizeInterval(item)),
    anomalies: anomaliesRaw.map((item) => normalizeAnomaly(item)),
    summary: {
      complete_interval_count: readNumber(summary.complete_interval_count) ?? 0,
      anomaly_count: readNumber(summary.anomaly_count) ?? 0,
      complete_duration_seconds: readNumber(summary.complete_duration_seconds) ?? 0
    },
    source_event_count: readNumber(data.source_event_count) ?? 0,
    source_max_event_id: readNumber(data.source_max_event_id)
  };
}

export async function fetchManagerQrAttendance(params?: {
  from?: string;
  to?: string;
  personel_id?: number;
  sube_id?: number;
  limit?: number;
  offset?: number;
}): Promise<ManagerQrAttendanceResponse> {
  const path = appendQueryParams(endpoints.puantaj.qrHareketleri, {
    from: params?.from,
    to: params?.to,
    personel_id: params?.personel_id,
    sube_id: params?.sube_id,
    limit: params?.limit,
    offset: params?.offset
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizeManagerQrAttendanceResponse(response);
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
