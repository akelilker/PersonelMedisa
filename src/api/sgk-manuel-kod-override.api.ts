import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import type { ApiResponse } from "../types/api";

export type CreateSgkManuelKodOverridePayload = {
  target_type: "SUREC" | "GUNLUK_PUANTAJ";
  target_id: number;
  personel_id: number;
  tarih: string;
  yeni_eksik_gun_kodu: string;
  gerekce: string;
  belge_id: number;
  idempotency_key: string;
  onceki_eksik_gun_kodu?: string | null;
};

export type SgkManuelKodOverrideResult = {
  id: number;
  state: string;
  supersedes_id?: number | null;
  idempotent_mi?: boolean;
};

function unwrapData<T>(payload: ApiResponse<T> | T): T {
  if (typeof payload === "object" && payload !== null && "data" in payload) {
    return (payload as ApiResponse<T>).data;
  }
  return payload as T;
}

export async function createSgkManuelKodOverride(
  payload: CreateSgkManuelKodOverridePayload
): Promise<SgkManuelKodOverrideResult> {
  const response = await apiRequest<ApiResponse<SgkManuelKodOverrideResult> | SgkManuelKodOverrideResult>(
    endpoints.sgkManuelKodOverride.create,
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );

  return unwrapData(response);
}
