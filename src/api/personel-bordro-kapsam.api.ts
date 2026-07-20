import type { ApiResponse } from "../types/api";
import type {
  CreatePersonelBordroKapsamPayload,
  PersonelBordroKapsamDryRunResult,
  PersonelBordroKapsamKaydi
} from "../types/personel-bordro-kapsam";
import { ApiRequestError, apiRequest, getApiErrorDetail } from "./api-client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

const KAPSAM_ERROR_STATUS: Record<string, number> = {
  KAPSAM_OVERLAP: 409,
  DRY_RUN_HASH_REQUIRED: 400,
  DRY_RUN_STALE: 409,
  FORBIDDEN: 403,
  VALIDATION_ERROR: 422,
  KAPSAM_NOT_FOUND: 404,
  INVALID_STATE: 409
};

function throwFirstError(errors: ApiResponse<unknown>["errors"], fallback: string): never {
  const first = errors?.[0];
  const code = typeof first?.code === "string" ? first.code : "INVALID_REQUEST";
  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallback,
    KAPSAM_ERROR_STATUS[code] ?? 400,
    { code }
  );
}

function assertNoErrors(response: ApiResponse<unknown>, fallback: string): void {
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstError(response.errors, fallback);
  }
}

export function getBordroKapsamApiErrorMessage(error: unknown, fallback: string): string {
  const detail = getApiErrorDetail(error, fallback);
  if (detail.code === "KAPSAM_OVERLAP") {
    return "Bu personel için seçilen tarih aralığında çakışan aktif bordro kapsam kaydı var.";
  }
  if (detail.code === "DRY_RUN_STALE") {
    return "Önizleme güncel değil; dry-run’u yenileyip tekrar deneyin.";
  }
  return detail.message;
}

function toNumber(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }
  return undefined;
}

function toStringValue(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }
  const trimmed = value.trim();
  return trimmed || undefined;
}

export function normalizePersonelBordroKapsamKaydi(data: unknown): PersonelBordroKapsamKaydi {
  if (typeof data !== "object" || data === null) {
    throw new Error("Bordro kapsam kaydı yanıtı beklenen formatta değil.");
  }
  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const personelId = toNumber(record.personel_id ?? record.personelId);
  const subeId = toNumber(record.sube_id ?? record.subeId);
  const durum = toStringValue(record.durum);
  const neden = toStringValue(record.neden_kodu ?? record.nedenKodu);
  const aciklama = toStringValue(record.aciklama) ?? "";
  const baslangic = toStringValue(record.gecerlilik_baslangic ?? record.gecerlilikBaslangic);
  const state = toStringValue(record.state);

  if (!id || !personelId || !subeId || !durum || !neden || !baslangic || !state) {
    throw new Error("Bordro kapsam kaydı yanıtı eksik alan içeriyor.");
  }

  const ad = toStringValue(record.ad);
  const soyad = toStringValue(record.soyad);
  const adSoyad =
    toStringValue(record.ad_soyad ?? record.adSoyad) ??
    ([ad, soyad].filter(Boolean).join(" ").trim() || undefined);

  return {
    id,
    personel_id: personelId,
    sube_id: subeId,
    durum: durum as PersonelBordroKapsamKaydi["durum"],
    neden_kodu: neden as PersonelBordroKapsamKaydi["neden_kodu"],
    aciklama,
    gecerlilik_baslangic: baslangic,
    gecerlilik_bitis: toStringValue(record.gecerlilik_bitis ?? record.gecerlilikBitis) ?? null,
    state: state as PersonelBordroKapsamKaydi["state"],
    hazirlayan_id: toNumber(record.hazirlayan_id) ?? null,
    onaylayan_id: toNumber(record.onaylayan_id) ?? null,
    onay_zamani: toStringValue(record.onay_zamani) ?? null,
    iptal_eden_id: toNumber(record.iptal_eden_id) ?? null,
    iptal_zamani: toStringValue(record.iptal_zamani) ?? null,
    iptal_nedeni: toStringValue(record.iptal_nedeni) ?? null,
    parent_kapsam_id: toNumber(record.parent_kapsam_id) ?? null,
    created_by: toNumber(record.created_by) ?? null,
    created_at: toStringValue(record.created_at) ?? null,
    updated_by: toNumber(record.updated_by) ?? null,
    updated_at: toStringValue(record.updated_at) ?? null,
    sicil_no: toStringValue(record.sicil_no) ?? null,
    ad: ad ?? null,
    soyad: soyad ?? null,
    ad_soyad: adSoyad ?? null
  };
}

export async function fetchPersonelBordroKapsamlari(
  personelId: number | string
): Promise<PersonelBordroKapsamKaydi[]> {
  const response = await apiRequest<
    ApiResponse<{ items: unknown[] }> | { items: unknown[] } | unknown[]
  >(endpoints.personelBordroKapsamlari.list(personelId));
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "Bordro kapsam listesi alınamadı.");
    return extractListItems((response as ApiResponse<unknown>).data).map(
      normalizePersonelBordroKapsamKaydi
    );
  }
  return extractListItems(response).map(normalizePersonelBordroKapsamKaydi);
}

export async function dryRunPersonelBordroKapsam(
  personelId: number | string,
  payload: Omit<CreatePersonelBordroKapsamPayload, "dry_run_hash"> & { dry_run_hash?: string }
): Promise<PersonelBordroKapsamDryRunResult> {
  const response = await apiRequest<ApiResponse<PersonelBordroKapsamDryRunResult> | PersonelBordroKapsamDryRunResult>(
    endpoints.personelBordroKapsamlari.dryRun(personelId),
    { method: "POST", body: JSON.stringify(payload) }
  );
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "Bordro kapsam dry-run başarısız.");
    return (response as ApiResponse<PersonelBordroKapsamDryRunResult>).data;
  }
  return response as PersonelBordroKapsamDryRunResult;
}

export async function createPersonelBordroKapsam(
  personelId: number | string,
  payload: CreatePersonelBordroKapsamPayload
): Promise<PersonelBordroKapsamKaydi> {
  const response = await apiRequest<ApiResponse<unknown> | unknown>(
    endpoints.personelBordroKapsamlari.create(personelId),
    { method: "POST", body: JSON.stringify(payload) }
  );
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "Bordro kapsam kaydı oluşturulamadı.");
    const data = (response as ApiResponse<unknown>).data;
    return normalizePersonelBordroKapsamKaydi(data);
  }
  return normalizePersonelBordroKapsamKaydi(response);
}

export async function submitPersonelBordroKapsam(
  personelId: number | string,
  kapsamId: number | string
): Promise<PersonelBordroKapsamKaydi> {
  const response = await apiRequest<ApiResponse<unknown> | unknown>(
    endpoints.personelBordroKapsamlari.submit(personelId, kapsamId),
    { method: "POST", body: JSON.stringify({}) }
  );
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "Onaya gönderilemedi.");
    return normalizePersonelBordroKapsamKaydi((response as ApiResponse<unknown>).data);
  }
  return normalizePersonelBordroKapsamKaydi(response);
}

export async function approvePersonelBordroKapsam(
  personelId: number | string,
  kapsamId: number | string
): Promise<PersonelBordroKapsamKaydi> {
  const response = await apiRequest<ApiResponse<unknown> | unknown>(
    endpoints.personelBordroKapsamlari.approve(personelId, kapsamId),
    { method: "POST", body: JSON.stringify({}) }
  );
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "Onaylanamadı.");
    return normalizePersonelBordroKapsamKaydi((response as ApiResponse<unknown>).data);
  }
  return normalizePersonelBordroKapsamKaydi(response);
}

export async function cancelPersonelBordroKapsam(
  personelId: number | string,
  kapsamId: number | string,
  neden: string
): Promise<PersonelBordroKapsamKaydi> {
  const response = await apiRequest<ApiResponse<unknown> | unknown>(
    endpoints.personelBordroKapsamlari.cancel(personelId, kapsamId),
    { method: "POST", body: JSON.stringify({ neden }) }
  );
  if (typeof response === "object" && response !== null && "errors" in response) {
    assertNoErrors(response as ApiResponse<unknown>, "İptal edilemedi.");
    return normalizePersonelBordroKapsamKaydi((response as ApiResponse<unknown>).data);
  }
  return normalizePersonelBordroKapsamKaydi(response);
}
