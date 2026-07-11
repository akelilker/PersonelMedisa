import type { ApiResponse } from "../types/api";
import type {
  HaftalikBildirimMutabakatDetail,
  HaftalikBildirimMutabakatOzet
} from "../types/haftalik-bildirim-mutabakat";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export async function fetchHaftalikBildirimMutabakatOzet(
  haftaBaslangic: string,
  context?: { subeId?: number | null; birimAmiriUserId?: number | null }
): Promise<HaftalikBildirimMutabakatOzet> {
  const path = appendQueryParams(endpoints.haftalikBildirimMutabakatlari.summary, {
    hafta_baslangic: haftaBaslangic,
    sube_id: context?.subeId,
    birim_amiri_user_id: context?.birimAmiriUserId
  });
  const response = await apiRequest<ApiResponse<HaftalikBildirimMutabakatOzet>>(path);
  return response.data;
}

export async function approveHaftalikBildirimMutabakat(
  haftaBaslangic: string
): Promise<HaftalikBildirimMutabakatDetail> {
  const response = await apiRequest<ApiResponse<HaftalikBildirimMutabakatDetail>>(
    endpoints.haftalikBildirimMutabakatlari.approve,
    { method: "POST", body: JSON.stringify({ hafta_baslangic: haftaBaslangic }) }
  );
  return response.data;
}

export async function fetchHaftalikBildirimMutabakatDetail(
  id: number | string
): Promise<HaftalikBildirimMutabakatDetail> {
  const response = await apiRequest<ApiResponse<HaftalikBildirimMutabakatDetail>>(
    endpoints.haftalikBildirimMutabakatlari.detail(id)
  );
  return response.data;
}
