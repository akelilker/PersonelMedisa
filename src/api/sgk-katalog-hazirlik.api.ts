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

/** S98 canonical catalog status (legacy boolean/enum remain temporary projections). */
export type SgkAktiflikDurumu = "AKTIF" | "TARIHSEL" | "BAGLAMA_OZGUN" | "PORTAL_TEYIT_BEKLIYOR";
export type SgkSifirGunDurumu = "IZINLI" | "YASAK" | "KOSULLU" | "TEYITSIZ";
export type SgkBelgeSaklamaIbrazDurumu =
  | "YOK"
  | "ISVERENCE_SAKLA_TALEPTE_IBRAZ"
  | "ELEKTRONIK_KAYNAKTAN"
  | "KURUMA_GONDER"
  | "KOSULLU"
  | "TEYITSIZ";
export type SgkYabanciKullanimDurumu = "IZINLI" | "YASAK" | "KOSULLU" | "TEYITSIZ";
export type SgkPortalTeyitDurumu = "TEYIT_EDILDI" | "TEYIT_BEKLIYOR" | "TARIHSEL";

export type SgkTamlikDurumu = "TASLAK" | "RESMI_KAYNAKLI_KISITLI" | "DOGRULANMIS_TAM";
export type SgkGecerlilikTarihDurumu = "RESMI_YURURLUK" | "ILK_RESMI_KANIT" | "BELIRLENEMEDI";

export const SGK_TAMLIK_DURUMU_LABEL: Record<SgkTamlikDurumu, string> = {
  TASLAK: "TASLAK",
  RESMI_KAYNAKLI_KISITLI: "RESMÎ KAYNAKLI KISITLI",
  DOGRULANMIS_TAM: "DOĞRULANMIŞ TAM"
};

export const SGK_GECERLILIK_TARIH_DURUMU_LABEL: Record<SgkGecerlilikTarihDurumu, string> = {
  RESMI_YURURLUK: "Resmî yürürlük",
  ILK_RESMI_KANIT: "İlk resmî kanıt",
  BELIRLENEMEDI: "Belirlenemedi"
};

export const SGK_AKTIFLIK_DURUMU_LABEL: Record<SgkAktiflikDurumu, string> = {
  AKTIF: "AKTIF",
  TARIHSEL: "TARIHSEL",
  BAGLAMA_OZGUN: "BAĞLAMA ÖZGÜ",
  PORTAL_TEYIT_BEKLIYOR: "PORTAL TEYİDİ BEKLİYOR"
};

/** Demo/production: TEYITSIZ and historical codes must not appear as selectable current catalog. */
export function isSgkKodSecilebilir(input: {
  aktiflik_durumu?: string | null;
  portal_teyit_durumu?: string | null;
  sifir_gun_sifir_kazanc_durumu?: string | null;
  katalog_tamlik_durumu?: string | null;
}): boolean {
  const katalogTamlik = (input.katalog_tamlik_durumu ?? "").toUpperCase();
  const kisitliOnayli = katalogTamlik === "RESMI_KAYNAKLI_KISITLI";
  const aktiflik = (input.aktiflik_durumu ?? "").toUpperCase();
  const portal = (input.portal_teyit_durumu ?? "").toUpperCase();
  const sifir = (input.sifir_gun_sifir_kazanc_durumu ?? "").toUpperCase();
  if (sifir === "TEYITSIZ") return false;
  if (aktiflik === "TARIHSEL") return false;
  if (portal === "TARIHSEL") return false;
  if (kisitliOnayli && aktiflik === "PORTAL_TEYIT_BEKLIYOR" && portal === "TEYIT_BEKLIYOR") {
    return true;
  }
  if (aktiflik === "PORTAL_TEYIT_BEKLIYOR") return false;
  if (portal === "TEYIT_BEKLIYOR") return false;
  return aktiflik === "AKTIF" && portal === "TEYIT_EDILDI";
}

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

export type SgkSirketPolitikaReadItem = {
  sube_id: number;
  state: "ONAYLANDI" | "NO_APPROVED_POLICY" | "CONFLICT" | string;
  approved_policy_id?: number;
  surum_kodu?: string;
  status?: string;
  bildirim_donem_tipi?: string;
  gecerlilik_baslangic?: string | null;
  gecerlilik_bitis?: string | null;
  politika_hash?: string | null;
  hazirlayan_id?: number | null;
  onaylayan_id?: number | null;
  onay_zamani?: string | null;
  degerler?: Record<string, string>;
};

export type SgkSirketPolitikaRevisionItem = SgkSirketPolitikaReadItem & {
  policy_id: number;
  created_at?: string | null;
  effective_for_requested_period: boolean;
  overlaps_requested_period: boolean;
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

export async function fetchSgkSirketPolitikasi(params?: { sube_id?: number; yil?: number; ay?: number }) {
  const response = await apiRequest<
    ApiResponse<{
      items: SgkSirketPolitikaReadItem[];
      period: { baslangic: string; bitis: string };
    }>
  >(appendQueryParams(endpoints.sgkKatalogHazirlik.sirketPolitikasi, params ?? {}));

  return unwrapData(response);
}

export async function fetchSgkSirketPolitikasiSurumler(params: {
  sube_id: number;
  baslangic?: string;
  bitis?: string;
  yil?: number;
  ay?: number;
}) {
  const response = await apiRequest<
    ApiResponse<{
      sube_id: number;
      items: SgkSirketPolitikaRevisionItem[];
      period: { baslangic: string; bitis: string };
    }>
  >(appendQueryParams(endpoints.sgkKatalogHazirlik.sirketPolitikasiSurumler, params));

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

export async function importSgkKatalog(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.import,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function submitSgkKatalog(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.submit,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function approveSgkKatalog(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.approve,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function dryRunSgkSurecEsleme(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.surecEslemeDryRun,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function importSgkSurecEsleme(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.surecEslemeImport,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function dryRunSgkSirketPolitikasi(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.sirketPolitikasiDryRun,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function importSgkSirketPolitikasi(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.sirketPolitikasiImport,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function submitSgkSirketPolitikasi(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.sirketPolitikasiSubmit,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

export async function approveSgkSirketPolitikasi(body: Record<string, unknown>) {
  const response = await apiRequest<ApiResponse<Record<string, unknown>> | Record<string, unknown>>(
    endpoints.sgkKatalogHazirlik.sirketPolitikasiApprove,
    { method: "POST", body: JSON.stringify(body) }
  );
  return unwrapData(response);
}

async function downloadAuthenticatedCsv(path: string, filename: string, demoFallbackCsv: string): Promise<void> {
  const { ApiRequestError, buildApiUrl, shouldPreferDemoApi } = await import("./api-client");
  const { getAuthTokenForApi } = await import("../auth/auth-token-provider");
  const { getActiveSubeIdForApiHeader } = await import("../auth/auth-manager");

  if (shouldPreferDemoApi()) {
    const { resolveDemoApiResponse } = await import("./mock-demo");
    const demoResponse = resolveDemoApiResponse(path, { method: "GET" });
    if (demoResponse !== null) {
      const csvContent =
        typeof demoResponse.data === "string" ? demoResponse.data : demoFallbackCsv;
      const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8" });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = filename;
      anchor.click();
      URL.revokeObjectURL(url);
      return;
    }
  }

  const headers = new Headers();
  const token = getAuthTokenForApi();
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }
  const subeHeader = getActiveSubeIdForApiHeader();
  if (subeHeader) {
    headers.set("X-Active-Sube-Id", subeHeader);
  }

  const response = await fetch(buildApiUrl(path), { headers });
  if (!response.ok) {
    let message = "SGK şablon dosyası indirilemedi.";
    try {
      const payload = (await response.json()) as {
        errors?: Array<{ message?: string; code?: string }>;
      };
      const first = Array.isArray(payload.errors) ? payload.errors[0] : undefined;
      if (first?.message) {
        message = first.message;
      }
    } catch {
      // keep default
    }
    throw new ApiRequestError(message, response.status);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

export async function downloadSgkSurecEslemeSablonCsv(): Promise<void> {
  const { SGK_SUREC_ESLEME_SABLON_CSV } = await import("./sgk-katalog-hazirlik.mock");
  await downloadAuthenticatedCsv(
    endpoints.sgkKatalogHazirlik.surecEslemeSablonCsv,
    "sgk-surec-esleme-sablon.csv",
    SGK_SUREC_ESLEME_SABLON_CSV
  );
}

export async function downloadSgkSirketPolitikasiSablonCsv(): Promise<void> {
  const { SGK_SIRKET_POLITIKASI_SABLON_CSV } = await import("./sgk-katalog-hazirlik.mock");
  await downloadAuthenticatedCsv(
    endpoints.sgkKatalogHazirlik.sirketPolitikasiSablonCsv,
    "sgk-sirket-politikasi-sablon.csv",
    SGK_SIRKET_POLITIKASI_SABLON_CSV
  );
}
