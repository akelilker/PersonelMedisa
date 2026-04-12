import type { ApiResponse, PaginatedResult } from "../types/api";
import type { Surec } from "../types/surec";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type SureclerListParams = {
  personel_id?: number;
  surec_turu?: string;
  baslangic_tarihi?: string;
  bitis_tarihi?: string;
  state?: string;
  departman_id?: number;
  sube_id?: number;
  page?: number;
  limit?: number;
};

export type CreateSurecPayload = {
  personel_id: number;
  surec_turu: string;
  alt_tur?: string;
  baslangic_tarihi: string;
  bitis_tarihi?: string;
  ucretli_mi?: boolean;
  aciklama?: string;
};

export type UpdateSurecPayload = Partial<CreateSurecPayload>;

function normalizeSurec(data: unknown): Surec {
  if (typeof data !== "object" || data === null) {
    throw new Error("Surec yaniti beklenen formatta degil.");
  }

  const surec = data as Partial<Surec>;
  if (
    typeof surec.id !== "number" ||
    typeof surec.personel_id !== "number" ||
    typeof surec.surec_turu !== "string"
  ) {
    throw new Error("Surec yaniti eksik alan iceriyor.");
  }

  return surec as Surec;
}

export async function fetchSureclerList(
  params?: SureclerListParams
): Promise<PaginatedResult<Surec>> {
  const path = appendQueryParams(endpoints.surecler.list, {
    personel_id: params?.personel_id,
    surec_turu: params?.surec_turu,
    baslangic_tarihi: params?.baslangic_tarihi,
    bitis_tarihi: params?.bitis_tarihi,
    state: params?.state,
    departman_id: params?.departman_id,
    sube_id: params?.sube_id,
    page: params?.page,
    limit: params?.limit
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizePaginatedList<Surec>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });
}

export async function createSurec(payload: CreateSurecPayload): Promise<Surec> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.surecler.list, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  const created = normalizeSurec(response.data);
  logAction({ action: "SUREC_CREATE", payload: { surec_id: created.id } });
  return created;
}

export async function fetchSurecDetail(surecId: number | string): Promise<Surec> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.surecler.detail(surecId));
  return normalizeSurec(response.data);
}

export async function updateSurec(
  surecId: number | string,
  payload: UpdateSurecPayload
): Promise<Surec> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.surecler.detail(surecId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  const updated = normalizeSurec(response.data);
  logAction({ action: "SUREC_UPDATE", payload: { surec_id: updated.id } });
  return updated;
}

export async function cancelSurec(surecId: number | string): Promise<void> {
  await apiRequest<ApiResponse<unknown>>(`${endpoints.surecler.detail(surecId)}/iptal`, {
    method: "POST"
  });
  logAction({ action: "SUREC_CANCEL", payload: { surec_id: surecId } });
}
