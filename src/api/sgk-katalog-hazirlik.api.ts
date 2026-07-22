import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

/** Canonical decoded-byte limit for operasyonel kanıt Base64 (matches PHP SgkOperasyonelKanitBase64Guard::MAX_DECODED_BYTES). */
export const SGK_OPERASYONEL_KANIT_MAX_DECODED_BYTES = 10 * 1024 * 1024;

export type SgkKatalogBlocker = {
  severity: "BLOCKER" | string;
  code: string;
  message: string;
  domain?: string;
  cozum_onerisi?: string;
};

export type SgkKatalogTamlik = {
  tamlik_durumu: string;
  katalog_surumu: string;
  manifest_set_hash: string;
  kod_sayisi: number;
  kaynak_sayisi: number;
  eksik_kanitlar: string[];
  erisilemeyen_kaynaklar: string[];
  operasyonel_kanitlar: Array<{
    kanit_turu: string;
    dosya_adi: string;
    sha256: string;
    mevzuat_kaynagi_mi: boolean;
    tek_basina_yeterli_mi: boolean;
    destekledigi_kodlar: string[];
  }>;
  blocker_kodlari: string[];
  blocker_detaylari?: SgkKatalogBlocker[];
  onaylanabilir_mi: boolean;
  dogrulanmis_tam_secilebilir_mi?: boolean;
  import_yazma_aktif_mi?: boolean;
  approve_aktif_mi?: boolean;
  response_hash: string;
};

export type SgkKatalogImportDryRun = {
  mode: "DRY_RUN" | string;
  format: string;
  gecerli_satirlar: Array<Record<string, unknown>>;
  hatali_satirlar: Array<{ row_index: number; eksik_gun_kodu?: string; errors: string[] }>;
  warnings: string[];
  blocker_kodlari: string[];
  blocker_detaylari?: SgkKatalogBlocker[];
  canonical_payload: { rows: Array<Record<string, unknown>> };
  payload_hash: string;
  manifest_set_hash: string;
  import_yapilabilir_mi: boolean;
  yazma_endpoint_aktif_mi?: boolean;
  response_hash: string;
};

export type SgkKatalogBlockerRaporu = {
  blocker_kodlari: string[];
  blocker_detaylari: SgkKatalogBlocker[];
  tamlik: SgkKatalogTamlik;
  approve_disabled_mi: boolean;
  import_write_disabled_mi: boolean;
  response_hash: string;
};

function unwrapData<T>(payload: ApiResponse<T> | T): T {
  if (typeof payload === "object" && payload !== null && "data" in payload) {
    return (payload as ApiResponse<T>).data;
  }
  return payload as T;
}

export async function fetchSgkKatalogTamlik(body?: Record<string, unknown>) {
  if (body && Object.keys(body).length > 0) {
    const response = await apiRequest<ApiResponse<SgkKatalogTamlik> | SgkKatalogTamlik>(
      endpoints.sgkKatalogHazirlik.tamlik,
      { method: "POST", body: JSON.stringify(body) }
    );
    return unwrapData(response);
  }
  const response = await apiRequest<ApiResponse<SgkKatalogTamlik> | SgkKatalogTamlik>(
    endpoints.sgkKatalogHazirlik.tamlik
  );
  return unwrapData(response);
}

export async function fetchSgkKatalogKaynaklar(params?: { page?: number; limit?: number }) {
  const response = await apiRequest<
    | ApiResponse<{
        items: Array<Record<string, unknown>>;
        page: number;
        limit: number;
        total: number;
        seed_var_mi: boolean;
        response_hash: string;
      }>
    | {
        items: Array<Record<string, unknown>>;
        page: number;
        limit: number;
        total: number;
        seed_var_mi: boolean;
        response_hash: string;
      }
  >(appendQueryParams(endpoints.sgkKatalogHazirlik.kaynaklar, params ?? {}));
  return unwrapData(response);
}

export async function fetchSgkKatalogSurumler() {
  const response = await apiRequest<
    | ApiResponse<{ items: unknown[]; total: number; dogrulanmis_tam_var_mi: boolean; response_hash: string }>
    | { items: unknown[]; total: number; dogrulanmis_tam_var_mi: boolean; response_hash: string }
  >(endpoints.sgkKatalogHazirlik.surumler);
  return unwrapData(response);
}

export async function dryRunSgkKatalogImport(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<SgkKatalogImportDryRun> | SgkKatalogImportDryRun>(
    endpoints.sgkKatalogHazirlik.importDryRun,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function validateSgkSurecEsleme(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.surecEslemeValidate,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function validateSgkCokluNeden(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.cokluNedenValidate,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function fetchSgkKatalogBlockerRaporu() {
  const response = await apiRequest<ApiResponse<SgkKatalogBlockerRaporu> | SgkKatalogBlockerRaporu>(
    endpoints.sgkKatalogHazirlik.blockerRaporu
  );
  return unwrapData(response);
}

export async function validateSgkOperasyonelKanit(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.operasyonelKanitValidate,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function previewSgkKismiSureli(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.kismiSureliPreview,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function previewSgkBildirimDonemi(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.bildirimDonemiPreview,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function validateSgkKatalogOnay(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.onayValidate,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}
