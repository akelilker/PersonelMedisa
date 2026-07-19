import type { ApiResponse, PaginatedResult } from "../types/api";
import type {
  Bildirim,
  BirimAmiriSecenegi,
  GunlukBildirimTamamlama,
  GunlukOzet
} from "../types/bildirim";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type BildirimlerListParams = {
  tarih?: string;
  baslangic_tarihi?: string;
  bitis_tarihi?: string;
  departman_id?: number;
  personel_id?: number;
  bildirim_turu?: string;
  state?: string;
  sube_id?: number;
  page?: number;
  limit?: number;
};

export type CreateBildirimPayload = {
  tarih: string;
  departman_id?: number;
  personel_id: number;
  bildirim_turu: string;
  aciklama?: string;
  alt_tur?: string | null;
  baslangic_saati?: string | null;
  bitis_saati?: string | null;
  dakika?: number | null;
};

export type UpdateBildirimPayload = {
  bildirim_turu?: string;
  alt_tur?: string | null;
  baslangic_saati?: string | null;
  bitis_saati?: string | null;
  dakika?: number | null;
  aciklama?: string | null;
  okundu_mi?: boolean;
};

export type RequestBildirimCorrectionPayload = {
  correction_reason: string;
};

export type CompleteGunlukTamamlamaPayload = {
  tarih: string;
  not_metni?: string;
};

export async function fetchBirimAmiriSecenekleri(subeId: number): Promise<BirimAmiriSecenegi[]> {
  const path = appendQueryParams(endpoints.bildirimler.birimAmiriSecenekleri, { sube_id: subeId });
  const response = await apiRequest<ApiResponse<{ items: BirimAmiriSecenegi[] }>>(path);
  return Array.isArray(response.data.items) ? response.data.items : [];
}

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

function normalizeGunlukOzet(data: unknown): GunlukOzet {
  if (typeof data !== "object" || data === null) {
    throw new Error("Gunluk ozet yaniti beklenen formatta degil.");
  }
  return data as GunlukOzet;
}

function normalizeTamamlama(data: unknown): GunlukBildirimTamamlama {
  if (typeof data !== "object" || data === null) {
    throw new Error("Gunluk tamamlama yaniti beklenen formatta degil.");
  }
  const row = data as Partial<GunlukBildirimTamamlama>;
  if (typeof row.id !== "number" || typeof row.state !== "string") {
    throw new Error("Gunluk tamamlama yaniti eksik alan iceriyor.");
  }
  return row as GunlukBildirimTamamlama;
}

export async function fetchBildirimlerList(
  params?: BildirimlerListParams
): Promise<PaginatedResult<Bildirim>> {
  const path = appendQueryParams(endpoints.bildirimler.list, {
    tarih: params?.tarih,
    baslangic_tarihi: params?.baslangic_tarihi,
    bitis_tarihi: params?.bitis_tarihi,
    departman_id: params?.departman_id,
    personel_id: params?.personel_id,
    bildirim_turu: params?.bildirim_turu,
    state: params?.state,
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

export async function fetchGunlukOzet(params: {
  tarih: string;
  sube_id?: number;
  birim_amiri_user_id?: number;
}): Promise<GunlukOzet> {
  const path = appendQueryParams(endpoints.bildirimler.gunlukOzet, {
    tarih: params.tarih,
    sube_id: params.sube_id,
    birim_amiri_user_id: params.birim_amiri_user_id
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizeGunlukOzet(response.data);
}

export async function fetchGunlukTamamlama(params: {
  tarih: string;
  sube_id?: number;
  birim_amiri_user_id?: number;
}): Promise<{
  tarih: string;
  sube_id: number;
  birim_amiri_user_id: number;
  tamamlama: GunlukBildirimTamamlama | null;
}> {
  const path = appendQueryParams(endpoints.bildirimler.gunlukTamamlama, {
    tarih: params.tarih,
    sube_id: params.sube_id,
    birim_amiri_user_id: params.birim_amiri_user_id
  });
  const response = await apiRequest<
    ApiResponse<{
      tarih: string;
      sube_id: number;
      birim_amiri_user_id: number;
      tamamlama: GunlukBildirimTamamlama | null;
    }>
  >(path);
  return response.data;
}

export async function completeGunlukTamamlama(
  payload: CompleteGunlukTamamlamaPayload
): Promise<GunlukBildirimTamamlama> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.bildirimler.gunlukTamamlama, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  const created = normalizeTamamlama(response.data);
  return created;
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
  await apiRequest<ApiResponse<unknown>>(endpoints.bildirimler.detail(bildirimId) + "/iptal", {
    method: "POST"
  });
  logAction({ action: "BILDIRIM_CANCEL", payload: { bildirim_id: bildirimId } });
}

export async function submitBildirim(bildirimId: number | string): Promise<Bildirim> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.bildirimler.submit(bildirimId), {
    method: "POST"
  });
  const submitted = normalizeBildirim(response.data);
  logAction({ action: "BILDIRIM_SUBMIT", payload: { bildirim_id: submitted.id } });
  return submitted;
}

export async function requestBildirimCorrection(
  bildirimId: number | string,
  payload: RequestBildirimCorrectionPayload
): Promise<Bildirim> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.bildirimler.requestCorrection(bildirimId),
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  const corrected = normalizeBildirim(response.data);
  logAction({
    action: "BILDIRIM_REQUEST_CORRECTION",
    payload: { bildirim_id: corrected.id }
  });
  return corrected;
}

export async function markBildirimOkundu(bildirimId: number | string): Promise<Bildirim> {
  return updateBildirim(bildirimId, { okundu_mi: true });
}
