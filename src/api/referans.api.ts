import type { ApiResponse } from "../types/api";
import type { IdOption, KeyOption } from "../types/referans";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

function getObjectLabel(item: Record<string, unknown>) {
  const candidates = ["ad", "adi", "name", "label", "title", "kod", "code", "key", "value"];
  for (const field of candidates) {
    const value = item[field];
    if (typeof value === "string" && value.trim().length > 0) {
      return value;
    }
  }

  return null;
}

function normalizeIdOptions(data: unknown, parentKey?: string): IdOption[] {
  const entries = extractListItems<unknown>(data);
  const normalizedEntries =
    entries.length > 0 ? entries : typeof data === "object" && data !== null ? [data] : [];
  if (normalizedEntries.length === 0) {
    return [];
  }

  return normalizedEntries
    .map((entry) => {
      if (typeof entry !== "object" || entry === null) {
        return null;
      }

      const item = entry as Record<string, unknown>;
      const rawId = item.id;
      const id = typeof rawId === "number" ? rawId : Number.parseInt(String(rawId ?? ""), 10);
      if (Number.isNaN(id) || id <= 0) {
        return null;
      }

      const label = getObjectLabel(item) ?? String(id);
      const option: IdOption = { id, label };
      if (parentKey) {
        const rawParent = item[parentKey];
        const parentId =
          typeof rawParent === "number"
            ? rawParent
            : Number.parseInt(String(rawParent ?? ""), 10);
        if (Number.isFinite(parentId) && parentId > 0) {
          option.parentId = parentId;
        }
      }
      return option;
    })
    .filter((item): item is IdOption => item !== null);
}

function normalizeKeyOptions(data: unknown): KeyOption[] {
  const entries = extractListItems<unknown>(data);
  const normalizedEntries =
    entries.length > 0 ? entries : typeof data === "object" && data !== null ? [data] : [];
  if (normalizedEntries.length === 0) {
    return [];
  }

  return normalizedEntries
    .map((entry) => {
      if (typeof entry === "string" && entry.trim().length > 0) {
        return { key: entry, label: entry };
      }

      if (typeof entry !== "object" || entry === null) {
        return null;
      }

      const item = entry as Record<string, unknown>;
      const label = getObjectLabel(item);
      if (!label) {
        return null;
      }

      const rawKey = item.kod ?? item.code ?? item.key ?? item.value ?? label;
      const key = String(rawKey).trim();
      if (!key) {
        return null;
      }

      return {
        key,
        label
      };
    })
    .filter((item): item is KeyOption => item !== null);
}

export async function fetchDepartmanOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.departmanlar);
  return normalizeIdOptions(response.data);
}

export async function createDepartmanOption(ad: string): Promise<IdOption> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.departmanlar, {
    method: "POST",
    body: JSON.stringify({ ad })
  });

  const items = normalizeIdOptions(response.data);
  if (items.length === 0) {
    throw new Error("Departman kaydı oluşturuldu ama yanıt beklenen formatta değil.");
  }

  return items[0];
}

export async function fetchGorevOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.gorevler);
  return normalizeIdOptions(response.data);
}

export async function fetchBolumOptions(departmanId?: number): Promise<IdOption[]> {
  try {
    const path =
      departmanId && departmanId > 0
        ? `${endpoints.referans.bolumler}?departman_id=${departmanId}`
        : endpoints.referans.bolumler;
    const response = await apiRequest<ApiResponse<unknown>>(path);
    return normalizeIdOptions(response.data, "departman_id");
  } catch {
    return [];
  }
}

export async function fetchBirimOptions(bolumId?: number): Promise<IdOption[]> {
  try {
    const path =
      bolumId && bolumId > 0
        ? `${endpoints.referans.birimler}?bolum_id=${bolumId}`
        : endpoints.referans.birimler;
    const response = await apiRequest<ApiResponse<unknown>>(path);
    return normalizeIdOptions(response.data, "bolum_id");
  } catch {
    return [];
  }
}

export async function fetchPozisyonOptions(): Promise<IdOption[]> {
  try {
    const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.pozisyonlar);
    return normalizeIdOptions(response.data);
  } catch {
    return [];
  }
}

export async function createGorevOption(ad: string): Promise<IdOption> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.gorevler, {
    method: "POST",
    body: JSON.stringify({ ad })
  });

  const items = normalizeIdOptions(response.data);
  if (items.length === 0) {
    throw new Error("Görev kaydı oluşturuldu ama yanıt beklenen formatta değil.");
  }

  return items[0];
}

export async function fetchPersonelTipiOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.personelTipleri);
  return normalizeIdOptions(response.data);
}

export async function fetchBagliAmirOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.bagliAmirler);
  return normalizeIdOptions(response.data);
}

export async function fetchUcretTipiOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.ucretTipleri);
  return normalizeIdOptions(response.data);
}

export async function fetchPrimKuraliOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.primKurallari);
  return normalizeIdOptions(response.data);
}

export async function fetchSurecTuruOptions(): Promise<KeyOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.surecTurleri);
  return normalizeKeyOptions(response.data);
}

export async function fetchBildirimTuruOptions(): Promise<KeyOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.bildirimTurleri);
  return normalizeKeyOptions(response.data);
}
