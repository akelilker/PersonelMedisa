import type { ApiResponse, PaginatedResult } from "../types/api";
import type { Bildirim } from "../types/bildirim";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type BildirimlerListParams = {
  tarih?: string;
  departman_id?: number;
  personel_id?: number;
  bildirim_turu?: string;
  sube_id?: number;
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

export type UpdateBildirimPayload = Partial<CreateBildirimPayload> & {
  okundu_mi?: boolean;
};

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
    sube_id: params?.sube_id,
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
  const created = normalizeBildirim(response.data);
  logAction({ action: "BILDIRIM_CREATE", payload: { bildirim_id: created.id } });
  return created;
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
  const updated = normalizeBildirim(response.data);
  if (payload.okundu_mi === true) {
    logAction({ action: "BILDIRIM_MARK_READ", payload: { bildirim_id: updated.id } });
  } else {
    logAction({ action: "BILDIRIM_UPDATE", payload: { bildirim_id: updated.id } });
  }
  return updated;
}

export async function cancelBildirim(bildirimId: number | string): Promise<void> {
  await apiRequest<ApiResponse<unknown>>(`${endpoints.bildirimler.detail(bildirimId)}/iptal`, {
    method: "POST"
  });
  logAction({ action: "BILDIRIM_CANCEL", payload: { bildirim_id: bildirimId } });
}

export async function markBildirimOkundu(bildirimId: number | string): Promise<Bildirim> {
  return updateBildirim(bildirimId, { okundu_mi: true });
}
