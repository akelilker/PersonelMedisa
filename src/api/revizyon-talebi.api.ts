import type { ApiResponse } from "../types/api";
import type {
  PostRevizyonTalebiPayload,
  RevizyonJsonDeger,
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

function toJsonDeger(value: unknown): RevizyonJsonDeger {
  if (value === null || value === undefined) {
    return null;
  }

  if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
    return value;
  }

  if (Array.isArray(value)) {
    return value.map((item) => toJsonDeger(item));
  }

  if (typeof value === "object") {
    const out: { [key: string]: RevizyonJsonDeger } = {};
    for (const [key, nested] of Object.entries(value as Record<string, unknown>)) {
      out[key] = toJsonDeger(nested);
    }
    return out;
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
  const correctionDurumuRaw = toOptionalString(record.correction_durumu);
  const correction_durumu =
    correctionDurumuRaw === "AKTIF" || correctionDurumuRaw === "IPTAL" ? correctionDurumuRaw : null;

  const auditRaw = record.audit_gecmisi;
  const audit_gecmisi = Array.isArray(auditRaw)
    ? auditRaw
        .map((item) => {
          const row = toRecord(item);
          if (!row) {
            return null;
          }
          const aksiyon = toOptionalString(row.aksiyon);
          const sonraki = toOptionalString(row.sonraki_durum);
          const userId = toOptionalNumber(row.islem_yapan_kullanici_id);
          const zaman = toOptionalString(row.islem_zamani);
          if (!aksiyon || !sonraki || userId === undefined || !zaman) {
            return null;
          }
          return {
            aksiyon,
            onceki_durum: toOptionalString(row.onceki_durum) ?? null,
            sonraki_durum: sonraki,
            islem_yapan_kullanici_id: userId,
            islem_yapan_kullanici_adi: toOptionalString(row.islem_yapan_kullanici_adi) ?? null,
            islem_zamani: zaman,
            aciklama: toOptionalString(row.aciklama) ?? null
          };
        })
        .filter((item): item is NonNullable<typeof item> => item !== null)
    : undefined;

  return {
    id,
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
    revizyon_tipi,
    onceki_deger: toJsonDeger(record.onceki_deger),
    talep_edilen_deger: toJsonDeger(record.talep_edilen_deger),
    aktif_correction_sonrasi_deger:
      record.aktif_correction_sonrasi_deger === undefined
        ? undefined
        : toJsonDeger(record.aktif_correction_sonrasi_deger),
    gerekce,
    talep_eden_kullanici_id,
    talep_eden_kullanici_adi: toOptionalString(record.talep_eden_kullanici_adi) ?? null,
    talep_zamani,
    durum,
    karar_veren_kullanici_id: karar_veren_kullanici_id ?? null,
    karar_veren_kullanici_adi: toOptionalString(record.karar_veren_kullanici_adi) ?? null,
    karar_zamani: toOptionalString(record.karar_zamani) ?? null,
    karar_notu: toOptionalString(record.karar_notu) ?? null,
    bordro_etki_var_mi: toBoolean(record.bordro_etki_var_mi, false),
    bordro_etki_notu: toOptionalString(record.bordro_etki_notu) ?? null,
    correction_event_id: correction_event_id ?? null,
    correction_durumu,
    aktif_correction_var_mi: toBoolean(record.aktif_correction_var_mi, false),
    audit_gecmisi
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
    hafta_bitis: filters?.hafta_bitis,
    revizyon_tipi: filters?.revizyon_tipi,
    departman_id: filters?.departman_id,
    bordro_etki_var_mi:
      filters?.bordro_etki_var_mi === undefined ? undefined : filters.bordro_etki_var_mi ? "1" : "0",
    correction_var_mi:
      filters?.correction_var_mi === undefined ? undefined : filters.correction_var_mi ? "1" : "0",
    correction_durumu: filters?.correction_durumu
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
      talep_edilen_deger: payload.talep_edilen_deger,
      gerekce,
      bordro_etki_var_mi: payload.bordro_etki_var_mi ?? false,
      bordro_etki_notu: payload.bordro_etki_notu ?? null
    })
  });

  return parseRevizyonTalebiResponse(response, "Revizyon talebi olusturulamadi.");
}

export async function fetchRevizyonKaynaklar(params: {
  personel_id: number | string;
  hafta_baslangic: string;
  hafta_bitis: string;
}): Promise<
  Array<{
    kaynak_tipi: string;
    kaynak_id: number;
    etkilenen_tarih: string;
    kaynak_turu_label: string;
    mevcut_deger: RevizyonJsonDeger;
    goruntuleme_etiketi: string;
    uygun_revizyon_tipleri: string[];
  }>
> {
  const personel_id = parsePositiveIntParam(params.personel_id, "personel_id");
  const path = appendQueryParams(endpoints.revizyonTalepleri.kaynaklar, {
    personel_id,
    hafta_baslangic: params.hafta_baslangic,
    hafta_bitis: params.hafta_bitis
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Revizyon kaynaklari alinamadi.");
  }
  const record = toRecord(response.data);
  const items = record?.items;
  if (!Array.isArray(items)) {
    return [];
  }
  return items
    .map((item) => {
      const row = toRecord(item);
      if (!row) {
        return null;
      }
      const kaynak_id = toOptionalNumber(row.kaynak_id);
      const kaynak_tipi = toOptionalString(row.kaynak_tipi);
      const etkilenen_tarih = toOptionalString(row.etkilenen_tarih);
      const kaynak_turu_label = toOptionalString(row.kaynak_turu_label);
      const goruntuleme_etiketi = toOptionalString(row.goruntuleme_etiketi);
      if (
        kaynak_id === undefined ||
        !kaynak_tipi ||
        !etkilenen_tarih ||
        !kaynak_turu_label ||
        !goruntuleme_etiketi
      ) {
        return null;
      }
      const uygun = Array.isArray(row.uygun_revizyon_tipleri)
        ? row.uygun_revizyon_tipleri.filter((v): v is string => typeof v === "string")
        : [];
      return {
        kaynak_tipi,
        kaynak_id,
        etkilenen_tarih,
        kaynak_turu_label,
        mevcut_deger: toJsonDeger(row.mevcut_deger),
        goruntuleme_etiketi,
        uygun_revizyon_tipleri: uygun
      };
    })
    .filter((item): item is NonNullable<typeof item> => item !== null);
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
