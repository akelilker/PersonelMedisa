import type { ApiResponse } from "../types/api";
import type {
  GenelYoneticiBildirimOnayi,
  GenelYoneticiBildirimOnayiOlusturPayload,
  GenelYoneticiBildirimOnayiOzet
} from "../types/genel-yonetici-bildirim-onayi";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export async function fetchGenelYoneticiBildirimOnayiOzet(
  ay: string,
  subeId: number,
  birimAmiriUserId: number
): Promise<GenelYoneticiBildirimOnayiOzet> {
  const path = appendQueryParams(endpoints.genelYoneticiBildirimOnaylari.summary, {
    ay,
    sube_id: subeId,
    birim_amiri_user_id: birimAmiriUserId
  });
  const response = await apiRequest<ApiResponse<GenelYoneticiBildirimOnayiOzet>>(path);
  return response.data;
}

export async function approveGenelYoneticiBildirimOnayi(
  payload: GenelYoneticiBildirimOnayiOlusturPayload
): Promise<GenelYoneticiBildirimOnayi> {
  const path = appendQueryParams(endpoints.genelYoneticiBildirimOnaylari.approve, {
    sube_id: payload.sube_id
  });
  const response = await apiRequest<ApiResponse<GenelYoneticiBildirimOnayi>>(
    path,
    { method: "POST", body: JSON.stringify(payload) }
  );
  return response.data;
}
