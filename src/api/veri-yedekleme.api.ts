import type { ApiResponse } from "../types/api";
import { getActiveSubeIdForApiHeader } from "../auth/auth-manager";
import { getAuthTokenForApi } from "../auth/auth-token-provider";
import { ApiRequestError, apiRequest, buildApiUrl } from "./api-client";

export type VeriYedegi = {
  file: string;
  sha256: string;
  bytes: number;
  created_at: string;
  ledger_tip: string;
  table_count: number;
  total_rows: number;
};

export type VeriYedeklemeDurumu = {
  son_yedek: VeriYedegi | null;
  yedekler: VeriYedegi[];
  saklanan_adet: number;
  geri_yukleme_etkin: boolean;
};

export const veriYedeklemeEndpoints = {
  durum: "/yonetim/veri-yedekleme",
  olustur: "/yonetim/veri-yedekleme",
  indir: (dosya: string) => `/yonetim/veri-yedekleme/indir?dosya=${encodeURIComponent(dosya)}`
};

export async function fetchVeriYedeklemeDurumu(): Promise<VeriYedeklemeDurumu> {
  const response = await apiRequest<ApiResponse<VeriYedeklemeDurumu>>(veriYedeklemeEndpoints.durum);
  return response.data;
}

export async function createVeriYedegi(): Promise<VeriYedegi> {
  const response = await apiRequest<ApiResponse<VeriYedegi>>(veriYedeklemeEndpoints.olustur, {
    method: "POST",
    body: JSON.stringify({}),
    timeoutMs: 300_000
  });
  return response.data;
}

/** Yedeği oturum başlığıyla indirir (dosya web kökünde değildir, doğrudan link yoktur). */
export async function downloadVeriYedegi(dosya: string): Promise<void> {
  const headers = new Headers();
  const token = getAuthTokenForApi();
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }
  const subeHeader = getActiveSubeIdForApiHeader();
  if (subeHeader) {
    headers.set("X-Active-Sube-Id", subeHeader);
  }
  const response = await fetch(buildApiUrl(veriYedeklemeEndpoints.indir(dosya)), { headers });
  if (!response.ok) {
    throw new ApiRequestError("Yedek indirilemedi.", response.status);
  }
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = dosya;
  anchor.click();
  URL.revokeObjectURL(url);
}
