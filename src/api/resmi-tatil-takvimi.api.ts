import type { ApiResponse } from "../types/api";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

export type ResmiTatilGunKapsami = "TAM_GUN" | "YARIM_GUN";
export type ResmiTatilDurum = "TASLAK" | "AKTIF" | "IPTAL";
export type ResmiTatilTuru = "UBGT" | "DIGER";

export type ResmiTatilTakvimKaydi = {
  id: number;
  tarih: string;
  tatil_kodu: string;
  tatil_adi: string;
  tatil_turu: ResmiTatilTuru;
  gun_kapsami: ResmiTatilGunKapsami;
  tatil_interval_baslangic: string | null;
  tatil_interval_bitis: string | null;
  durum: ResmiTatilDurum;
  kaynak_turu: string;
  kaynak_referansi: string;
  kaynak_tarihi: string | null;
  aciklama: string | null;
  revizyon_no: number;
  onceki_kayit_id: number | null;
  yapan_ad?: string | null;
  iptal_gerekcesi?: string | null;
};

export type ResmiTatilSiniflandirmaOzet = {
  toplam_ubgt_satiri: number;
  tam_gun: number;
  yarim_gun: number;
  bilinmiyor: number;
  cakisma: number;
  kaynak_eksik: number;
  ht_ubgt: number;
  muhurlu: number;
  muhursuz: number;
  policy_activation_blocker: number;
};

export type ResmiTatilEnvanterOzet = {
  yil: number;
  ay: number;
  donem?: string;
  toplam: number;
  aktif: number;
  taslak: number;
  iptal: number;
  aktif_ubgt_tam_gun: number;
  aktif_ubgt_yarim_gun: number;
  siniflandirma?: ResmiTatilSiniflandirmaOzet;
};

export type ResmiTatilUpsertPayload = {
  tarih: string;
  tatil_kodu: string;
  tatil_adi: string;
  tatil_turu?: ResmiTatilTuru;
  gun_kapsami: ResmiTatilGunKapsami;
  tatil_interval_baslangic?: string | null;
  tatil_interval_bitis?: string | null;
  kaynak_turu: string;
  kaynak_referansi: string;
  kaynak_tarihi?: string | null;
  aciklama?: string | null;
};

export type ResmiTatilListFilters = {
  durum?: ResmiTatilDurum | "";
  tatil_turu?: ResmiTatilTuru | "";
  gun_kapsami?: ResmiTatilGunKapsami | "";
  tarih_bas?: string;
  tarih_bit?: string;
};

export type ResmiTatilHistoryAudit = {
  id: number;
  kayit_id: number;
  aksiyon: string;
  actor_id: number | null;
  actor_rol: string | null;
  actor_ad: string | null;
  request_hash: string | null;
  created_at: string;
};

export type ResmiTatilHistoryResponse = {
  items: ResmiTatilTakvimKaydi[];
  auditler: ResmiTatilHistoryAudit[];
};

export type ResmiTatilProjectionPreview = {
  tarih_bas: string;
  tarih_bit: string;
  preview_modu: string;
  read_only: true;
  policy_aktif_degil: true;
  toplam_satir: number;
  dogrulandi: number;
  kaynak_eksik: number;
  cakisma: number;
  bilinmiyor: number;
  tam_gun: number;
  yarim_gun: number;
  ht_ubgt: number;
  interval_olcumu_eksik: number;
  policy_blocker: number;
  muhurlu: number;
  muhursuz: number;
  muhur_projection_eksik: number;
  tam_gun_aktivasyona_hazir: number;
  yarim_gun_odeme_politikasi_bekliyor: number;
  genel_sistem_hazir: false;
};

function unwrapData<T>(payload: ApiResponse<T> | T): T {
  if (typeof payload === "object" && payload !== null && "data" in payload) {
    return (payload as ApiResponse<T>).data;
  }
  return payload as T;
}

export async function fetchResmiTatilTakvimiList(
  filters: ResmiTatilListFilters = {}
): Promise<ResmiTatilTakvimKaydi[]> {
  const path = appendQueryParams(endpoints.resmiTatilTakvimi.list, {
    durum: filters.durum || undefined,
    tatil_turu: filters.tatil_turu || undefined,
    gun_kapsami: filters.gun_kapsami || undefined,
    tarih_bas: filters.tarih_bas || undefined,
    tarih_bit: filters.tarih_bit || undefined
  });
  const response = await apiRequest<ApiResponse<{ items: ResmiTatilTakvimKaydi[] }> | { items: ResmiTatilTakvimKaydi[] }>(
    path
  );
  return unwrapData(response).items ?? [];
}

export async function fetchResmiTatilTakvimiDetail(id: number): Promise<ResmiTatilTakvimKaydi> {
  const response = await apiRequest<ApiResponse<ResmiTatilTakvimKaydi> | ResmiTatilTakvimKaydi>(
    endpoints.resmiTatilTakvimi.detail(id)
  );
  return unwrapData(response);
}

export async function createResmiTatilTakvimi(
  payload: ResmiTatilUpsertPayload
): Promise<ResmiTatilTakvimKaydi> {
  const response = await apiRequest<ApiResponse<ResmiTatilTakvimKaydi> | ResmiTatilTakvimKaydi>(
    endpoints.resmiTatilTakvimi.create,
    { method: "POST", body: JSON.stringify(payload) }
  );
  return unwrapData(response);
}

export async function updateResmiTatilTakvimi(
  id: number,
  payload: ResmiTatilUpsertPayload
): Promise<ResmiTatilTakvimKaydi> {
  const response = await apiRequest<ApiResponse<ResmiTatilTakvimKaydi> | ResmiTatilTakvimKaydi>(
    endpoints.resmiTatilTakvimi.update(id),
    { method: "PUT", body: JSON.stringify(payload) }
  );
  return unwrapData(response);
}

export async function activateResmiTatilTakvimi(id: number): Promise<ResmiTatilTakvimKaydi> {
  const response = await apiRequest<ApiResponse<ResmiTatilTakvimKaydi> | ResmiTatilTakvimKaydi>(
    endpoints.resmiTatilTakvimi.activate(id),
    { method: "POST", body: JSON.stringify({}) }
  );
  return unwrapData(response);
}

export async function reviseResmiTatilTakvimi(
  id: number,
  payload: ResmiTatilUpsertPayload & { iptal_gerekcesi: string }
): Promise<ResmiTatilTakvimKaydi> {
  const response = await apiRequest<ApiResponse<ResmiTatilTakvimKaydi> | ResmiTatilTakvimKaydi>(
    endpoints.resmiTatilTakvimi.revise(id),
    { method: "POST", body: JSON.stringify(payload) }
  );
  return unwrapData(response);
}

export async function cancelResmiTatilTakvimi(
  id: number,
  iptal_gerekcesi: string
): Promise<ResmiTatilTakvimKaydi> {
  const response = await apiRequest<ApiResponse<ResmiTatilTakvimKaydi> | ResmiTatilTakvimKaydi>(
    endpoints.resmiTatilTakvimi.cancel(id),
    { method: "POST", body: JSON.stringify({ iptal_gerekcesi }) }
  );
  return unwrapData(response);
}

export async function fetchResmiTatilHistory(id: number): Promise<ResmiTatilHistoryResponse> {
  const response = await apiRequest<ApiResponse<ResmiTatilHistoryResponse> | ResmiTatilHistoryResponse>(
    endpoints.resmiTatilTakvimi.history(id)
  );
  return unwrapData(response);
}

export async function previewResmiTatilProjection(payload: {
  tarih?: string;
  tarih_bas?: string;
  tarih_bit?: string;
  sube_id?: number;
  personel_id?: number;
  preview_modu?: string;
}): Promise<ResmiTatilProjectionPreview> {
  const response = await apiRequest<ApiResponse<ResmiTatilProjectionPreview> | ResmiTatilProjectionPreview>(
    endpoints.resmiTatilTakvimi.projectionPreview,
    { method: "POST", body: JSON.stringify(payload) }
  );
  return unwrapData(response);
}

export async function fetchResmiTatilEnvanterOzet(
  yil: number,
  ay: number
): Promise<ResmiTatilEnvanterOzet> {
  const response = await apiRequest<ApiResponse<ResmiTatilEnvanterOzet> | ResmiTatilEnvanterOzet>(
    `${endpoints.resmiTatilTakvimi.envanterOzet}?yil=${yil}&ay=${ay}`
  );
  return unwrapData(response);
}
