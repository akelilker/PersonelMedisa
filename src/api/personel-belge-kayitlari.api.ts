import type { ApiResponse, PaginatedResult } from "../types/api";
import {
  computeGecerlilikDurumu,
  PERSONEL_BELGE_KAYIT_TIPI_KEYS,
  type CreatePersonelBelgeKaydiPayload,
  type PersonelBelgeKaydi,
  type PersonelBelgeKayitDurum,
  type PersonelBelgeKayitTipi,
  type UpdatePersonelBelgeKaydiPayload
} from "../types/personel-belge-kaydi";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

function toNumber(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return undefined;
}

function toNullableString(value: unknown): string | null {
  if (value === null || value === undefined) {
    return null;
  }

  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  return trimmed || null;
}

function isPersonelBelgeKayitTipi(value: unknown): value is PersonelBelgeKayitTipi {
  return typeof value === "string" && (PERSONEL_BELGE_KAYIT_TIPI_KEYS as readonly string[]).includes(value);
}

function isPersonelBelgeKayitDurum(value: unknown): value is PersonelBelgeKayitDurum {
  return value === "AKTIF" || value === "IPTAL";
}

export function normalizePersonelBelgeKaydi(data: unknown): PersonelBelgeKaydi {
  if (typeof data !== "object" || data === null) {
    throw new Error("Belge kaydi yaniti beklenen formatta degil.");
  }

  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const personelId = toNumber(record.personel_id ?? record.personelId);
  const kayitTipiRaw = record.kayit_tipi ?? record.kayitTipi;
  const ad = toNullableString(record.ad);

  if (!id || !personelId || !isPersonelBelgeKayitTipi(kayitTipiRaw) || !ad) {
    throw new Error("Belge kaydi yaniti eksik alan iceriyor.");
  }

  const bitisTarihi = toNullableString(record.bitis_tarihi ?? record.bitisTarihi);
  const durumRaw = record.durum ?? record.state;
  const durum: PersonelBelgeKayitDurum = isPersonelBelgeKayitDurum(durumRaw) ? durumRaw : "AKTIF";
  const gecerlilik_durumu = computeGecerlilikDurumu(bitisTarihi);

  return {
    id,
    personel_id: personelId,
    kayit_tipi: kayitTipiRaw,
    ad,
    veren_kurum: toNullableString(record.veren_kurum ?? record.verenKurum),
    belge_no: toNullableString(record.belge_no ?? record.belgeNo),
    baslangic_tarihi: toNullableString(record.baslangic_tarihi ?? record.baslangicTarihi),
    bitis_tarihi: bitisTarihi,
    durum,
    gecerlilik_durumu,
    ek_ref: toNullableString(record.ek_ref ?? record.ekRef),
    aciklama: toNullableString(record.aciklama),
    created_at: toNullableString(record.created_at ?? record.createdAt),
    updated_at: toNullableString(record.updated_at ?? record.updatedAt)
  };
}

export async function fetchPersonelBelgeKayitlari(
  personelId: number | string,
  params?: { state?: PersonelBelgeKayitDurum | "tum"; page?: number; limit?: number }
): Promise<PaginatedResult<PersonelBelgeKaydi>> {
  const searchParams = new URLSearchParams();
  if (params?.state && params.state !== "tum") {
    searchParams.set("state", params.state);
  }
  if (params?.page) {
    searchParams.set("page", String(params.page));
  }
  if (params?.limit) {
    searchParams.set("limit", String(params.limit));
  }

  const query = searchParams.toString();
  const basePath = endpoints.personelBelgeKayitlari.listByPersonel(personelId);
  const path = query ? `${basePath}?${query}` : basePath;

  const response = await apiRequest<ApiResponse<unknown>>(path);
  const normalized = normalizePaginatedList<unknown>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });

  return {
    items: normalized.items.map((item) => normalizePersonelBelgeKaydi(item)),
    pagination: normalized.pagination
  };
}

export async function createPersonelBelgeKaydi(
  personelId: number | string,
  payload: CreatePersonelBelgeKaydiPayload
): Promise<PersonelBelgeKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.personelBelgeKayitlari.create(personelId),
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  const created = normalizePersonelBelgeKaydi(response.data);
  logAction({
    action: "PERSONEL_BELGE_KAYDI_CREATE",
    payload: { belge_kaydi_id: created.id, personel_id: created.personel_id }
  });
  return created;
}

export async function updatePersonelBelgeKaydi(
  id: number | string,
  payload: UpdatePersonelBelgeKaydiPayload
): Promise<PersonelBelgeKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personelBelgeKayitlari.detail(id), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  return normalizePersonelBelgeKaydi(response.data);
}

export async function cancelPersonelBelgeKaydi(id: number | string): Promise<PersonelBelgeKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personelBelgeKayitlari.cancel(id), {
    method: "POST"
  });
  return normalizePersonelBelgeKaydi(response.data);
}
