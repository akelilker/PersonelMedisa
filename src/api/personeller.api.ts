import type { ApiResponse, PaginatedResult } from "../types/api";
import type { Personel } from "../types/personel";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type PersonellerListParams = {
  search?: string;
  departman_id?: number;
  aktiflik?: "aktif" | "pasif" | "tum";
  personel_tipi_id?: number;
  page?: number;
  limit?: number;
};

export type CreatePersonelPayload = {
  tc_kimlik_no: string;
  ad: string;
  soyad: string;
  dogum_tarihi: string;
  telefon: string;
  acil_durum_kisi: string;
  acil_durum_telefon: string;
  sicil_no: string;
  ise_giris_tarihi: string;
  departman_id: number;
  gorev_id: number;
  personel_tipi_id: number;
  aktif_durum: "AKTIF" | "PASIF";
};

export type UpdatePersonelPayload = Partial<CreatePersonelPayload>;

function normalizePersonel(data: unknown): Personel {
  if (typeof data !== "object" || data === null) {
    throw new Error("Personel yaniti beklenen formatta degil.");
  }

  const personel = data as Partial<Personel>;
  if (
    typeof personel.id !== "number" ||
    typeof personel.ad !== "string" ||
    typeof personel.soyad !== "string" ||
    typeof personel.aktif_durum !== "string" ||
    typeof personel.tc_kimlik_no !== "string"
  ) {
    throw new Error("Personel yaniti eksik alan iceriyor.");
  }

  return personel as Personel;
}

export async function fetchPersonellerList(
  params?: PersonellerListParams
): Promise<PaginatedResult<Personel>> {
  const path = appendQueryParams(endpoints.personeller.list, {
    search: params?.search,
    departman_id: params?.departman_id,
    aktiflik: params?.aktiflik,
    personel_tipi_id: params?.personel_tipi_id,
    page: params?.page,
    limit: params?.limit
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizePaginatedList<Personel>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });
}

export async function createPersonel(payload: CreatePersonelPayload): Promise<Personel> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.list, {
    method: "POST",
    body: JSON.stringify(payload)
  });

  return normalizePersonel(response.data);
}

export async function fetchPersonelDetail(personelId: number | string): Promise<Personel> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.detail(personelId));
  return normalizePersonel(response.data);
}

export async function updatePersonel(
  personelId: number | string,
  payload: UpdatePersonelPayload
): Promise<Personel> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.detail(personelId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  return normalizePersonel(response.data);
}
