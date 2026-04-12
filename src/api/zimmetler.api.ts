import type { ApiResponse, PaginatedResult } from "../types/api";
import type { CreateZimmetPayload, Zimmet } from "../types/zimmet";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type ZimmetlerListParams = {
  personel_id?: number;
  sube_id?: number;
  zimmet_durumu?: string;
  page?: number;
  limit?: number;
};

function normalizeZimmet(data: unknown): Zimmet {
  if (typeof data !== "object" || data === null) {
    throw new Error("Zimmet yaniti beklenen formatta degil.");
  }

  const zimmet = data as Partial<Zimmet>;
  if (
    typeof zimmet.id !== "number" ||
    typeof zimmet.personel_id !== "number" ||
    typeof zimmet.urun_turu !== "string" ||
    typeof zimmet.teslim_tarihi !== "string" ||
    typeof zimmet.teslim_durumu !== "string" ||
    typeof zimmet.zimmet_durumu !== "string"
  ) {
    throw new Error("Zimmet yaniti eksik alan iceriyor.");
  }

  return zimmet as Zimmet;
}

export async function fetchZimmetlerList(
  params?: ZimmetlerListParams
): Promise<PaginatedResult<Zimmet>> {
  const path = appendQueryParams(endpoints.zimmetler.list, {
    personel_id: params?.personel_id,
    sube_id: params?.sube_id,
    zimmet_durumu: params?.zimmet_durumu,
    page: params?.page,
    limit: params?.limit
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const normalized = normalizePaginatedList<unknown>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });

  return {
    ...normalized,
    items: normalized.items.map((item) => normalizeZimmet(item))
  };
}

export async function createZimmet(payload: CreateZimmetPayload): Promise<Zimmet> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.zimmetler.list, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  const created = normalizeZimmet(response.data);
  logAction({ action: "ZIMMET_CREATE", payload: { zimmet_id: created.id, personel_id: created.personel_id } });
  return created;
}
