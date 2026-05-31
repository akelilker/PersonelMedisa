import type { ApiResponse } from "../types/api";
import type {
  PostSerbestZamanKullanimPayload,
  PostSerbestZamanOlusumPayload,
  SerbestZamanBakiye,
  SerbestZamanEvent,
  SerbestZamanEventTipi,
  SerbestZamanKullanimEvent
} from "../types/serbest-zaman";
import { SERBEST_ZAMAN_EVENT_TIPI_VALUES } from "../types/serbest-zaman";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function toOptionalNumber(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    if (!trimmed) {
      return undefined;
    }

    const parsed = Number.parseInt(trimmed, 10);
    return Number.isNaN(parsed) ? undefined : parsed;
  }

  return undefined;
}

function toNonNegativeNumber(value: unknown, fallback = 0): number {
  const parsed = toOptionalNumber(value);
  if (parsed === undefined || parsed < 0) {
    return fallback;
  }

  return parsed;
}

function toOptionalString(value: unknown): string | undefined {
  if (typeof value === "string") {
    const trimmed = value.trim();
    return trimmed ? trimmed : undefined;
  }

  return undefined;
}

function isSerbestZamanEventTipi(value: unknown): value is SerbestZamanEventTipi {
  return (
    typeof value === "string" &&
    (SERBEST_ZAMAN_EVENT_TIPI_VALUES as readonly string[]).includes(value)
  );
}

function isValidEventTarihi(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value.trim());
}

function normalizeSerbestZamanOlusumEvent(
  record: Record<string, unknown>
): SerbestZamanEvent {
  const personel_id = toOptionalNumber(record.personel_id);
  const kaynak_snapshot_id = toOptionalNumber(record.kaynak_snapshot_id);
  const kaynak_odeme_tercihi_id = toOptionalNumber(record.kaynak_odeme_tercihi_id);
  const event_tarihi = toOptionalString(record.event_tarihi);
  const son_kullanim_tarihi = toOptionalString(record.son_kullanim_tarihi);

  if (
    personel_id === undefined ||
    kaynak_snapshot_id === undefined ||
    kaynak_odeme_tercihi_id === undefined ||
    !event_tarihi ||
    !son_kullanim_tarihi
  ) {
    throw new ApiRequestError("Serbest zaman event yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  const id = toOptionalNumber(record.id);

  return {
    id,
    personel_id,
    kaynak_snapshot_id,
    kaynak_odeme_tercihi_id,
    event_tipi: "SERBEST_ZAMAN_OLUSUM",
    dakika: toNonNegativeNumber(record.dakika),
    event_tarihi,
    son_kullanim_tarihi,
    aciklama: toOptionalString(record.aciklama)
  };
}

function normalizeSerbestZamanKullanimEvent(
  record: Record<string, unknown>
): SerbestZamanKullanimEvent {
  const personel_id = toOptionalNumber(record.personel_id);
  const event_tarihi = toOptionalString(record.event_tarihi);

  if (personel_id === undefined || !event_tarihi) {
    throw new ApiRequestError("Serbest zaman event yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  const id = toOptionalNumber(record.id);

  return {
    id,
    personel_id,
    event_tipi: "SERBEST_ZAMAN_KULLANIM",
    dakika: toNonNegativeNumber(record.dakika),
    event_tarihi,
    aciklama: toOptionalString(record.aciklama)
  };
}

export function normalizeSerbestZamanEvent(data: unknown): SerbestZamanEvent {
  const record = toRecord(data);
  if (!record) {
    throw new ApiRequestError("Serbest zaman event yaniti beklenen formatta degil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  const event_tipi = record.event_tipi;

  if (!isSerbestZamanEventTipi(event_tipi)) {
    throw new ApiRequestError("Serbest zaman event yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  if (event_tipi === "SERBEST_ZAMAN_OLUSUM") {
    return normalizeSerbestZamanOlusumEvent(record);
  }

  if (event_tipi === "SERBEST_ZAMAN_KULLANIM") {
    return normalizeSerbestZamanKullanimEvent(record);
  }

  throw new ApiRequestError("Serbest zaman event yaniti desteklenmeyen event tipi iceriyor.", 400, {
    code: "INVALID_RESPONSE"
  });
}

export function normalizeSerbestZamanBakiye(data: unknown, fallbackPersonelId?: number): SerbestZamanBakiye {
  const record = toRecord(data) ?? {};
  const personel_id = toOptionalNumber(record.personel_id) ?? fallbackPersonelId;

  if (personel_id === undefined) {
    throw new ApiRequestError("Serbest zaman bakiye yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  return {
    personel_id,
    toplam_hak_dakika: toNonNegativeNumber(record.toplam_hak_dakika),
    kullanilan_dakika: toNonNegativeNumber(record.kullanilan_dakika),
    kalan_dakika: toNonNegativeNumber(record.kalan_dakika),
    suresi_dolan_dakika: toNonNegativeNumber(record.suresi_dolan_dakika),
    event_sayisi: toNonNegativeNumber(record.event_sayisi)
  };
}

function parsePositiveIntParam(value: number | string, field: string): number {
  const parsed =
    typeof value === "number" ? value : Number.parseInt(String(value).trim(), 10);

  if (!Number.isFinite(parsed) || parsed < 1) {
    throw new ApiRequestError(`${field} gecersiz.`, 400, { code: "INVALID_QUERY", field });
  }

  return parsed;
}

function throwFirstApiError(
  errors: ApiResponse<unknown>["errors"],
  fallbackMessage: string,
  fallbackStatus = 400
): never {
  const first = errors?.[0];
  const code = typeof first?.code === "string" ? first.code : "INVALID_REQUEST";
  const status =
    code === "NOT_FOUND"
      ? 404
      : code === "ALREADY_EXISTS" ||
          code === "INSUFFICIENT_BALANCE" ||
          code === "NO_ELIGIBLE_BALANCE"
        ? 409
        : fallbackStatus;

  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallbackMessage,
    status,
    { code }
  );
}

export async function fetchSerbestZamanEvents(
  personelId: number | string
): Promise<SerbestZamanEvent[]> {
  const personel_id = parsePositiveIntParam(personelId, "personel_id");
  const path = appendQueryParams(endpoints.serbestZaman.events, { personel_id });

  const response = await apiRequest<ApiResponse<unknown>>(path);

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Serbest zaman event listesi alinamadi.");
  }

  const record = toRecord(response.data);
  const items = record?.items;

  if (!Array.isArray(items)) {
    return [];
  }

  return items.map((item) => normalizeSerbestZamanEvent(item));
}

export async function fetchSerbestZamanBakiye(
  personelId: number | string,
  referansTarih?: string
): Promise<SerbestZamanBakiye> {
  const personel_id = parsePositiveIntParam(personelId, "personel_id");
  const path = appendQueryParams(endpoints.serbestZaman.bakiye, {
    personel_id,
    referans_tarih: referansTarih
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Serbest zaman bakiye alinamadi.");
  }

  return normalizeSerbestZamanBakiye(response.data, personel_id);
}

export async function postSerbestZamanOlusum(
  payload: PostSerbestZamanOlusumPayload
): Promise<SerbestZamanEvent> {
  const odeme_tercihi_id =
    payload.odeme_tercihi_id !== undefined
      ? parsePositiveIntParam(payload.odeme_tercihi_id, "odeme_tercihi_id")
      : undefined;
  const snapshot_id =
    payload.snapshot_id !== undefined
      ? parsePositiveIntParam(payload.snapshot_id, "snapshot_id")
      : undefined;

  if (odeme_tercihi_id === undefined && snapshot_id === undefined) {
    throw new ApiRequestError("odeme_tercihi_id veya snapshot_id zorunludur.", 400, {
      code: "INVALID_BODY"
    });
  }

  const response = await apiRequest<ApiResponse<unknown>>(endpoints.serbestZaman.olusum, {
    method: "POST",
    body: JSON.stringify({
      odeme_tercihi_id,
      snapshot_id
    })
  });

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Serbest zaman olusum eventi olusturulamadi.");
  }

  return normalizeSerbestZamanEvent(response.data);
}

export async function postSerbestZamanKullanim(
  payload: PostSerbestZamanKullanimPayload
): Promise<SerbestZamanKullanimEvent> {
  const personel_id = parsePositiveIntParam(payload.personel_id, "personel_id");
  const dakika = toOptionalNumber(payload.dakika);

  if (dakika === undefined || dakika <= 0) {
    throw new ApiRequestError("dakika pozitif olmalidir.", 400, { code: "INVALID_BODY" });
  }

  const event_tarihi = toOptionalString(payload.event_tarihi);
  if (!event_tarihi || !isValidEventTarihi(event_tarihi)) {
    throw new ApiRequestError("event_tarihi YYYY-MM-DD formatinda olmalidir.", 400, {
      code: "INVALID_BODY"
    });
  }

  const response = await apiRequest<ApiResponse<unknown>>(endpoints.serbestZaman.kullanim, {
    method: "POST",
    body: JSON.stringify({
      personel_id,
      dakika,
      event_tarihi: event_tarihi.trim().slice(0, 10),
      aciklama: toOptionalString(payload.aciklama)
    })
  });

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Serbest zaman kullanim eventi olusturulamadi.");
  }

  const event = normalizeSerbestZamanEvent(response.data);
  if (event.event_tipi !== "SERBEST_ZAMAN_KULLANIM") {
    throw new ApiRequestError("Serbest zaman event yaniti beklenen formatta degil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  return event;
}
