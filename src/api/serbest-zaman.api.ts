import type { ApiResponse } from "../types/api";
import type {
  PostSerbestZamanDuzeltmePayload,
  PostSerbestZamanIptalPayload,
  PostSerbestZamanKullanimPayload,
  PostSerbestZamanOlusumPayload,
  SerbestZamanBakiye,
  SerbestZamanDuzeltmeEvent,
  SerbestZamanEvent,
  SerbestZamanEventTipi,
  SerbestZamanHedefEventTipi,
  SerbestZamanIptalEvent,
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

function isHedefEventTipi(value: unknown): value is SerbestZamanHedefEventTipi {
  return value === "SERBEST_ZAMAN_OLUSUM" || value === "SERBEST_ZAMAN_KULLANIM";
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
    aciklama: toOptionalString(record.aciklama),
    ...toDonemMeta(record)
  };
}

function toDonemMeta(record: Record<string, unknown>): {
  donem_yil?: number | null;
  donem_ay?: number | null;
  donem_kilitli_miydi?: boolean;
} {
  const donem_yil =
    record.donem_yil === null || record.donem_yil === undefined
      ? record.donem_yil === null
        ? null
        : undefined
      : toOptionalNumber(record.donem_yil) ?? null;
  const donem_ay =
    record.donem_ay === null || record.donem_ay === undefined
      ? record.donem_ay === null
        ? null
        : undefined
      : toOptionalNumber(record.donem_ay) ?? null;
  const donem_kilitli_miydi =
    typeof record.donem_kilitli_miydi === "boolean"
      ? record.donem_kilitli_miydi
      : record.donem_kilitli_miydi === 1 || record.donem_kilitli_miydi === "1"
        ? true
        : record.donem_kilitli_miydi === 0 || record.donem_kilitli_miydi === "0"
          ? false
          : undefined;

  return { donem_yil, donem_ay, donem_kilitli_miydi };
}

function requireIslemAnahtari(value: unknown): string {
  const anahtar = toOptionalString(value);
  if (!anahtar) {
    throw new ApiRequestError("Serbest zaman event yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  return anahtar;
}

function normalizeSerbestZamanIptalEvent(record: Record<string, unknown>): SerbestZamanIptalEvent {
  const personel_id = toOptionalNumber(record.personel_id);
  const hedef_event_id = toOptionalNumber(record.hedef_event_id);
  const event_tarihi = toOptionalString(record.event_tarihi);
  const hedef_event_tipi = record.hedef_event_tipi;
  const islem_anahtari = requireIslemAnahtari(record.islem_anahtari);

  if (
    personel_id === undefined ||
    hedef_event_id === undefined ||
    !event_tarihi ||
    !isHedefEventTipi(hedef_event_tipi)
  ) {
    throw new ApiRequestError("Serbest zaman event yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  const id = toOptionalNumber(record.id);

  return {
    id,
    personel_id,
    event_tipi: "SERBEST_ZAMAN_IPTAL",
    hedef_event_id,
    hedef_event_tipi,
    event_tarihi,
    islem_anahtari,
    aciklama: toOptionalString(record.aciklama),
    ...toDonemMeta(record)
  };
}

function normalizeSerbestZamanDuzeltmeEvent(
  record: Record<string, unknown>
): SerbestZamanDuzeltmeEvent {
  const personel_id = toOptionalNumber(record.personel_id);
  const hedef_event_id = toOptionalNumber(record.hedef_event_id);
  const event_tarihi = toOptionalString(record.event_tarihi);
  const hedef_event_tipi = record.hedef_event_tipi;
  const islem_anahtari = requireIslemAnahtari(record.islem_anahtari);
  const aciklama = toOptionalString(record.aciklama);

  if (
    personel_id === undefined ||
    hedef_event_id === undefined ||
    !event_tarihi ||
    !isHedefEventTipi(hedef_event_tipi) ||
    !aciklama
  ) {
    throw new ApiRequestError("Serbest zaman event yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  const id = toOptionalNumber(record.id);

  return {
    id,
    personel_id,
    event_tipi: "SERBEST_ZAMAN_DUZELTME",
    hedef_event_id,
    hedef_event_tipi,
    yeni_dakika: toNonNegativeNumber(record.yeni_dakika),
    event_tarihi,
    islem_anahtari,
    aciklama,
    ...toDonemMeta(record)
  };
}

function normalizeSerbestZamanKullanimEvent(
  record: Record<string, unknown>
): SerbestZamanKullanimEvent {
  const personel_id = toOptionalNumber(record.personel_id);
  const event_tarihi = toOptionalString(record.event_tarihi);
  const islem_anahtari = requireIslemAnahtari(record.islem_anahtari);

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
    islem_anahtari,
    aciklama: toOptionalString(record.aciklama),
    ...toDonemMeta(record)
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

  if (event_tipi === "SERBEST_ZAMAN_IPTAL") {
    return normalizeSerbestZamanIptalEvent(record);
  }

  if (event_tipi === "SERBEST_ZAMAN_DUZELTME") {
    return normalizeSerbestZamanDuzeltmeEvent(record);
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
    code === "NOT_FOUND" || code === "TARGET_NOT_FOUND"
      ? 404
      : code === "ZERO_DAKIKA"
        ? 422
        : code === "ALREADY_EXISTS" ||
            code === "INSUFFICIENT_BALANCE" ||
            code === "NO_ELIGIBLE_BALANCE" ||
            code === "ALREADY_CANCELLED" ||
            code === "TARGET_ALREADY_CANCELLED" ||
            code === "NOT_ELIGIBLE" ||
            code === "NOT_PERSISTED" ||
            code === "IDEMPOTENCY_CONFLICT" ||
            code === "TARGET_PERSONEL_MISMATCH" ||
            code === "UNSUPPORTED_TARGET_EVENT"
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

  const islem_anahtari = toOptionalString(payload.islem_anahtari);
  if (!islem_anahtari) {
    throw new ApiRequestError("islem_anahtari zorunludur.", 400, { code: "INVALID_BODY" });
  }

  const response = await apiRequest<ApiResponse<unknown>>(endpoints.serbestZaman.kullanim, {
    method: "POST",
    body: JSON.stringify({
      personel_id,
      dakika,
      event_tarihi: event_tarihi.trim().slice(0, 10),
      islem_anahtari,
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

export async function postSerbestZamanIptal(
  payload: PostSerbestZamanIptalPayload
): Promise<SerbestZamanIptalEvent> {
  const personel_id = parsePositiveIntParam(payload.personel_id, "personel_id");
  const hedef_event_id = parsePositiveIntParam(payload.hedef_event_id, "hedef_event_id");

  if (!isHedefEventTipi(payload.hedef_event_tipi)) {
    throw new ApiRequestError("hedef_event_tipi gecersiz.", 400, { code: "INVALID_BODY" });
  }

  const event_tarihi = toOptionalString(payload.event_tarihi);
  if (!event_tarihi || !isValidEventTarihi(event_tarihi)) {
    throw new ApiRequestError("event_tarihi YYYY-MM-DD formatinda olmalidir.", 400, {
      code: "INVALID_BODY"
    });
  }

  const islem_anahtari = toOptionalString(payload.islem_anahtari);
  if (!islem_anahtari) {
    throw new ApiRequestError("islem_anahtari zorunludur.", 400, { code: "INVALID_BODY" });
  }

  const response = await apiRequest<ApiResponse<unknown>>(endpoints.serbestZaman.iptal, {
    method: "POST",
    body: JSON.stringify({
      personel_id,
      hedef_event_id,
      hedef_event_tipi: payload.hedef_event_tipi,
      event_tarihi: event_tarihi.trim().slice(0, 10),
      islem_anahtari,
      aciklama: toOptionalString(payload.aciklama)
    })
  });

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Serbest zaman iptal eventi olusturulamadi.");
  }

  const event = normalizeSerbestZamanEvent(response.data);
  if (event.event_tipi !== "SERBEST_ZAMAN_IPTAL") {
    throw new ApiRequestError("Serbest zaman event yaniti beklenen formatta degil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  return event;
}

export async function postSerbestZamanDuzeltme(
  payload: PostSerbestZamanDuzeltmePayload
): Promise<SerbestZamanDuzeltmeEvent> {
  const personel_id = parsePositiveIntParam(payload.personel_id, "personel_id");
  const hedef_event_id = parsePositiveIntParam(payload.hedef_event_id, "hedef_event_id");
  const yeni_dakika = toOptionalNumber(payload.yeni_dakika);

  if (!isHedefEventTipi(payload.hedef_event_tipi)) {
    throw new ApiRequestError("hedef_event_tipi gecersiz.", 400, { code: "INVALID_BODY" });
  }

  if (yeni_dakika === undefined || yeni_dakika <= 0) {
    throw new ApiRequestError("yeni_dakika pozitif olmalidir.", 400, { code: "INVALID_BODY" });
  }

  const event_tarihi = toOptionalString(payload.event_tarihi);
  if (!event_tarihi || !isValidEventTarihi(event_tarihi)) {
    throw new ApiRequestError("event_tarihi YYYY-MM-DD formatinda olmalidir.", 400, {
      code: "INVALID_BODY"
    });
  }

  const islem_anahtari = toOptionalString(payload.islem_anahtari);
  if (!islem_anahtari) {
    throw new ApiRequestError("islem_anahtari zorunludur.", 400, { code: "INVALID_BODY" });
  }

  const aciklama = toOptionalString(payload.aciklama);
  if (!aciklama) {
    throw new ApiRequestError("aciklama zorunludur.", 400, { code: "INVALID_BODY" });
  }

  const response = await apiRequest<ApiResponse<unknown>>(endpoints.serbestZaman.duzeltme, {
    method: "POST",
    body: JSON.stringify({
      personel_id,
      hedef_event_id,
      hedef_event_tipi: payload.hedef_event_tipi,
      yeni_dakika,
      event_tarihi: event_tarihi.trim().slice(0, 10),
      islem_anahtari,
      aciklama
    })
  });

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Serbest zaman duzeltme eventi olusturulamadi.");
  }

  const event = normalizeSerbestZamanEvent(response.data);
  if (event.event_tipi !== "SERBEST_ZAMAN_DUZELTME") {
    throw new ApiRequestError("Serbest zaman event yaniti beklenen formatta degil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  return event;
}
