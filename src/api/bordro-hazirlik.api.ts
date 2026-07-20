import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import type { MaasHesaplamaIssue } from "./maas-hesaplama.api";

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
    }
  >;
  policy_summary: {
    onayli_politika_id: number | null;
    policy_version_hash: string | null;
    zorunlu_adet: number;
  };
  correction_projection_hash: string;
  contract_version: string;
};

export type BordroOnIzlemePersonelSatiri = {
  aday_id: number;
  personel_id: number;
  ad_soyad: string;
  sicil: string;
  sube_ad: string;
  departman_ad: string;
  net_maas: string | null;
  brut_maas: string;
  net_odenecek: string;
  toplam_ek_odeme: string;
  toplam_kesinti: string;
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
  toplam_net: string;
  toplam_brut: string;
  toplam_ek_odeme: string;
  toplam_kesinti: string;
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
  dogrulama_durumu: "TAMAM" | "EKSIK";
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

export async function importBordroDevirler(payload: {
  yil: number;
  ay: number;
  subeId: number;
  dryRun: boolean;
  rows: Array<Record<string, string>>;
}) {
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
  return unwrapData(response, "Devir import islenemedi.");
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
