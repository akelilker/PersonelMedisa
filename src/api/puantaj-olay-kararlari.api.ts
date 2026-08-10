import type { ApiResponse } from "../types/api";
import type { PuantajOlayKarar, PuantajOlayKararUpsertPayload } from "../types/puantaj-olay-karar";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type PuantajOlayKararlariListParams = {
  personel_id: number;
  from: string;
  to: string;
};

function normalizePuantajOlayKarar(data: unknown): PuantajOlayKarar {
  if (typeof data !== "object" || data === null) {
    throw new Error("Puantaj olay karar yaniti beklenen formatta degil.");
  }
  const row = data as Partial<PuantajOlayKarar>;
  if (typeof row.id !== "number" || typeof row.personel_id !== "number") {
    throw new Error("Puantaj olay karar yaniti eksik alan iceriyor.");
  }
  return row as PuantajOlayKarar;
}

export async function fetchPuantajOlayKararlariList(
  params: PuantajOlayKararlariListParams
): Promise<PuantajOlayKarar[]> {
  const path = appendQueryParams(endpoints.puantajOlayKararlari.list, {
    personel_id: params.personel_id,
    from: params.from,
    to: params.to
  });
  const response = await apiRequest<ApiResponse<{ items?: unknown[] }>>(path);
  const items = response.data?.items;
  if (!Array.isArray(items)) {
    return [];
  }
  return items.map(normalizePuantajOlayKarar);
}

export async function upsertPuantajOlayKarar(
  payload: PuantajOlayKararUpsertPayload
): Promise<PuantajOlayKarar> {
  const response = await apiRequest<ApiResponse<{ item?: unknown }>>(
    endpoints.puantajOlayKararlari.upsert,
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  return normalizePuantajOlayKarar(response.data?.item);
}
