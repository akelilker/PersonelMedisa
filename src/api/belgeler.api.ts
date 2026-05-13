import type { ApiResponse } from "../types/api";
import type { BelgeDurum, BelgeDurumuItem, BelgeTuru } from "../types/belgeler";
import { BELGE_TURU_KEYS } from "../types/belgeler";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";

function isBelgeTuru(value: unknown): value is BelgeTuru {
  return typeof value === "string" && (BELGE_TURU_KEYS as readonly string[]).includes(value);
}

function isBelgeDurum(value: unknown): value is BelgeDurum {
  return value === "VAR" || value === "YOK";
}

function normalizeBelgeDurumuItems(data: unknown): BelgeDurumuItem[] {
  if (typeof data !== "object" || data === null) {
    throw new Error("Belge durumu yaniti beklenen formatta degil.");
  }

  const record = data as Record<string, unknown>;
  const rawItems = record.items;
  if (!Array.isArray(rawItems)) {
    throw new Error("Belge durumu items alani eksik.");
  }

  const byTur = new Map<BelgeTuru, BelgeDurum>();
  for (const row of rawItems) {
    if (typeof row !== "object" || row === null) {
      continue;
    }
    const r = row as Record<string, unknown>;
    const tur = r.belge_turu ?? r.belgeTuru;
    const durum = r.durum;
    if (isBelgeTuru(tur) && isBelgeDurum(durum)) {
      byTur.set(tur, durum);
    }
  }

  return BELGE_TURU_KEYS.map((belge_turu) => ({
    belge_turu,
    durum: byTur.get(belge_turu) ?? "YOK"
  }));
}

export async function fetchPersonelBelgeDurumu(personelId: number | string): Promise<BelgeDurumuItem[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.belgeDurumu(personelId));
  return normalizeBelgeDurumuItems(response.data);
}

export async function putPersonelBelgeDurumu(
  personelId: number | string,
  items: BelgeDurumuItem[]
): Promise<BelgeDurumuItem[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.belgeDurumu(personelId), {
    method: "PUT",
    body: JSON.stringify({ items })
  });
  return normalizeBelgeDurumuItems(response.data);
}
