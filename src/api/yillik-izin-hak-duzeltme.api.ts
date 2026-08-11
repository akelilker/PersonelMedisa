import type { ApiResponse } from "../types/api";
import type {
  CreateYillikIzinHakDuzeltmePayload,
  ReverseYillikIzinHakDuzeltmePayload,
  YillikIzinBakiye,
  YillikIzinHakDuzeltmeKaydi
} from "../types/yillik-izin-hak-duzeltme";
import { ApiRequestError, apiRequest, getApiErrorDetail } from "./api-client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

const ERROR_STATUS: Record<string, number> = {
  FORBIDDEN: 403,
  VALIDATION_ERROR: 422,
  NOT_FOUND: 404,
  PERSONEL_NOT_FOUND: 404,
  ALREADY_REVERSED: 409,
  INVALID_REVERSAL_TARGET: 409,
  SCHEMA_MISSING: 503
};

function throwFirstError(errors: ApiResponse<unknown>["errors"], fallback: string): never {
  const first = errors?.[0];
  const code = typeof first?.code === "string" ? first.code : "INVALID_REQUEST";
  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallback,
    ERROR_STATUS[code] ?? 400,
    { code }
  );
}

function assertNoErrors(response: ApiResponse<unknown>, fallback: string): void {
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstError(response.errors, fallback);
  }
}

export function getYillikIzinHakDuzeltmeApiErrorMessage(error: unknown, fallback: string): string {
  const detail = getApiErrorDetail(error, fallback);
  if (detail.code === "ALREADY_REVERSED") {
    return "Bu düzeltme kaydı zaten terslenmiş.";
  }
  if (detail.code === "INVALID_REVERSAL_TARGET") {
    return "Bu kayıt terslenemez.";
  }
  return detail.message;
}

function toNumber(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) return value;
  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) return parsed;
  }
  return undefined;
}

function toStringValue(value: unknown): string | undefined {
  if (typeof value !== "string") return undefined;
  const trimmed = value.trim();
  return trimmed || undefined;
}

export function normalizeYillikIzinBakiye(data: unknown): YillikIzinBakiye {
  if (typeof data !== "object" || data === null) {
    throw new Error("İzin bakiyesi yanıtı beklenen formatta değil.");
  }
  const record = data as Record<string, unknown>;
  const personelId = toNumber(record.personel_id ?? record.personelId);
  if (!personelId) {
    throw new Error("İzin bakiyesi yanıtında personel_id eksik.");
  }

  const kullanilanRaw = record.kullanilan_gun ?? record.kullanilanGun;
  const kalanRaw = record.kalan_gun ?? record.kalanGun;
  const hamRaw = record.ham_kalan_gun ?? record.hamKalanGun;
  const eksik = Array.isArray(record.eksik_takvim_tarihleri)
    ? record.eksik_takvim_tarihleri.filter((item): item is string => typeof item === "string")
    : [];

  const birikmis =
    toNumber(record.birikmis_yasal_hak_gun) ??
    toNumber(record.yasal_hak_gun) ??
    0;
  const mevcut =
    toNumber(record.mevcut_yillik_hak_gun) ??
    toNumber(record.yillik_izin_gun) ??
    0;

  return {
    personel_id: personelId,
    contract_version: toStringValue(record.contract_version) ?? "",
    referans_tarih: toStringValue(record.referans_tarih) ?? null,
    annual_band_semantic: toStringValue(record.annual_band_semantic),
    balance_legal_semantic: toStringValue(record.balance_legal_semantic),
    kidem_yil: toNumber(record.kidem_yil) ?? 0,
    yas: toNumber(record.yas) ?? null,
    yas_istisna_uygulandi: Boolean(record.yas_istisna_uygulandi),
    mevcut_yillik_hak_gun: mevcut,
    birikmis_yasal_hak_gun: birikmis,
    yasal_hak_gun: birikmis,
    manuel_duzeltme_gun: toNumber(record.manuel_duzeltme_gun) ?? 0,
    efektif_hak_gun: toNumber(record.efektif_hak_gun) ?? 0,
    kullanilan_gun: kullanilanRaw === null || kullanilanRaw === undefined ? null : (toNumber(kullanilanRaw) ?? null),
    ham_kalan_gun: hamRaw === null || hamRaw === undefined ? null : (toNumber(hamRaw) ?? null),
    kalan_gun: kalanRaw === null || kalanRaw === undefined ? null : (toNumber(kalanRaw) ?? null),
    takvim_dogrulandi_mi: Boolean(record.takvim_dogrulandi_mi),
    eksik_takvim_tarihleri: eksik,
    sayilan_normal_gun: toNumber(record.sayilan_normal_gun) ?? 0,
    haric_tutulan_hafta_tatili_gun: toNumber(record.haric_tutulan_hafta_tatili_gun) ?? 0,
    haric_tutulan_ubgt_gun: toNumber(record.haric_tutulan_ubgt_gun) ?? 0,
    duzeltme_adet: toNumber(record.duzeltme_adet) ?? 0,
    hesap_engeli: toStringValue(record.hesap_engeli)
  };
}

export function normalizeYillikIzinHakDuzeltmeKaydi(data: unknown): YillikIzinHakDuzeltmeKaydi {
  if (typeof data !== "object" || data === null) {
    throw new Error("Hak düzeltme kaydı yanıtı beklenen formatta değil.");
  }
  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const personelId = toNumber(record.personel_id ?? record.personelId);
  const gunDelta = toNumber(record.gun_delta ?? record.gunDelta);
  const kategori = toStringValue(record.kategori);
  const aciklama = toStringValue(record.aciklama) ?? "";
  const effectiveDate = toStringValue(record.effective_date ?? record.effectiveDate);
  if (!id || !personelId || gunDelta === undefined || !kategori || !effectiveDate) {
    throw new Error("Hak düzeltme kaydı yanıtı eksik alan içeriyor.");
  }

  const reversesRaw = record.reverses_id ?? record.reversesId;
  return {
    id,
    personel_id: personelId,
    gun_delta: gunDelta,
    kategori: kategori as YillikIzinHakDuzeltmeKaydi["kategori"],
    aciklama,
    effective_date: effectiveDate,
    reverses_id:
      reversesRaw === null || reversesRaw === undefined ? null : (toNumber(reversesRaw) ?? null),
    is_reversed: Boolean(record.is_reversed ?? record.isReversed),
    created_by: toNumber(record.created_by ?? record.createdBy) ?? null,
    created_by_display:
      toStringValue(
        record.created_by_display ?? record.createdByDisplay ?? record.created_by_ad ?? record.createdByAd
      ) ?? null,
    created_at: toStringValue(record.created_at ?? record.createdAt) ?? ""
  };
}

export async function fetchYillikIzinBakiye(personelId: number | string): Promise<YillikIzinBakiye> {
  const response = await apiRequest<ApiResponse<YillikIzinBakiye> | YillikIzinBakiye>(
    endpoints.yillikIzinHak.balance(personelId)
  );
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "İzin bakiyesi alınamadı.");
    return normalizeYillikIzinBakiye((response as ApiResponse<unknown>).data);
  }
  return normalizeYillikIzinBakiye(response);
}

export async function fetchYillikIzinHakDuzeltmeleri(
  personelId: number | string
): Promise<YillikIzinHakDuzeltmeKaydi[]> {
  const response = await apiRequest<
    ApiResponse<{ items?: unknown[] }> | { items?: unknown[] } | unknown[]
  >(endpoints.yillikIzinHak.list(personelId));
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "Hak düzeltmeleri alınamadı.");
    return extractListItems((response as ApiResponse<unknown>).data).map(
      normalizeYillikIzinHakDuzeltmeKaydi
    );
  }
  return extractListItems(response).map(normalizeYillikIzinHakDuzeltmeKaydi);
}

export async function createYillikIzinHakDuzeltme(
  personelId: number | string,
  payload: CreateYillikIzinHakDuzeltmePayload
): Promise<YillikIzinHakDuzeltmeKaydi> {
  const response = await apiRequest<ApiResponse<unknown> | unknown>(
    endpoints.yillikIzinHak.create(personelId),
    { method: "POST", body: JSON.stringify(payload) }
  );
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "Hak düzeltmesi oluşturulamadı.");
    return normalizeYillikIzinHakDuzeltmeKaydi((response as ApiResponse<unknown>).data);
  }
  return normalizeYillikIzinHakDuzeltmeKaydi(response);
}

export async function reverseYillikIzinHakDuzeltme(
  personelId: number | string,
  duzeltmeId: number | string,
  payload: ReverseYillikIzinHakDuzeltmePayload
): Promise<YillikIzinHakDuzeltmeKaydi> {
  const response = await apiRequest<ApiResponse<unknown> | unknown>(
    endpoints.yillikIzinHak.reverse(personelId, duzeltmeId),
    { method: "POST", body: JSON.stringify(payload) }
  );
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "Ters kayıt oluşturulamadı.");
    return normalizeYillikIzinHakDuzeltmeKaydi((response as ApiResponse<unknown>).data);
  }
  return normalizeYillikIzinHakDuzeltmeKaydi(response);
}
