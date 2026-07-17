import type { ApiResponse } from "../types/api";
import type {
  CreatePersonelUcretPayload,
  PersonelUcretKaydi,
  UcretDurum,
  UcretKaynak,
  UcretTuru,
  UpdatePersonelUcretPayload
} from "../types/ucret";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { ApiRequestError, apiRequest, getApiErrorDetail } from "./api-client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

export const SALARY_DATE_OVERLAP_MESSAGE =
  "Bu personel için seçilen tarih aralığında başka bir ücret kaydı bulunmaktadır.";

const UCRET_ERROR_STATUS: Record<string, number> = {
  SALARY_DATE_OVERLAP: 409,
  SALARY_OVERLAP_DATA_ERROR: 409,
  SALARY_CHANGE_FORBIDDEN: 409,
  SALARY_ACCESS_FORBIDDEN: 403,
  SALARY_RECORD_NOT_FOUND: 404,
  SALARY_MISSING: 404,
  DATE_RANGE_INVALID: 400,
  DATE_INVALID: 400,
  SALARY_AMOUNT_INVALID: 400,
  SALARY_TYPE_INVALID: 400,
  SALARY_CURRENCY_INVALID: 400,
  VALIDATION_ERROR: 422,
  FORBIDDEN: 403
};

function throwFirstUcretApiError(
  errors: ApiResponse<unknown>["errors"],
  fallbackMessage: string
): never {
  const first = errors?.[0];
  const code = typeof first?.code === "string" ? first.code : "INVALID_REQUEST";
  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallbackMessage,
    UCRET_ERROR_STATUS[code] ?? 400,
    { code }
  );
}

function assertNoUcretApiErrors(response: ApiResponse<unknown>, fallbackMessage: string): void {
  if (Array.isArray(response.errors) && response.errors.length > 0) {
    throwFirstUcretApiError(response.errors, fallbackMessage);
  }
}

/** Ücret hatalarını Türkçe kullanıcı mesajına çevirir; overlap için sabit metin döner. */
export function getUcretApiErrorMessage(error: unknown, fallbackMessage: string): string {
  const detail = getApiErrorDetail(error, fallbackMessage);
  if (detail.code === "SALARY_DATE_OVERLAP") {
    return SALARY_DATE_OVERLAP_MESSAGE;
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

function todayIsoDate(): string {
  return new Date().toISOString().slice(0, 10);
}

/** Tarih aralığı dahil (inclusive) semantiği: baslangic <= gun && (bitis yok || gun <= bitis). */
export function isUcretKaydiGuncel(
  record: Pick<PersonelUcretKaydi, "durum" | "gecerlilik_baslangic" | "gecerlilik_bitis">,
  referenceDate: string = todayIsoDate()
): boolean {
  if (record.durum !== "AKTIF") {
    return false;
  }

  if (record.gecerlilik_baslangic > referenceDate) {
    return false;
  }

  return record.gecerlilik_bitis === null || referenceDate <= record.gecerlilik_bitis;
}

export function normalizePersonelUcretKaydi(data: unknown): PersonelUcretKaydi {
  if (typeof data !== "object" || data === null) {
    throw new Error("Ücret kaydı yanıtı beklenen formatta değil.");
  }

  const record = data as Record<string, unknown>;
  const personelId = toNumber(record.personel_id ?? record.personelId);
  const ucretTutari = toNumber(record.ucret_tutari ?? record.ucretTutari);
  const gecerlilikBaslangic = toStringValue(record.gecerlilik_baslangic ?? record.gecerlilikBaslangic);

  if (!personelId || ucretTutari === undefined || !gecerlilikBaslangic) {
    throw new Error("Ücret kaydı yanıtı eksik alan içeriyor.");
  }

  const ucretTuruRaw = (toStringValue(record.ucret_turu ?? record.ucretTuru) ?? "NET").toUpperCase();
  const ucretTuru: UcretTuru = ucretTuruRaw === "BRUT" ? "BRUT" : "NET";
  const durumRaw = (toStringValue(record.durum ?? record.state) ?? "AKTIF").toUpperCase();
  const durum: UcretDurum = durumRaw === "IPTAL" ? "IPTAL" : "AKTIF";
  const kaynakRaw = (toStringValue(record.kaynak) ?? "MANUEL").toUpperCase();
  const kaynak: UcretKaynak =
    kaynakRaw === "PERSONEL_KAYDI_MIGRASYON" || kaynakRaw === "SISTEM" ? kaynakRaw : "MANUEL";
  const gecerlilikBitis = toStringValue(record.gecerlilik_bitis ?? record.gecerlilikBitis) ?? null;

  const normalized: PersonelUcretKaydi = {
    id: toNumber(record.id) ?? null,
    personel_id: personelId,
    ucret_tutari: ucretTutari,
    ucret_turu: ucretTuru,
    para_birimi: (toStringValue(record.para_birimi ?? record.paraBirimi) ?? "TRY").toUpperCase(),
    gecerlilik_baslangic: gecerlilikBaslangic,
    gecerlilik_bitis: gecerlilikBitis,
    durum,
    guncel_mi: false,
    kaynak,
    aciklama: toStringValue(record.aciklama) ?? null,
    created_at: toStringValue(record.created_at) ?? null,
    created_by: toNumber(record.created_by) ?? null,
    updated_at: toStringValue(record.updated_at) ?? null,
    updated_by: toNumber(record.updated_by) ?? null
  };

  const guncelRaw = record.guncel_mi;
  normalized.guncel_mi =
    typeof guncelRaw === "boolean" ? guncelRaw : isUcretKaydiGuncel(normalized);

  return normalized;
}

export async function fetchPersonelUcretList(
  personelId: number | string
): Promise<PersonelUcretKaydi[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personelUcretleri.list(personelId));
  assertNoUcretApiErrors(response, "Ücret geçmişi yüklenemedi.");

  return extractListItems<unknown>(response.data).map((item) => normalizePersonelUcretKaydi(item));
}

export async function fetchPersonelAktifUcret(
  personelId: number | string,
  tarih?: string
): Promise<PersonelUcretKaydi | null> {
  const path = appendQueryParams(endpoints.personelUcretleri.aktif(personelId), { tarih });

  try {
    const response = await apiRequest<ApiResponse<unknown>>(path);
    assertNoUcretApiErrors(response, "Güncel ücret bilgisi alınamadı.");
    if (response.data === null || response.data === undefined) {
      return null;
    }
    return normalizePersonelUcretKaydi(response.data);
  } catch (error) {
    if (
      error instanceof ApiRequestError &&
      (error.code === "SALARY_MISSING" || error.status === 404)
    ) {
      return null;
    }
    throw error;
  }
}

export async function createPersonelUcret(
  personelId: number | string,
  payload: CreatePersonelUcretPayload
): Promise<PersonelUcretKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.personelUcretleri.create(personelId),
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  assertNoUcretApiErrors(response, "Ücret kaydı oluşturulamadı.");

  const created = normalizePersonelUcretKaydi(response.data);
  logAction({ action: "UCRET_CREATE", payload: { personel_id: personelId, ucret_id: created.id } });
  return created;
}

export async function updatePersonelUcret(
  personelId: number | string,
  ucretId: number | string,
  payload: UpdatePersonelUcretPayload
): Promise<PersonelUcretKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.personelUcretleri.detail(personelId, ucretId),
    {
      method: "PUT",
      body: JSON.stringify(payload)
    }
  );
  assertNoUcretApiErrors(response, "Ücret kaydı güncellenemedi.");

  const updated = normalizePersonelUcretKaydi(response.data);
  logAction({ action: "UCRET_UPDATE", payload: { personel_id: personelId, ucret_id: updated.id } });
  return updated;
}

export async function cancelPersonelUcret(
  personelId: number | string,
  ucretId: number | string
): Promise<void> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.personelUcretleri.cancel(personelId, ucretId),
    {
      method: "POST"
    }
  );
  assertNoUcretApiErrors(response, "Ücret kaydı iptal edilemedi.");
  logAction({ action: "UCRET_CANCEL", payload: { personel_id: personelId, ucret_id: ucretId } });
}
