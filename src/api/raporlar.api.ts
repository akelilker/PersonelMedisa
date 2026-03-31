import type { ApiMeta, ApiResponse } from "../types/api";
import type { RaporFiltreleri, RaporSatiri, RaporSonuc, RaporTipi } from "../types/rapor";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

const RAPOR_ENDPOINTS: Record<RaporTipi, string> = {
  "personel-ozet": endpoints.raporlar.personelOzet,
  izin: endpoints.raporlar.izin,
  devamsizlik: endpoints.raporlar.devamsizlik,
  tesvik: endpoints.raporlar.tesvik,
  ceza: endpoints.raporlar.ceza,
  "ekstra-prim": endpoints.raporlar.ekstraPrim,
  "is-kazasi": endpoints.raporlar.isKazasi,
  bildirim: endpoints.raporlar.bildirim
};

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function toNumber(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return null;
}

function normalizeRows(data: unknown): RaporSatiri[] {
  if (Array.isArray(data)) {
    return data.map((item) => toRecord(item) ?? { value: item });
  }

  const listItems = extractListItems<unknown>(data);
  if (listItems.length > 0) {
    return listItems.map((item) => toRecord(item) ?? { value: item });
  }

  const record = toRecord(data);
  if (record) {
    return [record];
  }

  return [];
}

function readTotal(meta: ApiMeta, data: unknown, rows: RaporSatiri[]): number | null {
  const metaRecord = toRecord(meta);
  const dataRecord = toRecord(data);

  return (
    toNumber(metaRecord?.total) ??
    toNumber(metaRecord?.total_count) ??
    toNumber(dataRecord?.total) ??
    toNumber(dataRecord?.total_count) ??
    rows.length
  );
}

export async function fetchRapor(
  raporTipi: RaporTipi,
  filters?: RaporFiltreleri
): Promise<RaporSonuc> {
  const path = appendQueryParams(RAPOR_ENDPOINTS[raporTipi], {
    personel_id: filters?.personel_id,
    departman_id: filters?.departman_id,
    baslangic_tarihi: filters?.baslangic_tarihi,
    bitis_tarihi: filters?.bitis_tarihi,
    aktiflik: filters?.aktiflik
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const rows = normalizeRows(response.data);

  return {
    rows,
    total: readTotal(response.meta, response.data, rows)
  };
}
