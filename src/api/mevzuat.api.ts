import type { ApiResponse } from "../types/api";
import type {
  CreateMevzuatParametresiPayload,
  MevzuatDegerTipi,
  MevzuatDurum,
  MevzuatParametresi,
  UpdateMevzuatParametresiPayload
} from "../types/mevzuat";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { ApiRequestError, apiRequest, getApiErrorDetail } from "./api-client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

export const MEVZUAT_DATE_OVERLAP_MESSAGE =
  "Bu parametre kodu için seçilen tarih aralığında başka bir kayıt bulunmaktadır.";

const MEVZUAT_ERROR_STATUS: Record<string, number> = {
  LEGAL_PARAMETER_OVERLAP: 409,
  LEGAL_PARAMETER_OVERLAP_DATA_ERROR: 409,
  LEGAL_PARAMETER_CHANGE_FORBIDDEN: 409,
  LEGAL_PARAMETER_MISSING: 404,
  NOT_FOUND: 404,
  DATE_RANGE_INVALID: 400,
  DATE_INVALID: 400,
  VALIDATION_ERROR: 422,
  FORBIDDEN: 403
};

function throwFirstMevzuatApiError(
  errors: ApiResponse<unknown>["errors"],
  fallbackMessage: string
): never {
  const first = errors?.[0];
  const code = typeof first?.code === "string" ? first.code : "INVALID_REQUEST";
  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallbackMessage,
    MEVZUAT_ERROR_STATUS[code] ?? 400,
    { code }
  );
}

function assertNoMevzuatApiErrors(response: ApiResponse<unknown>, fallbackMessage: string): void {
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstMevzuatApiError(response.errors, fallbackMessage);
  }
}

export function getMevzuatApiErrorMessage(error: unknown, fallbackMessage: string): string {
  const detail = getApiErrorDetail(error, fallbackMessage);
  if (detail.code === "LEGAL_PARAMETER_OVERLAP") {
    return MEVZUAT_DATE_OVERLAP_MESSAGE;
  }
  return detail.message;
}

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

export function normalizeMevzuatParametresi(data: unknown): MevzuatParametresi {
  if (typeof data !== "object" || data === null) {
    throw new Error("Mevzuat parametresi yanıtı beklenen formatta değil.");
  }

  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const parametreKodu = toStringValue(record.parametre_kodu ?? record.parametreKodu);
  const gecerlilikBaslangic = toStringValue(record.gecerlilik_baslangic ?? record.gecerlilikBaslangic);

  if (!id || !parametreKodu || !gecerlilikBaslangic) {
    throw new Error("Mevzuat parametresi yanıtı eksik alan içeriyor.");
  }

  const degerTipiRaw = (toStringValue(record.deger_tipi ?? record.degerTipi) ?? "SAYISAL").toUpperCase();
  const degerTipi: MevzuatDegerTipi = degerTipiRaw === "METIN" ? "METIN" : "SAYISAL";
  const durumRaw = (toStringValue(record.durum ?? record.state) ?? "AKTIF").toUpperCase();
  const durum: MevzuatDurum = durumRaw === "IPTAL" ? "IPTAL" : "AKTIF";

  return {
    id,
    parametre_kodu: parametreKodu.toUpperCase(),
    deger_tipi: degerTipi,
    sayisal_deger: toNumber(record.sayisal_deger ?? record.sayisalDeger) ?? null,
    metin_deger: toStringValue(record.metin_deger ?? record.metinDeger) ?? null,
    gecerlilik_baslangic: gecerlilikBaslangic,
    gecerlilik_bitis: toStringValue(record.gecerlilik_bitis ?? record.gecerlilikBitis) ?? null,
    birim: toStringValue(record.birim) ?? null,
    aciklama: toStringValue(record.aciklama) ?? null,
    kaynak_referansi: toStringValue(record.kaynak_referansi ?? record.kaynakReferansi) ?? null,
    durum,
    created_at: toStringValue(record.created_at) ?? null,
    created_by: toNumber(record.created_by) ?? null,
    updated_at: toStringValue(record.updated_at) ?? null,
    updated_by: toNumber(record.updated_by) ?? null
  };
}

export async function fetchMevzuatParametreleri(params?: {
  parametre_kodu?: string;
}): Promise<MevzuatParametresi[]> {
  const path = appendQueryParams(endpoints.mevzuatParametreleri.list, {
    parametre_kodu: params?.parametre_kodu
  });

  const response = await apiRequest<ApiResponse<unknown>>(path);
  assertNoMevzuatApiErrors(response, "Mevzuat parametreleri yüklenemedi.");

  return extractListItems<unknown>(response.data).map((item) => normalizeMevzuatParametresi(item));
}

export async function createMevzuatParametresi(
  payload: CreateMevzuatParametresiPayload
): Promise<MevzuatParametresi> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.mevzuatParametreleri.create, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  assertNoMevzuatApiErrors(response, "Mevzuat parametresi oluşturulamadı.");

  const created = normalizeMevzuatParametresi(response.data);
  logAction({ action: "MEVZUAT_PARAMETRE_CREATE", payload: { mevzuat_parametre_id: created.id } });
  return created;
}

export async function updateMevzuatParametresi(
  id: number | string,
  payload: UpdateMevzuatParametresiPayload
): Promise<MevzuatParametresi> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.mevzuatParametreleri.detail(id), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  assertNoMevzuatApiErrors(response, "Mevzuat parametresi güncellenemedi.");

  const updated = normalizeMevzuatParametresi(response.data);
  logAction({ action: "MEVZUAT_PARAMETRE_UPDATE", payload: { mevzuat_parametre_id: updated.id } });
  return updated;
}

export async function cancelMevzuatParametresi(id: number | string): Promise<void> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.mevzuatParametreleri.cancel(id), {
    method: "POST"
  });
  assertNoMevzuatApiErrors(response, "Mevzuat parametresi iptal edilemedi.");
  logAction({ action: "MEVZUAT_PARAMETRE_CANCEL", payload: { mevzuat_parametre_id: id } });
}
