import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import type { MaasHesaplamaIssue } from "./maas-hesaplama.api";

export type BordroReadinessDomain = {
  key: string;
  label: string;
  status: "HAZIR" | "EKSİK" | "BLOKE" | "İNCELEME_GEREKLİ" | string;
  eksik_kayit_sayisi: number;
  etkilenen_personel_sayisi: number;
  aciklama: string;
  action_link: string;
  blocker_codes: string[];
  eksik_kodlar?: string[];
};

export type BordroCandidateGate = {
  aktif: boolean;
  disabled_nedenleri: string[];
  checks: Array<{ key: string; ok: boolean; mesaj: string }>;
};

export type BordroHazirlikPreflight = {
  sube_id: number;
  yil: number;
  ay: number;
  donem: string;
  hesaplanabilir_mi: boolean;
  blocker_count: number;
  warning_count: number;
  info_count: number;
  items: Array<
    MaasHesaplamaIssue & {
      action_link?: string | null;
      etkilenen_personel_sayisi?: number;
      etkilenen_kayit_sayisi?: number;
      kullanici_mesaji?: string;
      eksik_kodlar?: string[];
      alias_code?: string;
    }
  >;
  readiness_domains?: BordroReadinessDomain[];
  candidate_gate?: BordroCandidateGate;
  policy_summary: {
    onayli_politika_id: number | null;
    policy_version_hash: string | null;
    zorunlu_adet: number;
  };
  correction_projection_hash: string;
  contract_version: string;
};

export type BordroNetMaasEksikItem = {
  ad_soyad: string;
  sicil_no: string;
  sube_adi: string;
  departman_adi: string;
  gorev_adi: string;
  ise_giris_tarihi: string | null;
  isten_ayrilma: string | null;
  net_maas_durumu: "NULL" | "SIFIR" | "NEGATIF" | "LEGACY_ONLY" | "GECERSIZ" | string;
  legacy_maas_durumu: string;
  action_link: string;
};

export type BordroDevirImportResult = {
  dry_run: boolean;
  toplam_satir: number;
  basarili_satir: number;
  hatali_satir: number;
  counts?: {
    eklenecek: number;
    guncellenecek: number;
    degismeyecek: number;
    hatali: number;
    eslesmeyen: number;
    duplicate: number;
    scope_disi: number;
  };
  eklenecek?: number;
  guncellenecek?: number;
  degismeyecek?: number;
  hatali?: number;
  eslesmeyen?: number;
  duplicate?: number;
  scope_disi?: number;
  satirlar: Array<{
    satir: number;
    sicil?: string;
    ok: boolean;
    sinif?: string;
    hata?: string;
    personel_id?: number;
  }>;
};

export type BordroOnIzlemePersonelSatiri = {
  aday_id: number;
  personel_id: number;
  ad_soyad: string;
  sicil: string;
  sube_ad: string;
  departman_ad: string;
  net_maas: string | null;
  brut_maas: string | null;
  net_odenecek: string | null;
  toplam_ek_odeme: string | null;
  toplam_kesinti: string | null;
  durum: string;
  bordro_onay_durumu: string;
  aktif_correction_var_mi: boolean;
};

export type BordroOnIzlemeOzet = {
  donem: string;
  sube_id: number;
  toplam_personel: number;
  hesaplanabilir: number;
  blocker_bulunan: number;
  aday_olusturulan: number;
  kontrol_bekleyen: number;
  kesinlesen: number;
  toplam_net: string | null;
  toplam_brut: string | null;
  toplam_ek_odeme: string | null;
  toplam_kesinti: string | null;
  finance_masked?: boolean;
  calistirma: {
    id: number;
    bordro_onay_durumu: string;
    muhasebe_kontrol_notu?: string | null;
  } | null;
  preflight: BordroHazirlikPreflight;
  personel_satirlari: BordroOnIzlemePersonelSatiri[];
};

export type BordroDevirListItem = {
  personel: {
    ad: string;
    soyad: string;
    sicil: string;
    departman: string;
  };
  donem: string;
  devir: Record<string, unknown> | null;
  eksik_alanlar: string[];
  dogrulama_durumu: "TAMAM" | "EKSİK";
};

function unwrapData<T>(payload: ApiResponse<T> | T, fallback: string): T {
  if (typeof payload === "object" && payload !== null && "data" in payload) {
    return (payload as ApiResponse<T>).data;
  }
  return payload as T;
}

export async function fetchBordroHazirlikPreflight(params: {
  yil: number;
  ay: number;
  subeId: number;
}): Promise<BordroHazirlikPreflight> {
  const path = appendQueryParams(endpoints.bordroHazirlik.preflight, {
    sube_id: params.subeId,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<ApiResponse<BordroHazirlikPreflight> | BordroHazirlikPreflight>(path);
  return unwrapData(response, "Bordro preflight alinamadi.");
}

export async function fetchBordroReadiness(params: {
  yil: number;
  ay: number;
  subeId: number;
}): Promise<BordroHazirlikPreflight> {
  const path = appendQueryParams(endpoints.bordroHazirlik.readiness, {
    sube_id: params.subeId,
    yil: params.yil,
    ay: params.ay
  });
  const response = await apiRequest<ApiResponse<BordroHazirlikPreflight> | BordroHazirlikPreflight>(path);
  return unwrapData(response, "Bordro readiness alinamadi.");
}

export async function fetchBordroNetMaasEksikleri(params: {
  yil: number;
  ay: number;
  subeId: number;
  departmanId?: number | null;
}): Promise<BordroNetMaasEksikItem[]> {
  const path = appendQueryParams(endpoints.bordroHazirlik.netMaasEksikleri, {
    sube_id: params.subeId,
    yil: params.yil,
    ay: params.ay,
    ...(params.departmanId ? { departman_id: params.departmanId } : {})
  });
  const response = await apiRequest<
    ApiResponse<{ items: BordroNetMaasEksikItem[] }> | { items: BordroNetMaasEksikItem[] }
  >(path);
  return unwrapData(response, "Net maas eksikleri alinamadi.").items ?? [];
}

export async function fetchBordroOnIzleme(params: {
  yil: number;
  ay: number;
  subeId: number;
  departmanId?: number | null;
}): Promise<BordroOnIzlemeOzet> {
  const path = appendQueryParams(endpoints.bordroHazirlik.onIzleme, {
    sube_id: params.subeId,
    yil: params.yil,
    ay: params.ay,
    ...(params.departmanId ? { departman_id: params.departmanId } : {})
  });
  const response = await apiRequest<ApiResponse<BordroOnIzlemeOzet> | BordroOnIzlemeOzet>(path);
  return unwrapData(response, "Bordro on izleme alinamadi.");
}

export async function fetchBordroDevirListesi(params: {
  yil: number;
  ay: number;
  subeId: number;
  eksik?: boolean;
}): Promise<BordroDevirListItem[]> {
  const path = appendQueryParams(endpoints.bordroHazirlik.devirler, {
    sube_id: params.subeId,
    yil: params.yil,
    ay: params.ay,
    ...(params.eksik ? { eksik: "1" } : {})
  });
  const response = await apiRequest<ApiResponse<{ items: BordroDevirListItem[] }> | { items: BordroDevirListItem[] }>(
    path
  );
  return unwrapData(response, "Devir listesi alinamadi.").items ?? [];
}

export function bordroDevirSablonCsvUrl(params: {
  yil: number;
  ay: number;
  subeId: number;
  eksik?: boolean;
}): string {
  return appendQueryParams(endpoints.bordroHazirlik.devirSablonCsv, {
    sube_id: params.subeId,
    yil: params.yil,
    ay: params.ay,
    eksik: params.eksik === false ? "0" : "1"
  });
}

async function downloadAuthenticatedCsv(path: string, filename: string) {
  const { resolveDemoApiResponse } = await import("./mock-demo");
  const { ApiRequestError, buildApiUrl } = await import("./api-client");
  const { getAuthTokenForApi } = await import("../auth/auth-token-provider");
  const { getActiveSubeIdForApiHeader } = await import("../auth/auth-manager");

  const demoResponse = resolveDemoApiResponse(path, { method: "GET" });
  if (demoResponse !== null) {
    const csvContent =
      typeof demoResponse.data === "string" ? demoResponse.data : "sicil_no,ad_soyad\n";
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
    return;
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
    throw new ApiRequestError("CSV indirilemedi.", response.status);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

export async function downloadBordroDevirSablonCsv(params: {
  yil: number;
  ay: number;
  subeId: number;
  eksik?: boolean;
}) {
  const path = bordroDevirSablonCsvUrl(params);
  await downloadAuthenticatedCsv(path, `bordro-devir-sablon-${params.yil}-${String(params.ay).padStart(2, "0")}.csv`);
}

export async function downloadBordroReadinessCsv(params: {
  yil: number;
  ay: number;
  subeId: number;
}) {
  const path = appendQueryParams(endpoints.bordroHazirlik.readinessExportCsv, {
    sube_id: params.subeId,
    yil: params.yil,
    ay: params.ay
  });
  await downloadAuthenticatedCsv(path, `bordro-readiness-${params.yil}-${String(params.ay).padStart(2, "0")}.csv`);
}

export async function importBordroDevirler(payload: {
  yil: number;
  ay: number;
  subeId: number;
  dryRun: boolean;
  rows: Array<Record<string, string>>;
}): Promise<BordroDevirImportResult> {
  const response = await apiRequest(endpoints.bordroHazirlik.devirImport, {
    method: "POST",
    body: JSON.stringify({
      yil: payload.yil,
      ay: payload.ay,
      dry_run: payload.dryRun,
      rows: payload.rows
    }),
    headers: { "Content-Type": "application/json", "X-Active-Sube-Id": String(payload.subeId) }
  });
  return unwrapData(response, "Devir import islenemedi.") as BordroDevirImportResult;
}

export async function submitBordroKontrol(calistirmaId: number, not: string) {
  const response = await apiRequest(endpoints.bordroHazirlik.kontrolGonder(calistirmaId), {
    method: "POST",
    body: JSON.stringify({ muhasebe_kontrol_notu: not }),
    headers: { "Content-Type": "application/json" }
  });
  return unwrapData(response, "Muhasebe kontrolu gonderilemedi.");
}

export async function geriGonderBordro(calistirmaId: number, not: string) {
  const response = await apiRequest(endpoints.bordroHazirlik.geriGonder(calistirmaId), {
    method: "POST",
    body: JSON.stringify({ not }),
    headers: { "Content-Type": "application/json" }
  });
  return unwrapData(response, "Bordro geri gonderilemedi.");
}

export async function kesinlestirBordro(calistirmaId: number) {
  const response = await apiRequest(endpoints.bordroHazirlik.kesinlestir(calistirmaId), {
    method: "POST",
    body: JSON.stringify({}),
    headers: { "Content-Type": "application/json" }
  });
  return unwrapData(response, "Bordro kesinlestirilemedi.");
}
