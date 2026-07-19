import type { ApiResponse } from "../types/api";
import type {
  RevizyonCorrectionEvent,
  RevizyonCorrectionHataKodu,
  RevizyonCorrectionIptalPayload,
  RevizyonCorrectionListFilters,
  RevizyonCorrectionTipi
} from "../types/revizyon-correction";
import { REVIZYON_CORRECTION_TIPLERI } from "../types/revizyon-correction";
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

function toNullableScalar(value: unknown): string | number | boolean | null | Record<string, unknown> {
  if (value === null || value === undefined) {
    return null;
  }

  if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
    return value;
  }

  if (typeof value === "object" && !Array.isArray(value)) {
    return value as Record<string, unknown>;
  }

  return null;
}

function isRevizyonCorrectionTipi(value: unknown): value is RevizyonCorrectionTipi {
  return (
    typeof value === "string" &&
    (REVIZYON_CORRECTION_TIPLERI as readonly string[]).includes(value)
  );
}

function parsePositiveIntParam(value: number | string, field: string): number {
  const parsed =
    typeof value === "number" ? value : Number.parseInt(String(value).trim(), 10);

  if (!Number.isFinite(parsed) || parsed < 1) {
    throw new ApiRequestError(`${field} gecersiz.`, 400, { code: "INVALID_CORRECTION_PAYLOAD", field });
  }

  return parsed;
}

function resolveCorrectionErrorStatus(code: string): number {
  switch (code) {
    case "CORRECTION_NOT_FOUND":
    case "CORRECTION_TARGET_NOT_FOUND":
      return 404;
    case "CORRECTION_SCOPE_DENIED":
    case "CORRECTION_FINANCE_SCOPE_DENIED":
      return 403;
    case "CORRECTION_ALREADY_EXISTS":
    case "CORRECTION_NOT_ALLOWED_FOR_STATE":
    case "CORRECTION_IMMUTABLE_SNAPSHOT":
    case "CORRECTION_RECOMPUTE_REQUIRED":
      return 409;
    case "INVALID_CORRECTION_PAYLOAD":
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
  const code = typeof first?.code === "string" ? first.code : "INVALID_CORRECTION_PAYLOAD";
  const status = resolveCorrectionErrorStatus(code);

  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallbackMessage,
    status,
    { code: code as RevizyonCorrectionHataKodu }
  );
}

export function normalizeRevizyonCorrection(raw: unknown): RevizyonCorrectionEvent {
  const record = toRecord(raw);
  if (!record) {
    throw new ApiRequestError("Revizyon correction yaniti gecersiz.", 400, {
      code: "INVALID_CORRECTION_PAYLOAD"
    });
  }

  const id = toOptionalNumber(record.id);
  const revizyon_talebi_id = toOptionalNumber(record.revizyon_talebi_id);
  const personel_id = toOptionalNumber(record.personel_id);
  const hafta_baslangic = toOptionalString(record.hafta_baslangic);
  const hafta_bitis = toOptionalString(record.hafta_bitis);
  const etkilenen_tarih = toOptionalString(record.etkilenen_tarih);
  const kaynak_tipi = toOptionalString(record.kaynak_tipi);
  const kaynak_id = toOptionalNumber(record.kaynak_id);
  const correction_tipi = record.correction_tipi;
  const olusturan_kullanici_id = toOptionalNumber(record.olusturan_kullanici_id);
  const olusturma_zamani = toOptionalString(record.olusturma_zamani);
  const audit_ref = toOptionalString(record.audit_ref);

  if (
    id === undefined ||
    revizyon_talebi_id === undefined ||
    personel_id === undefined ||
    !hafta_baslangic ||
    !hafta_bitis ||
    !etkilenen_tarih ||
    !kaynak_tipi ||
    kaynak_id === undefined ||
    !isRevizyonCorrectionTipi(correction_tipi) ||
    olusturan_kullanici_id === undefined ||
    !olusturma_zamani ||
    !audit_ref
  ) {
    throw new ApiRequestError("Revizyon correction yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_CORRECTION_PAYLOAD"
    });
  }

  const iptal_eden_kullanici_id = toOptionalNumber(record.iptal_eden_kullanici_id);

  return {
    id,
    revizyon_talebi_id,
    personel_id,
    personel_ad_soyad: toOptionalString(record.personel_ad_soyad) ?? null,
    sicil_no: toOptionalString(record.sicil_no) ?? null,
    sube_id: toOptionalNumber(record.sube_id) ?? null,
    sube_adi: toOptionalString(record.sube_adi) ?? null,
    departman_id: toOptionalNumber(record.departman_id) ?? null,
    departman_adi: toOptionalString(record.departman_adi) ?? null,
    hafta_baslangic,
    hafta_bitis,
    etkilenen_tarih,
    kaynak_tipi,
    kaynak_id,
    correction_tipi,
    onceki_deger: toNullableScalar(record.onceki_deger),
    yeni_deger: toNullableScalar(record.yeni_deger),
    delta_dakika: toOptionalNumber(record.delta_dakika) ?? 0,
    delta_gun: toOptionalNumber(record.delta_gun) ?? 0,
    bordro_etki_var_mi: toBoolean(record.bordro_etki_var_mi, false),
    bordro_etki_tipi: toOptionalString(record.bordro_etki_tipi) ?? null,
    aciklama: toOptionalString(record.aciklama) ?? null,
    olusturan_kullanici_id,
    olusturma_zamani,
    iptal_edildi_mi: toBoolean(record.iptal_edildi_mi, false),
    iptal_zamani: toOptionalString(record.iptal_zamani) ?? null,
    iptal_eden_kullanici_id: iptal_eden_kullanici_id ?? null,
    audit_ref,
    snapshot_ref: toOptionalString(record.snapshot_ref) ?? null
  };
}

function parseRevizyonCorrectionResponse(
  response: ApiResponse<unknown>,
  fallbackMessage: string
): RevizyonCorrectionEvent {
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, fallbackMessage);
  }

  return normalizeRevizyonCorrection(response.data);
}

export async function fetchRevizyonCorrections(
  filters?: RevizyonCorrectionListFilters
): Promise<RevizyonCorrectionEvent[]> {
  const path = appendQueryParams(endpoints.revizyonCorrections.list, {
    revizyon_talebi_id: filters?.revizyon_talebi_id,
    personel_id: filters?.personel_id,
    hafta_baslangic: filters?.hafta_baslangic,
    hafta_bitis: filters?.hafta_bitis
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Revizyon correction listesi alinamadi.");
  }

  const record = toRecord(response.data);
  const items = record?.items;

  if (!Array.isArray(items)) {
    if (Array.isArray(response.data)) {
      return response.data.map((item) => normalizeRevizyonCorrection(item));
    }

    return [];
  }

  return items.map((item) => normalizeRevizyonCorrection(item));
}

export async function fetchRevizyonCorrectionDetail(
  id: number | string
): Promise<RevizyonCorrectionEvent> {
  const correctionId = parsePositiveIntParam(id, "id");
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.revizyonCorrections.detail(correctionId)
  );

  return parseRevizyonCorrectionResponse(response, "Revizyon correction bulunamadi.");
}

export async function produceRevizyonCorrection(
  talepId: number | string
): Promise<RevizyonCorrectionEvent> {
  const parsedTalepId = parsePositiveIntParam(talepId, "talepId");
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.revizyonCorrections.produce(parsedTalepId),
    {
      method: "POST",
      body: JSON.stringify({})
    }
  );

  return parseRevizyonCorrectionResponse(response, "Revizyon correction uretilemedi.");
}

export async function cancelRevizyonCorrection(
  id: number | string,
  payload?: RevizyonCorrectionIptalPayload
): Promise<RevizyonCorrectionEvent> {
  const correctionId = parsePositiveIntParam(id, "id");
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.revizyonCorrections.cancel(correctionId),
    {
      method: "POST",
      body: JSON.stringify({
        aciklama: payload?.aciklama ?? null
      })
    }
  );

  return parseRevizyonCorrectionResponse(response, "Revizyon correction iptal edilemedi.");
}
