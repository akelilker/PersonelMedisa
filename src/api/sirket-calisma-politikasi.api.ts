import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type SirketPolitikaDeger = {
  id?: number;
  parametre_kodu: string;
  etiket: string;
  aciklama?: string | null;
  deger_tipi: "SAYISAL" | "METIN";
  sayisal_deger?: string | null;
  metin_deger?: string | null;
  birim?: string | null;
  mevcut_deger?: string | null;
};

export type SirketCalismaPolitikasi = {
  id: number;
  revision_no: number;
  state: "TASLAK" | "ONAY_BEKLIYOR" | "ONAYLANDI" | "IPTAL";
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  aciklama: string | null;
  policy_version_hash: string | null;
  hazirlayan_ad?: string | null;
  onaylayan_ad?: string | null;
  onay_zamani?: string | null;
  degerler?: SirketPolitikaDeger[];
};

function unwrapData<T>(payload: ApiResponse<T> | T, fallback: string): T {
  if (typeof payload === "object" && payload !== null && "data" in payload) {
    return (payload as ApiResponse<T>).data;
  }
  return payload as T;
}

export async function fetchSirketPolitikaKatalog() {
  const response = await apiRequest<ApiResponse<{ items: SirketPolitikaDeger[] }> | { items: SirketPolitikaDeger[] }>(
    endpoints.sirketCalismaPolitikalari.katalog
  );
  return unwrapData(response, "Politika katalogu alinamadi.").items ?? [];
}

export async function fetchSirketPolitikalari(state?: string) {
  const path = state
    ? appendQueryParams(endpoints.sirketCalismaPolitikalari.list, { state })
    : endpoints.sirketCalismaPolitikalari.list;
  const response = await apiRequest<ApiResponse<{ items: SirketCalismaPolitikasi[] }> | { items: SirketCalismaPolitikasi[] }>(
    path
  );
  return unwrapData(response, "Politika listesi alinamadi.").items ?? [];
}

export async function fetchSirketPolitikaDetail(id: number) {
  const response = await apiRequest<ApiResponse<SirketCalismaPolitikasi> | SirketCalismaPolitikasi>(
    endpoints.sirketCalismaPolitikalari.detail(id)
  );
  return unwrapData(response, "Politika detayi alinamadi.");
}

export async function createSirketPolitikaDraft(payload: {
  gecerlilik_baslangic: string;
  gecerlilik_bitis?: string | null;
  aciklama?: string | null;
  degerler: Array<{
    parametre_kodu: string;
    sayisal_deger?: string;
    metin_deger?: string;
    mevcut_deger?: string;
  }>;
}) {
  const response = await apiRequest<ApiResponse<SirketCalismaPolitikasi> | SirketCalismaPolitikasi>(
    endpoints.sirketCalismaPolitikalari.list,
    {
      method: "POST",
      body: JSON.stringify(payload),
      headers: { "Content-Type": "application/json" }
    }
  );
  return unwrapData(response, "Politika taslagı olusturulamadi.");
}

export async function updateSirketPolitikaDraft(
  id: number,
  payload: {
    gecerlilik_baslangic: string;
    gecerlilik_bitis?: string | null;
    aciklama?: string | null;
    degerler: Array<{
      parametre_kodu: string;
      sayisal_deger?: string;
      metin_deger?: string;
      mevcut_deger?: string;
    }>;
  }
) {
  const response = await apiRequest<ApiResponse<SirketCalismaPolitikasi> | SirketCalismaPolitikasi>(
    endpoints.sirketCalismaPolitikalari.detail(id),
    {
      method: "PUT",
      body: JSON.stringify(payload),
      headers: { "Content-Type": "application/json" }
    }
  );
  return unwrapData(response, "Politika guncellenemedi.");
}

export async function submitSirketPolitika(id: number) {
  const response = await apiRequest<ApiResponse<SirketCalismaPolitikasi> | SirketCalismaPolitikasi>(
    endpoints.sirketCalismaPolitikalari.submit(id),
    {
      method: "POST",
      body: JSON.stringify({}),
      headers: { "Content-Type": "application/json" }
    }
  );
  return unwrapData(response, "Politika onaya gonderilemedi.");
}

export async function approveSirketPolitika(id: number) {
  const response = await apiRequest<ApiResponse<SirketCalismaPolitikasi> | SirketCalismaPolitikasi>(
    endpoints.sirketCalismaPolitikalari.approve(id),
    {
      method: "POST",
      body: JSON.stringify({}),
      headers: { "Content-Type": "application/json" }
    }
  );
  return unwrapData(response, "Politika onaylanamadi.");
}
