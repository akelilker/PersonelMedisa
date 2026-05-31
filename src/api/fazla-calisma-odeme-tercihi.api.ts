import type { ApiResponse } from "../types/api";
import type {
  FazlaCalismaOdemeTercihi,
  OdemeTipi,
  PutFazlaCalismaOdemeTercihiPayload
} from "../types/fazla-calisma-odeme-tercihi";
import {
  DEFAULT_ODEME_TIPI,
  ODEME_TIPI_VALUES
} from "../types/fazla-calisma-odeme-tercihi";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest } from "./api-client";
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

function toNonNegativeNumber(value: unknown, fallback = 0): number {
  const parsed = toOptionalNumber(value);
  if (parsed === undefined || parsed < 0) {
    return fallback;
  }

  return parsed;
}

function toOptionalString(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : undefined;
}

function isOdemeTipi(value: unknown): value is OdemeTipi {
  return (
    typeof value === "string" &&
    (ODEME_TIPI_VALUES as readonly string[]).includes(value)
  );
}

function normalizeOdemeTipi(value: unknown, fallback: OdemeTipi = DEFAULT_ODEME_TIPI): OdemeTipi {
  if (isOdemeTipi(value)) {
    return value;
  }

  return fallback;
}

export function normalizeFazlaCalismaOdemeTercihi(data: unknown): FazlaCalismaOdemeTercihi {
  const record = toRecord(data);
  if (!record) {
    throw new ApiRequestError("Odeme tercihi yaniti beklenen formatta degil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  const snapshot_id = toOptionalNumber(record.snapshot_id);
  const kapanis_id = toOptionalNumber(record.kapanis_id);
  const personel_id = toOptionalNumber(record.personel_id);
  const hafta_baslangic = toOptionalString(record.hafta_baslangic);
  const hafta_bitis = toOptionalString(record.hafta_bitis);

  if (
    snapshot_id === undefined ||
    kapanis_id === undefined ||
    personel_id === undefined ||
    !hafta_baslangic ||
    !hafta_bitis
  ) {
    throw new ApiRequestError("Odeme tercihi yaniti eksik alan iceriyor.", 400, {
      code: "INVALID_RESPONSE"
    });
  }

  const id = toOptionalNumber(record.id);
  const secen_kullanici_id = toOptionalNumber(record.secen_kullanici_id);
  const onceki_odeme_tipiRaw = record.onceki_odeme_tipi;
  const onceki_odeme_tipi = isOdemeTipi(onceki_odeme_tipiRaw) ? onceki_odeme_tipiRaw : undefined;

  return {
    id,
    snapshot_id,
    kapanis_id,
    personel_id,
    hafta_baslangic,
    hafta_bitis,
    fazla_calisma_dakika: toNonNegativeNumber(record.fazla_calisma_dakika),
    odeme_tipi: normalizeOdemeTipi(record.odeme_tipi),
    secim_zamani: toOptionalString(record.secim_zamani),
    secen_kullanici_id,
    onceki_odeme_tipi,
    gerekce: toOptionalString(record.gerekce)
  };
}

function parsePositiveIntParam(value: number | string, field: string): number {
  const parsed =
    typeof value === "number" ? value : Number.parseInt(String(value).trim(), 10);

  if (!Number.isFinite(parsed) || parsed < 1) {
    throw new ApiRequestError(`${field} gecersiz.`, 400, { code: "INVALID_QUERY", field });
  }

  return parsed;
}

function throwFirstApiError(
  errors: ApiResponse<unknown>["errors"],
  fallbackMessage: string,
  fallbackStatus = 400
): never {
  const first = errors?.[0];
  const code = typeof first?.code === "string" ? first.code : "INVALID_REQUEST";
  const status = code === "NOT_FOUND" ? 404 : fallbackStatus;

  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallbackMessage,
    status,
    { code }
  );
}

export async function fetchFazlaCalismaOdemeTercihi(
  snapshotId: number | string
): Promise<FazlaCalismaOdemeTercihi> {
  const snapshot_id = parsePositiveIntParam(snapshotId, "snapshot_id");
  const path = appendQueryParams(endpoints.fazlaCalismaOdemeTercihi.resource, {
    snapshot_id
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Odeme tercihi alinamadi.");
  }

  return normalizeFazlaCalismaOdemeTercihi(response.data);
}

export async function putFazlaCalismaOdemeTercihi(
  payload: PutFazlaCalismaOdemeTercihiPayload
): Promise<FazlaCalismaOdemeTercihi> {
  const snapshot_id = parsePositiveIntParam(payload.snapshot_id, "snapshot_id");

  if (!isOdemeTipi(payload.odeme_tipi)) {
    throw new ApiRequestError("odeme_tipi gecersiz.", 400, { code: "INVALID_BODY", field: "odeme_tipi" });
  }

  const response = await apiRequest<ApiResponse<unknown>>(endpoints.fazlaCalismaOdemeTercihi.resource, {
    method: "PUT",
    body: JSON.stringify({
      snapshot_id,
      odeme_tipi: payload.odeme_tipi,
      gerekce: payload.gerekce,
      secen_kullanici_id: payload.secen_kullanici_id
    })
  });

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstApiError(response.errors, "Odeme tercihi kaydedilemedi.");
  }

  return normalizeFazlaCalismaOdemeTercihi(response.data);
}
