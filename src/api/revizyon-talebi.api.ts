import type { ApiResponse } from "../types/api";
import type {
  PostRevizyonTalebiPayload,
  RevizyonTalebi,
  RevizyonTalebiDurumu,
  RevizyonTalebiKararPayload,
  RevizyonTalebiListFilters,
  RevizyonTipi
} from "../types/revizyon-talebi";
import { REVIZYON_TALEBI_DURUMLARI, REVIZYON_TIPLERI } from "../types/revizyon-talebi";
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

function toOptionalString(value: unknown): string | undefined {
  if (typeof value === "string") {
    const trimmed = value.trim();
    return trimmed ? trimmed : undefined;
  }

  return undefined;
}

function toBoolean(value: unknown, fallback = false): boolean {
  if (typeof value === "boolean") {
    return value;
  }

  return fallback;
}

function toNullableScalar(value: unknown): string | number | boolean | null {
  if (value === null || value === undefined) {
    return null;
  }

  if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
    return value;
  }

  return null;
}

function isRevizyonTipi(value: unknown): value is RevizyonTipi {
  return typeof value === "string" && (REVIZYON_TIPLERI as readonly string[]).includes(value);
}

function isRevizyonTalebiDurumu(value: unknown): value is RevizyonTalebiDurumu {
  return typeof value === "string" && (REVIZYON_TALEBI_DURUMLARI as readonly string[]).includes(value);
}

function parsePositiveIntParam(value: number | string, field: string): number {
  const parsed =
    typeof value === "number" ? value : Number.parseInt(String(value).trim(), 10);

  if (!Number.isFinite(parsed) || parsed < 1) {
    throw new ApiRequestError(`${field} gecersiz.`, 400, { code: "INVALID_BODY", field });
  }

  return parsed;
}

function resolveRevizyonErrorStatus(code: string): number {
  switch (code) {
    case "NOT_FOUND":
    case "TARGET_NOT_FOUND":
      return 404;
    case "UNAUTHORIZED_REVISION_REQUEST":
    case "UNAUTHORIZED_REVISION_APPROVAL":
    case "REVISION_SCOPE_DENIED":
    case "REVISION_OWNER_DENIED":
    case "FINANCE_EFFECT_ACCESS_DENIED":
    case "FORBIDDEN":
      return 403;
    case "PERIOD_NOT_CLOSED":
    case "PERIOD_LOCKED":
    case "REVISION_ALREADY_EXISTS":
    case "ALREADY_EXISTS":
    case "INVALID_STATE_TRANSITION":
    case "STATE_CONFLICT":
    case "SNAPSHOT_IMMUTABLE":
      return 409;
    case "VALIDATION_ERROR":
      return 422;
    case "INVALID_BODY":
      return 400;
    default:
      return 400;
  }
}

function throwFirstApiError(
  errors: ApiResponse<unknown>["errors"],
  fallbackMessage: string
): never {
  const first = errors?.[0];
  const code = typeof first?.code === "string" ? first.code : "INVALID_REQUEST";
  const status = resolveRevizyonErrorStatus(code);

  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallbackMessage,
    status,
    { code }
  );
}

export function normalizeRevizyonTalebi(raw: unknown): RevizyonTalebi {
  const record = toRecord(raw);
  if (!record) {
    throw new ApiRequestError("Revizyon talebi yaniti gecersiz.", 400, { code: "INVALID_BODY" });
  }

  const id = toOptionalNumber(record.id);
  const personel_id = toOptionalNumber(record.personel_id);
  const hafta_baslangic = toOptionalString(record.hafta_baslangic);
  const hafta_bitis = toOptionalString(record.hafta_bitis);
  const etkilenen_tarih = toOptionalString(record.etkilenen_tarih);
  const kaynak_tipi = toOptionalString(record.kaynak_tipi);
  const kaynak_id = toOptionalNumber(record.kaynak_id);
  const revizyon_tipi = record.revizyon_tipi;
  const gerekce = toOptionalString(record.gerekce);
  const talep_eden_kullanici_id = toOptionalNumber(record.talep_eden_kullanici_id);
  const talep_zamani = toOptionalString(record.talep_zamani);
  const durum = record.durum;

  if (
    id === undefined ||
    personel_id === undefined ||
    !hafta_baslangic ||
    !hafta_bitis ||
    !etkilenen_tarih ||
    !kaynak_tipi ||
    kaynak_id === undefined ||
    !isRevizyonTipi(revizyon_tipi) ||
    !gerekce ||
    talep_eden_kullanici_id === undefined ||
    !talep_zamani ||
    !isRevizyonTalebiDurumu(durum)
  ) {
    throw new ApiRequestError("Revizyon talebi yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_BODY"
    });
  }

  const karar_veren_kullanici_id = toOptionalNumber(record.karar_veren_kullanici_id);
  const correction_event_id = toOptionalNumber(record.correction_event_id);

  return {
    id,
    personel_id,
    hafta_baslangic,
    hafta_bitis,
    etkilenen_tarih,
    kaynak_tipi,
    kaynak_id,
    revizyon_tipi,
    onceki_deger: toNullableScalar(record.onceki_deger),
    talep_edilen_deger: toNullableScalar(record.talep_edilen_deger),
    gerekce,
    talep_eden_kullanici_id,
    talep_zamani,
    durum,
    karar_veren_kullanici_id: karar_veren_kullanici_id ?? null,
    karar_zamani: toOptionalString(record.karar_zamani) ?? null,
    karar_notu: toOptionalString(record.karar_notu) ?? null,
    bordro_etki_var_mi: toBoolean(record.bordro_etki_var_mi, false),
    bordro_etki_notu: toOptionalString(record.bordro_etki_notu) ?? null,
    correction_event_id: correction_event_id ?? null
  };
}

function parseRevizyonTalebiResponse(
  response: ApiResponse<unknown>,
  fallbackMessage: string
): RevizyonTalebi {
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, fallbackMessage);
  }

  return normalizeRevizyonTalebi(response.data);
}

export async function fetchRevizyonTalepleri(
  filters?: RevizyonTalebiListFilters
): Promise<RevizyonTalebi[]> {
  const path = appendQueryParams(endpoints.revizyonTalepleri.list, {
    personel_id: filters?.personel_id,
    durum: filters?.durum,
    hafta_baslangic: filters?.hafta_baslangic,
    hafta_bitis: filters?.hafta_bitis
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Revizyon talepleri alinamadi.");
  }

  const record = toRecord(response.data);
  const items = record?.items;

  if (!Array.isArray(items)) {
    if (Array.isArray(response.data)) {
      return response.data.map((item) => normalizeRevizyonTalebi(item));
    }

    return [];
  }

  return items.map((item) => normalizeRevizyonTalebi(item));
}

export async function fetchRevizyonTalebiDetail(id: number | string): Promise<RevizyonTalebi> {
  const talepId = parsePositiveIntParam(id, "id");
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.revizyonTalepleri.detail(talepId));

  return parseRevizyonTalebiResponse(response, "Revizyon talebi bulunamadi.");
}

export async function createRevizyonTalebi(
  payload: PostRevizyonTalebiPayload
): Promise<RevizyonTalebi> {
  const personel_id = parsePositiveIntParam(payload.personel_id, "personel_id");
  const kaynak_id = parsePositiveIntParam(payload.kaynak_id, "kaynak_id");
  const hafta_baslangic = toOptionalString(payload.hafta_baslangic);
  const hafta_bitis = toOptionalString(payload.hafta_bitis);
  const etkilenen_tarih = toOptionalString(payload.etkilenen_tarih);
  const kaynak_tipi = toOptionalString(payload.kaynak_tipi);
  const gerekce = toOptionalString(payload.gerekce);

  if (
    !hafta_baslangic ||
    !hafta_bitis ||
    !etkilenen_tarih ||
    !kaynak_tipi ||
    !gerekce ||
    !isRevizyonTipi(payload.revizyon_tipi)
  ) {
    throw new ApiRequestError("Revizyon talebi payload gecersiz.", 400, { code: "INVALID_BODY" });
  }

  const response = await apiRequest<ApiResponse<unknown>>(endpoints.revizyonTalepleri.create, {
    method: "POST",
    body: JSON.stringify({
      personel_id,
      hafta_baslangic,
      hafta_bitis,
      etkilenen_tarih,
      kaynak_tipi,
      kaynak_id,
      revizyon_tipi: payload.revizyon_tipi,
      onceki_deger: payload.onceki_deger,
      talep_edilen_deger: payload.talep_edilen_deger,
      gerekce,
      bordro_etki_var_mi: payload.bordro_etki_var_mi ?? false,
      bordro_etki_notu: payload.bordro_etki_notu ?? null
    })
  });

  return parseRevizyonTalebiResponse(response, "Revizyon talebi olusturulamadi.");
}

export async function submitRevizyonTalebi(id: number | string): Promise<RevizyonTalebi> {
  const talepId = parsePositiveIntParam(id, "id");
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.revizyonTalepleri.submit(talepId), {
    method: "POST",
    body: JSON.stringify({})
  });

  return parseRevizyonTalebiResponse(response, "Revizyon talebi gonderilemedi.");
}

export async function approveRevizyonTalebi(
  id: number | string,
  payload: RevizyonTalebiKararPayload
): Promise<RevizyonTalebi> {
  const talepId = parsePositiveIntParam(id, "id");
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.revizyonTalepleri.approve(talepId), {
    method: "POST",
    body: JSON.stringify({
      karar_notu: payload.karar_notu ?? null
    })
  });

  return parseRevizyonTalebiResponse(response, "Revizyon talebi onaylanamadi.");
}

export async function rejectRevizyonTalebi(
  id: number | string,
  payload: RevizyonTalebiKararPayload
): Promise<RevizyonTalebi> {
  const talepId = parsePositiveIntParam(id, "id");
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.revizyonTalepleri.reject(talepId), {
    method: "POST",
    body: JSON.stringify({
      karar_notu: payload.karar_notu ?? null
    })
  });

  return parseRevizyonTalebiResponse(response, "Revizyon talebi reddedilemedi.");
}

export async function cancelRevizyonTalebi(
  id: number | string,
  payload?: RevizyonTalebiKararPayload
): Promise<RevizyonTalebi> {
  const talepId = parsePositiveIntParam(id, "id");
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.revizyonTalepleri.cancel(talepId), {
    method: "POST",
    body: JSON.stringify({
      karar_notu: payload?.karar_notu ?? null
    })
  });

  return parseRevizyonTalebiResponse(response, "Revizyon talebi iptal edilemedi.");
}
