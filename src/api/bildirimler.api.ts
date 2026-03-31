import type { ApiResponse, PaginatedResult } from "../types/api";
import type { Bildirim } from "../types/bildirim";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type BildirimlerListParams = {
  tarih?: string;
  departman_id?: number;
  personel_id?: number;
  bildirim_turu?: string;
  page?: number;
  limit?: number;
};

export type CreateBildirimPayload = {
  tarih: string;
  departman_id: number;
  personel_id: number;
  bildirim_turu: string;
  aciklama?: string;
};

export type UpdateBildirimPayload = Partial<CreateBildirimPayload>;

function normalizeBildirim(data: unknown): Bildirim {
  if (typeof data !== "object" || data === null) {
    throw new Error("Bildirim yaniti beklenen formatta degil.");
  }

  const bildirim = data as Partial<Bildirim>;
  if (typeof bildirim.id !== "number" || typeof bildirim.bildirim_turu !== "string") {
    throw new Error("Bildirim yaniti eksik alan iceriyor.");
  }

  return bildirim as Bildirim;
}

export async function fetchBildirimlerList(
  params?: BildirimlerListParams
): Promise<PaginatedResult<Bildirim>> {
  const path = appendQueryParams(endpoints.bildirimler.list, {
    tarih: params?.tarih,
    departman_id: params?.departman_id,
    personel_id: params?.personel_id,
    bildirim_turu: params?.bildirim_turu,
    page: params?.page,
    limit: params?.limit
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizePaginatedList<Bildirim>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });
}

export async function createBildirim(payload: CreateBildirimPayload): Promise<Bildirim> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.bildirimler.list, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  return normalizeBildirim(response.data);
}

export async function fetchBildirimDetail(bildirimId: number | string): Promise<Bildirim> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.bildirimler.detail(bildirimId));
  return normalizeBildirim(response.data);
}

export async function updateBildirim(
  bildirimId: number | string,
  payload: UpdateBildirimPayload
): Promise<Bildirim> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.bildirimler.detail(bildirimId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  return normalizeBildirim(response.data);
}

export async function cancelBildirim(bildirimId: number | string): Promise<void> {
  await apiRequest<ApiResponse<unknown>>(`${endpoints.bildirimler.detail(bildirimId)}/iptal`, {
    method: "POST"
  });
}
