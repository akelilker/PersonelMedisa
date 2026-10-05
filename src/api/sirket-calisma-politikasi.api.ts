import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type SirketPolitikaEvidenceStatus =
  | "PRESENT_VALID"
  | "MISSING"
  | "LEGACY_MISSING"
  | "INVALID";

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
  belge_id: string | null;
  belge_sha256: string | null;
  evidence_status: SirketPolitikaEvidenceStatus;
  policy_version_hash: string | null;
  hazirlayan_id?: number | null;
  hazirlayan_ad?: string | null;
  onaylayan_ad?: string | null;
  onay_zamani?: string | null;
  degerler?: SirketPolitikaDeger[];
};

export type SirketPolitikaDraftPayload = {
  gecerlilik_baslangic: string;
  gecerlilik_bitis?: string | null;
  aciklama?: string | null;
  belge_id?: string | null;
  belge_sha256?: string | null;
  degerler: Array<{
    parametre_kodu: string;
    sayisal_deger?: string;
    metin_deger?: string;
    mevcut_deger?: string;
  }>;
};

const ZERO_HASH = "0".repeat(64);
const PLACEHOLDER_HASHES = new Set(["tbd", "pending", "unknown", ZERO_HASH]);

/** Client-side evidence validation helper (server remains owner). */
export function normalizePolitikaEvidenceInput(belgeId: string, belgeSha256: string): {
  ok: boolean;
  belge_id: string | null;
  belge_sha256: string | null;
  error: string | null;
} {
  const id = belgeId.trim();
  const shaRaw = belgeSha256.trim();
  const sha = shaRaw.toLowerCase();
  if (!id && !shaRaw) {
    return { ok: true, belge_id: null, belge_sha256: null, error: null };
  }
  if ((!id && shaRaw) || (id && !shaRaw)) {
    return {
      ok: false,
      belge_id: null,
      belge_sha256: null,
      error: "Karar Belge ID ve SHA256 birlikte doldurulmalıdır."
    };
  }
  if (id.length > 160) {
    return { ok: false, belge_id: null, belge_sha256: null, error: "Karar Belge ID en fazla 160 karakter olabilir." };
  }
  if (PLACEHOLDER_HASHES.has(sha) || !/^[0-9a-f]{64}$/.test(sha)) {
    return {
      ok: false,
      belge_id: null,
      belge_sha256: null,
      error: "Karar Belge SHA256 exact 64 hexadecimal karakter olmalıdır."
    };
  }
  return { ok: true, belge_id: id, belge_sha256: sha, error: null };
}

export function evidenceStatusLabel(status: SirketPolitikaEvidenceStatus | string | null | undefined): string {
  switch (status) {
    case "PRESENT_VALID":
      return "Kanıt geçerli";
    case "MISSING":
      return "Kanıt eksik";
    case "LEGACY_MISSING":
      return "Tarihsel kayıt — kanıt alanı migration öncesinde bulunmuyordu";
    case "INVALID":
      return "Kanıt geçersiz";
    default:
      return "Kanıt durumu bilinmiyor";
  }
}

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

export async function createSirketPolitikaDraft(payload: SirketPolitikaDraftPayload) {
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

export async function updateSirketPolitikaDraft(id: number, payload: SirketPolitikaDraftPayload) {
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

export type SirketPolitikaKararOzeti = {
  politika_id: number;
  revision_no: number;
  state: string;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  policy_version_hash: string | null;
  belge_id: string | null;
  belge_sha256: string | null;
  evidence_status: string;
  evidence_ready_for_approval: boolean;
  hazirlayan_id?: number | null;
  zorunlu_parametreler: string[];
  eksik_parametreler: string[];
  onceki_onayli: Record<string, unknown> | null;
  etkilenen_donem_ipucu: string;
  etkilenen_personel_sayisi: number;
  aday_snapshot_etki_notu: string;
  katalog_ornek_bicim: Array<{
    parametre_kodu: string;
    etiket: string;
    deger_tipi: string;
    birim?: string | null;
    ornek_bicim: string;
  }>;
};

export async function fetchSirketPolitikaKararOzeti(id: number, subeId?: number | null) {
  const path = appendQueryParams(endpoints.sirketCalismaPolitikalari.kararOzeti(id), {
    ...(subeId ? { sube_id: subeId } : {})
  });
  const response = await apiRequest<ApiResponse<SirketPolitikaKararOzeti> | SirketPolitikaKararOzeti>(path);
  return unwrapData(response, "Politika karar ozeti alinamadi.");
}
