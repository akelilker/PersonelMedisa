import type { ApiResponse, PaginatedResult } from "../types/api";
import type {
  CreateFinansKalemPayload,
  FinansKalem,
  UpdateFinansKalemPayload
} from "../types/finans";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type FinansListParams = {
  personel_id?: number;
  donem?: string;
  kalem_turu?: string;
  state?: string;
  sube_id?: number;
  page?: number;
  limit?: number;
};

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

function toStringValue(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed || undefined;
}

function normalizeFinansKalem(data: unknown): FinansKalem {
  if (typeof data !== "object" || data === null) {
    throw new Error("Finans kalemi yaniti beklenen formatta degil.");
  }

  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const personelId = toNumber(record.personel_id ?? record.personelId);
  const donem = toStringValue(record.donem);
  const kalemTuru = toStringValue(record.kalem_turu ?? record.kalemTuru);
  const tutar = toNumber(record.tutar);

  if (!id || !personelId || !donem || !kalemTuru || tutar === undefined) {
    throw new Error("Finans kalemi yaniti eksik alan iceriyor.");
  }

  return {
    id,
    personel_id: personelId,
    donem,
    kalem_turu: kalemTuru,
    tutar,
    aciklama: toStringValue(record.aciklama),
    state: toStringValue(record.state ?? record.durum)
  };
}

export async function fetchFinansKalemList(
  params?: FinansListParams
): Promise<PaginatedResult<FinansKalem>> {
  const path = appendQueryParams(endpoints.finans.list, {
    personel_id: params?.personel_id,
    donem: params?.donem,
    kalem_turu: params?.kalem_turu,
    state: params?.state,
    sube_id: params?.sube_id,
    page: params?.page,
    limit: params?.limit
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);
  const normalized = normalizePaginatedList<unknown>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });

  return {
    items: normalized.items.map((item) => normalizeFinansKalem(item)),
    pagination: normalized.pagination
  };
}

export async function createFinansKalem(payload: CreateFinansKalemPayload): Promise<FinansKalem> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.finans.list, {
    method: "POST",
    body: JSON.stringify(payload)
  });

  const created = normalizeFinansKalem(response.data);
  logAction({ action: "FINANS_CREATE", payload: { finans_id: created.id } });
  return created;
}

export async function updateFinansKalem(
  kalemId: number | string,
  payload: UpdateFinansKalemPayload
): Promise<FinansKalem> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.finans.detail(kalemId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });

  const updated = normalizeFinansKalem(response.data);
  logAction({ action: "FINANS_UPDATE", payload: { finans_id: updated.id } });
  return updated;
}

export async function cancelFinansKalem(kalemId: number | string): Promise<void> {
  await apiRequest<ApiResponse<unknown>>(`${endpoints.finans.detail(kalemId)}/iptal`, {
    method: "POST"
  });
  logAction({ action: "FINANS_CANCEL", payload: { finans_id: kalemId } });
}
