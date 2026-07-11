import type { ApiResponse } from "../types/api";
import type {
  AylikBildirimOnayDetail,
  AylikBildirimOnayOzet,
  AylikBildirimOnayPayload
} from "../types/aylik-bildirim-onay";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export async function fetchAylikBildirimOnayiOzet(
  ay: string,
  context?: { subeId?: number | null; birimAmiriUserId?: number | null }
): Promise<AylikBildirimOnayOzet> {
  const path = appendQueryParams(endpoints.aylikBildirimOnaylari.summary, {
    ay,
    sube_id: context?.subeId,
    birim_amiri_user_id: context?.birimAmiriUserId
  });
  const response = await apiRequest<ApiResponse<AylikBildirimOnayOzet>>(path);
  return response.data;
}

export async function approveAylikBildirimOnayi(
  payload: AylikBildirimOnayPayload
): Promise<AylikBildirimOnayDetail> {
  const response = await apiRequest<ApiResponse<AylikBildirimOnayDetail>>(
    endpoints.aylikBildirimOnaylari.approve,
    { method: "POST", body: JSON.stringify(payload) }
  );
  return response.data;
}

export async function fetchAylikBildirimOnayiDetail(
  id: number | string
): Promise<AylikBildirimOnayDetail> {
  const response = await apiRequest<ApiResponse<AylikBildirimOnayDetail>>(
    endpoints.aylikBildirimOnaylari.detail(id)
  );
  return response.data;
}
