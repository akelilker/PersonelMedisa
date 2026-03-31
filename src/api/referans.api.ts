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

function normalizeIdOptions(data: unknown): IdOption[] {
  const entries = extractListItems<unknown>(data);
  if (entries.length === 0) {
    return [];
  }

  return entries
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
      return { id, label };
    })
    .filter((item): item is IdOption => item !== null);
}

function normalizeKeyOptions(data: unknown): KeyOption[] {
  const entries = extractListItems<unknown>(data);
  if (entries.length === 0) {
    return [];
  }

  return entries
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

export async function fetchGorevOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.gorevler);
  return normalizeIdOptions(response.data);
}

export async function fetchPersonelTipiOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.personelTipleri);
  return normalizeIdOptions(response.data);
}

export async function fetchBagliAmirOptions(): Promise<IdOption[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.referans.bagliAmirler);
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
