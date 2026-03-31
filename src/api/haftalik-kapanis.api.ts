import type { ApiResponse } from "../types/api";
import type { HaftalikKapanisPayload, HaftalikKapanisSonuc } from "../types/haftalik-kapanis";
import { apiRequest } from "./client";
import { endpoints } from "./endpoints";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function toOptionalNumber(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    if (!trimmed) {
      return undefined;
    }

    const parsed = Number.parseInt(trimmed, 10);
    return Number.isNaN(parsed) ? undefined : parsed;
  }

  return undefined;
}

function toOptionalString(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : undefined;
}

function normalizeHaftalikKapanisSonuc(data: unknown): HaftalikKapanisSonuc {
  const record = toRecord(data) ?? {};

  return {
    ...record,
    id: toOptionalNumber(record.id),
    hafta_baslangic:
      toOptionalString(record.hafta_baslangic) ?? toOptionalString(record.week_start),
    hafta_bitis: toOptionalString(record.hafta_bitis) ?? toOptionalString(record.week_end),
    departman_id: toOptionalNumber(record.departman_id),
    state: toOptionalString(record.state) ?? toOptionalString(record.durum)
  };
}

export async function createHaftalikKapanis(
  payload: HaftalikKapanisPayload
): Promise<HaftalikKapanisSonuc> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.haftalikKapanis.close, {
    method: "POST",
    body: JSON.stringify(payload)
  });

  return normalizeHaftalikKapanisSonuc(response.data);
}
