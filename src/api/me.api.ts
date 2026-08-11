import type { ApiResponse } from "../types/api";
import type {
  MeFazlaCalismaResponse,
  MeIdentity,
  MePuantajGun,
  MePuantajOzet,
  MePuantajResponse,
  MePersonelSummary,
  MeYillikIzinBakiye
} from "../types/self-service";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest, shouldPreferDemoApi } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizeYillikIzinBakiye } from "./yillik-izin-hak-duzeltme.api";

const ERROR_STATUS: Record<string, number> = {
  SELF_SERVICE_BINDING_REQUIRED: 403,
  SELF_SERVICE_PERSONEL_INACTIVE: 403,
  SELF_SERVICE_PERSONEL_MISSING: 403,
  SELF_SERVICE_SCHEMA_NOT_READY: 403,
  FORBIDDEN: 403,
  VALIDATION_ERROR: 422,
  NOT_FOUND: 404
};

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }
  return value as Record<string, unknown>;
}

function readString(value: unknown): string | null {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return trimmed ? trimmed : null;
}

function readNumber(value: unknown): number | null {
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

function readNullableNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === "") {
    return null;
  }
  return readNumber(value);
}

function throwEnvelopeErrors(response: ApiResponse<unknown>, fallback: string): void {
  if (!Array.isArray(response.errors) || response.errors.length === 0) {
    return;
  }
  const first = response.errors[0];
  const code = typeof first?.code === "string" ? first.code : "INVALID_REQUEST";
  throw new ApiRequestError(
    typeof first?.message === "string" ? first.message : fallback,
    ERROR_STATUS[code] ?? 400,
    {
      code,
      field: typeof first?.field === "string" ? first.field : undefined
    }
  );
}

function unwrapData(response: unknown, fallback: string): unknown {
  const root = toRecord(response);
  if (!root) {
    throw new ApiRequestError(fallback, 400, { code: "INVALID_RESPONSE" });
  }
  if (Array.isArray(root.errors) && root.errors.length > 0) {
    throwEnvelopeErrors(root as ApiResponse<unknown>, fallback);
  }
  return "data" in root ? root.data : root;
}

function demoBindingRequired(): never {
  throw new ApiRequestError("Hesabınız personel kaydıyla eşleştirilmemiş.", 403, {
    code: "SELF_SERVICE_BINDING_REQUIRED"
  });
}

function normalizePersonelSummary(value: unknown): MePersonelSummary {
  const record = toRecord(value);
  if (!record) {
    throw new ApiRequestError("Personel özeti beklenen formatta değil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }
  const id = readNumber(record.id ?? record.personel_id);
  if (id === null) {
    throw new ApiRequestError("Personel özetinde id eksik.", 400, { code: "INVALID_RESPONSE" });
  }
  const ad = readString(record.ad) ?? "";
  const soyad = readString(record.soyad) ?? "";
  return {
    id,
    ad,
    soyad,
    ad_soyad: readString(record.ad_soyad) ?? `${ad} ${soyad}`.trim(),
    sube_id: readNumber(record.sube_id) ?? 0,
    sube_ad: readString(record.sube_ad) ?? "",
    departman_id: readNullableNumber(record.departman_id),
    departman_ad: readString(record.departman_ad),
    gorev_id: readNullableNumber(record.gorev_id),
    gorev_ad: readString(record.gorev_ad),
    aktif_durum: readString(record.aktif_durum) ?? "AKTIF"
  };
}

function normalizeMeIdentity(data: unknown): MeIdentity {
  const record = toRecord(data);
  if (!record) {
    throw new ApiRequestError("/me yanıtı beklenen formatta değil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }
  const userId = readNumber(record.user_id ?? record.userId);
  const personelId = readNumber(record.personel_id ?? record.personelId);
  if (userId === null || personelId === null) {
    throw new ApiRequestError("/me yanıtında kimlik alanları eksik.", 400, {
      code: "INVALID_RESPONSE"
    });
  }
  return {
    user_id: userId,
    username: readString(record.username) ?? "",
    ad_soyad: readString(record.ad_soyad) ?? "",
    rol: readString(record.rol) ?? "",
    personel_id: personelId,
    personel: normalizePersonelSummary(record.personel)
  };
}

function normalizePuantajGun(value: unknown): MePuantajGun | null {
  const record = toRecord(value);
  if (!record) {
    return null;
  }
  const tarih = readString(record.tarih);
  if (!tarih) {
    return null;
  }
  return {
    tarih,
    gun_tipi: readString(record.gun_tipi),
    giris_saati: readString(record.giris_saati),
    cikis_saati: readString(record.cikis_saati),
    net_calisma_suresi_dakika: readNullableNumber(record.net_calisma_suresi_dakika),
    gunluk_brut_sure_dakika: readNullableNumber(record.gunluk_brut_sure_dakika),
    gec_kalma_dakika: readNullableNumber(record.gec_kalma_dakika),
    erken_cikis_dakika: readNullableNumber(record.erken_cikis_dakika),
    fazla_calisma_dakika: readNullableNumber(record.fazla_calisma_dakika)
  };
}

function normalizePuantajOzet(value: unknown): MePuantajOzet {
  const record = toRecord(value) ?? {};
  return {
    calisma_gun_adet: readNumber(record.calisma_gun_adet) ?? 0,
    gec_kalma_adet: readNumber(record.gec_kalma_adet) ?? 0,
    gec_kalma_dakika_toplam: readNumber(record.gec_kalma_dakika_toplam) ?? 0,
    erken_cikis_adet: readNumber(record.erken_cikis_adet) ?? 0,
    erken_cikis_dakika_toplam: readNumber(record.erken_cikis_dakika_toplam) ?? 0,
    fazla_calisma_dakika_toplam: readNumber(record.fazla_calisma_dakika_toplam) ?? 0
  };
}

function normalizeMePuantaj(data: unknown): MePuantajResponse {
  const record = toRecord(data);
  if (!record) {
    throw new ApiRequestError("/me/puantaj yanıtı beklenen formatta değil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }
  const personelId = readNumber(record.personel_id);
  const from = readString(record.from);
  const to = readString(record.to);
  if (personelId === null || !from || !to) {
    throw new ApiRequestError("/me/puantaj yanıtında alanlar eksik.", 400, {
      code: "INVALID_RESPONSE"
    });
  }
  const items = Array.isArray(record.items)
    ? record.items.map(normalizePuantajGun).filter((item): item is MePuantajGun => item !== null)
    : [];
  return {
    personel_id: personelId,
    from,
    to,
    items,
    ozet: normalizePuantajOzet(record.ozet)
  };
}

function normalizeMeFazlaCalisma(data: unknown): MeFazlaCalismaResponse {
  const record = toRecord(data);
  if (!record) {
    throw new ApiRequestError("/me/fazla-calisma yanıtı beklenen formatta değil.", 400, {
      code: "INVALID_RESPONSE"
    });
  }
  const personelId = readNumber(record.personel_id);
  const yil = readNumber(record.yil);
  const from = readString(record.from) ?? "";
  const to = readString(record.to) ?? "";
  if (personelId === null || yil === null) {
    throw new ApiRequestError("/me/fazla-calisma yanıtında alanlar eksik.", 400, {
      code: "INVALID_RESPONSE"
    });
  }
  const donem = toRecord(record.donem_ozet);
  const yillik = toRecord(record.yillik) ?? {};
  return {
    personel_id: personelId,
    yil,
    from,
    to,
    donem_ozet: donem
      ? {
          fazla_calisma_dakika_toplam: readNumber(donem.fazla_calisma_dakika_toplam) ?? 0,
          calisma_gun_adet: readNumber(donem.calisma_gun_adet) ?? 0
        }
      : null,
    yillik: {
      personel_id: readNumber(yillik.personel_id) ?? personelId,
      yil: readNumber(yillik.yil) ?? yil,
      yillik_limit_dakika: readNumber(yillik.yillik_limit_dakika) ?? 16200,
      yaklasma_esik_dakika: readNumber(yillik.yaklasma_esik_dakika) ?? 15600,
      kullanilan_dakika: readNumber(yillik.kullanilan_dakika) ?? 0,
      kalan_dakika: readNumber(yillik.kalan_dakika) ?? 0,
      limit_asildi_mi: Boolean(yillik.limit_asildi_mi),
      limit_yaklasiyor_mu: Boolean(yillik.limit_yaklasiyor_mu),
      kapanan_hafta_sayisi: readNumber(yillik.kapanan_hafta_sayisi) ?? 0,
      atlanan_duplicate_hafta_sayisi: readNumber(yillik.atlanan_duplicate_hafta_sayisi) ?? 0,
      atlanan_eksik_hafta_sayisi: readNumber(yillik.atlanan_eksik_hafta_sayisi) ?? 0
    }
  };
}

export async function fetchMe(): Promise<MeIdentity> {
  if (shouldPreferDemoApi()) {
    demoBindingRequired();
  }
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.me.identity);
  return normalizeMeIdentity(unwrapData(response, "/me alınamadı."));
}

export async function fetchMePuantaj(params?: {
  from?: string;
  to?: string;
}): Promise<MePuantajResponse> {
  if (shouldPreferDemoApi()) {
    demoBindingRequired();
  }
  const path = appendQueryParams(endpoints.me.puantaj, {
    from: params?.from,
    to: params?.to
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizeMePuantaj(unwrapData(response, "/me/puantaj alınamadı."));
}

export async function fetchMeYillikIzinBakiye(params?: {
  referans_tarih?: string;
}): Promise<MeYillikIzinBakiye> {
  if (shouldPreferDemoApi()) {
    demoBindingRequired();
  }
  const path = appendQueryParams(endpoints.me.yillikIzinBakiye, {
    referans_tarih: params?.referans_tarih
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizeYillikIzinBakiye(unwrapData(response, "/me/yillik-izin-bakiye alınamadı."));
}

export async function fetchMeFazlaCalisma(params?: {
  yil?: number;
  from?: string;
  to?: string;
}): Promise<MeFazlaCalismaResponse> {
  if (shouldPreferDemoApi()) {
    demoBindingRequired();
  }
  const path = appendQueryParams(endpoints.me.fazlaCalisma, {
    yil: params?.yil,
    from: params?.from,
    to: params?.to
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizeMeFazlaCalisma(unwrapData(response, "/me/fazla-calisma alınamadı."));
}
