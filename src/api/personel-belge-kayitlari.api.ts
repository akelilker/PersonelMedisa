import type { ApiResponse, PaginatedResult } from "../types/api";
import {
  computeGecerlilikDurumu,
  deriveTakipDurumu,
  maskBelgeNo,
  normalizePersonelBelgeKayitTipi,
  type BelgeTakipOzet,
  type BelgeTakipParams,
  type BelgeTakipSatir,
  type CancelPersonelBelgeKaydiPayload,
  type CreatePersonelBelgeKaydiPayload,
  type PersonelBelgeAuditKaydi,
  type PersonelBelgeDosyaInfo,
  type PersonelBelgeKaydi,
  type PersonelBelgeKayitDurum,
  type PersonelBelgeTakipDurumu,
  type ReplacePersonelBelgeDosyaPayload,
  type UpdatePersonelBelgeKaydiPayload
} from "../types/personel-belge-kaydi";
import { logAction } from "../audit/audit-service";
import { ApiRequestError, apiRequest, buildApiUrl, getApiErrorMessage } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";
import { getActiveSubeIdForApiHeader } from "../auth/auth-manager";
import { getAuthTokenForApi } from "../auth/auth-token-provider";

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

function toNullableString(value: unknown): string | null {
  if (value === null || value === undefined) {
    return null;
  }

  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  return trimmed || null;
}

function isPersonelBelgeKayitDurum(value: unknown): value is PersonelBelgeKayitDurum {
  return value === "AKTIF" || value === "IPTAL";
}

function isPersonelBelgeTakipDurumu(value: unknown): value is PersonelBelgeTakipDurumu {
  return (
    value === "AKTIF" ||
    value === "SURESI_YAKLASIYOR" ||
    value === "SURESI_DOLDU" ||
    value === "IPTAL" ||
    value === "BELGE_DOSYASI_EKSIK"
  );
}

function normalizePersonelBelgeDosyaInfo(data: unknown): PersonelBelgeDosyaInfo | undefined {
  if (typeof data !== "object" || data === null) {
    return undefined;
  }

  const record = data as Record<string, unknown>;
  const varMiRaw = record.var_mi ?? record.varMi;
  const var_mi = varMiRaw === true || varMiRaw === 1 || varMiRaw === "1";

  return {
    var_mi,
    surum_no: toNumber(record.surum_no ?? record.surumNo),
    orijinal_dosya_adi: toNullableString(record.orijinal_dosya_adi ?? record.orijinalDosyaAdi) ?? undefined,
    mime_type: toNullableString(record.mime_type ?? record.mimeType) ?? undefined,
    byte_boyutu: toNumber(record.byte_boyutu ?? record.byteBoyutu),
    sha256: toNullableString(record.sha256) ?? undefined,
    created_at: toNullableString(record.created_at ?? record.createdAt)
  };
}

export function normalizePersonelBelgeKaydi(data: unknown): PersonelBelgeKaydi {
  if (typeof data !== "object" || data === null) {
    throw new Error("Belge kaydi yaniti beklenen formatta degil.");
  }

  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const personelId = toNumber(record.personel_id ?? record.personelId);
  const kayitTipiRaw = record.kayit_tipi ?? record.kayitTipi;
  const kayitTipi = normalizePersonelBelgeKayitTipi(kayitTipiRaw);
  const ad = toNullableString(record.ad);

  if (!id || !personelId || !kayitTipi || !ad) {
    throw new Error("Belge kaydi yaniti eksik alan iceriyor.");
  }

  const bitisTarihi = toNullableString(record.bitis_tarihi ?? record.bitisTarihi);
  const durumRaw = record.durum ?? record.state;
  const durum: PersonelBelgeKayitDurum = isPersonelBelgeKayitDurum(durumRaw) ? durumRaw : "AKTIF";
  const belgeNo = toNullableString(record.belge_no ?? record.belgeNo);
  const dosya = normalizePersonelBelgeDosyaInfo(record.dosya);
  const hasActiveFile = dosya?.var_mi === true;
  const takipRaw = record.takip_durumu ?? record.takipDurumu;
  const takip_durumu = isPersonelBelgeTakipDurumu(takipRaw)
    ? takipRaw
    : deriveTakipDurumu(durum, bitisTarihi, hasActiveFile);
  const belgeNoMasked =
    toNullableString(record.belge_no_masked ?? record.belgeNoMasked) ?? maskBelgeNo(belgeNo);

  return {
    id,
    personel_id: personelId,
    kayit_tipi: kayitTipi,
    ad,
    veren_kurum: toNullableString(record.veren_kurum ?? record.verenKurum),
    belge_no: belgeNo,
    belge_no_masked: belgeNoMasked,
    baslangic_tarihi: toNullableString(record.baslangic_tarihi ?? record.baslangicTarihi),
    bitis_tarihi: bitisTarihi,
    durum,
    gecerlilik_durumu: computeGecerlilikDurumu(bitisTarihi),
    takip_durumu,
    ek_ref: toNullableString(record.ek_ref ?? record.ekRef),
    aciklama: toNullableString(record.aciklama),
    dosya,
    yukleyen_ad: (() => {
      const direct = toNullableString(record.yukleyen_ad ?? record.yukleyenAd);
      if (direct) {
        return direct;
      }
      const nested = record.yukleyen;
      if (typeof nested === "object" && nested !== null) {
        return toNullableString((nested as Record<string, unknown>).ad_soyad);
      }
      return null;
    })(),
    created_at: toNullableString(record.created_at ?? record.createdAt),
    updated_at: toNullableString(record.updated_at ?? record.updatedAt)
  };
}

function normalizePersonelBelgeAuditKaydi(data: unknown): PersonelBelgeAuditKaydi {
  if (typeof data !== "object" || data === null) {
    throw new Error("Belge gecmisi yaniti beklenen formatta degil.");
  }

  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const islem = toNullableString(record.islem_turu ?? record.islemTuru);
  const createdAt = toNullableString(record.created_at ?? record.createdAt);

  if (!id || !islem || !createdAt) {
    throw new Error("Belge gecmisi yaniti eksik alan iceriyor.");
  }

  return {
    id,
    islem_turu: islem,
    yapan_kullanici_ad: toNullableString(record.yapan_kullanici_ad ?? record.yapanKullaniciAd),
    gerekce: toNullableString(record.gerekce),
    dosya_adi: toNullableString(record.dosya_adi ?? record.dosyaAdi),
    dosya_mime: toNullableString(record.dosya_mime ?? record.dosyaMime),
    dosya_byte: toNumber(record.dosya_byte ?? record.dosyaByte) ?? null,
    created_at: createdAt
  };
}

function normalizeBelgeTakipSatir(data: unknown): BelgeTakipSatir {
  if (typeof data !== "object" || data === null) {
    throw new Error("Belge takip yaniti beklenen formatta degil.");
  }

  const record = data as Record<string, unknown>;
  const belgeKaydiId = toNumber(record.belge_kaydi_id ?? record.belgeKaydiId ?? record.id);
  const personelId = toNumber(record.personel_id ?? record.personelId);
  const personelAdSoyad = toNullableString(record.personel_ad_soyad ?? record.personelAdSoyad);
  const kayitTipi = normalizePersonelBelgeKayitTipi(record.kayit_tipi ?? record.kayitTipi);
  const ad = toNullableString(record.ad);
  const takipRaw = record.takip_durumu ?? record.takipDurumu;
  const bitisTarihi = toNullableString(record.bitis_tarihi ?? record.bitisTarihi);
  const belgeNoMasked =
    toNullableString(record.belge_no_masked ?? record.belgeNoMasked) ??
    maskBelgeNo(toNullableString(record.belge_no ?? record.belgeNo));

  if (!belgeKaydiId || !personelId || !personelAdSoyad || !kayitTipi || !ad) {
    throw new Error("Belge takip satiri eksik alan iceriyor.");
  }

  const durum: PersonelBelgeKayitDurum =
    record.durum === "IPTAL" || record.state === "IPTAL" ? "IPTAL" : "AKTIF";
  const takip_durumu = isPersonelBelgeTakipDurumu(takipRaw)
    ? takipRaw
    : deriveTakipDurumu(durum, bitisTarihi, record.dosya_var_mi === true || record.dosyaVarMi === true);

  return {
    belge_kaydi_id: belgeKaydiId,
    personel_id: personelId,
    personel_ad_soyad: personelAdSoyad,
    sube_id: toNumber(record.sube_id ?? record.subeId) ?? null,
    departman_id: toNumber(record.departman_id ?? record.departmanId) ?? null,
    kayit_tipi: kayitTipi,
    ad,
    takip_durumu,
    bitis_tarihi: bitisTarihi,
    belge_no_masked: belgeNoMasked,
    updated_at: toNullableString(record.updated_at ?? record.updatedAt)
  };
}

function normalizeBelgeTakipOzet(data: unknown): BelgeTakipOzet {
  if (typeof data !== "object" || data === null) {
    return {
      toplam_aktif: 0,
      suresi_yaklasan: 0,
      suresi_dolan: 0,
      dosyasi_eksik: 0,
      belgesi_hic_bulunmayan: 0
    };
  }

  const record = data as Record<string, unknown>;
  return {
    toplam_aktif: toNumber(record.toplam_aktif ?? record.toplamAktif) ?? 0,
    suresi_yaklasan: toNumber(record.suresi_yaklasan ?? record.suresiYaklasan) ?? 0,
    suresi_dolan: toNumber(record.suresi_dolan ?? record.suresiDolan) ?? 0,
    dosyasi_eksik: toNumber(record.dosyasi_eksik ?? record.dosyasiEksik) ?? 0,
    belgesi_hic_bulunmayan:
      toNumber(record.belgesi_hic_bulunmayan ?? record.belgesiHicBulunmayan) ?? 0
  };
}

export async function fetchPersonelBelgeKayitlari(
  personelId: number | string,
  params?: { state?: PersonelBelgeKayitDurum | "tum"; page?: number; limit?: number }
): Promise<PaginatedResult<PersonelBelgeKaydi>> {
  const searchParams = new URLSearchParams();
  if (params?.state && params.state !== "tum") {
    searchParams.set("state", params.state);
  }
  if (params?.page) {
    searchParams.set("page", String(params.page));
  }
  if (params?.limit) {
    searchParams.set("limit", String(params.limit));
  }

  const query = searchParams.toString();
  const basePath = endpoints.personelBelgeKayitlari.listByPersonel(personelId);
  const path = query ? `${basePath}?${query}` : basePath;

  const response = await apiRequest<ApiResponse<unknown>>(path);
  const normalized = normalizePaginatedList<unknown>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });

  return {
    items: normalized.items.map((item) => normalizePersonelBelgeKaydi(item)),
    pagination: normalized.pagination
  };
}

export async function fetchPersonelBelgeKaydiDetail(id: number | string): Promise<PersonelBelgeKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personelBelgeKayitlari.detail(id));
  return normalizePersonelBelgeKaydi(response.data);
}

export async function createPersonelBelgeKaydi(
  personelId: number | string,
  payload: CreatePersonelBelgeKaydiPayload
): Promise<PersonelBelgeKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.personelBelgeKayitlari.create(personelId),
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  const created = normalizePersonelBelgeKaydi(response.data);
  logAction({
    action: "PERSONEL_BELGE_KAYDI_CREATE",
    payload: { belge_kaydi_id: created.id, personel_id: created.personel_id }
  });
  return created;
}

export async function updatePersonelBelgeKaydi(
  id: number | string,
  payload: UpdatePersonelBelgeKaydiPayload
): Promise<PersonelBelgeKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personelBelgeKayitlari.detail(id), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  return normalizePersonelBelgeKaydi(response.data);
}

export async function cancelPersonelBelgeKaydi(
  id: number | string,
  payload: CancelPersonelBelgeKaydiPayload
): Promise<PersonelBelgeKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personelBelgeKayitlari.cancel(id), {
    method: "POST",
    body: JSON.stringify(payload)
  });
  return normalizePersonelBelgeKaydi(response.data);
}

export async function replacePersonelBelgeDosya(
  id: number | string,
  payload: ReplacePersonelBelgeDosyaPayload
): Promise<PersonelBelgeKaydi> {
  const response = await apiRequest<ApiResponse<unknown>>(
    endpoints.personelBelgeKayitlari.replaceDosya(id),
    {
      method: "POST",
      body: JSON.stringify(payload)
    }
  );
  return normalizePersonelBelgeKaydi(response.data);
}

export async function fetchPersonelBelgeHistory(id: number | string): Promise<PersonelBelgeAuditKaydi[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personelBelgeKayitlari.history(id));
  const data = response.data;
  if (!data || typeof data !== "object") {
    return [];
  }

  const items = (data as Record<string, unknown>).items;
  if (!Array.isArray(items)) {
    return [];
  }

  return items.map((item) => normalizePersonelBelgeAuditKaydi(item));
}

export async function fetchBelgeTakip(params?: BelgeTakipParams): Promise<{
  ozet: BelgeTakipOzet;
  items: BelgeTakipSatir[];
  pagination: PaginatedResult<BelgeTakipSatir>["pagination"];
}> {
  const searchParams = new URLSearchParams();
  if (params?.sube_id) {
    searchParams.set("sube_id", String(params.sube_id));
  }
  if (params?.departman_id) {
    searchParams.set("departman_id", String(params.departman_id));
  }
  if (params?.personel_id) {
    searchParams.set("personel_id", String(params.personel_id));
  }
  if (params?.kayit_tipi) {
    searchParams.set("kayit_tipi", params.kayit_tipi);
  }
  if (params?.takip_durumu) {
    searchParams.set("takip_durumu", params.takip_durumu);
  }
  if (params?.baslangic_tarihi) {
    searchParams.set("baslangic_tarihi", params.baslangic_tarihi);
  }
  if (params?.bitis_tarihi) {
    searchParams.set("bitis_tarihi", params.bitis_tarihi);
  }
  if (params?.personel_aktiflik && params.personel_aktiflik !== "tum") {
    searchParams.set("personel_aktiflik", params.personel_aktiflik);
  }
  if (params?.page) {
    searchParams.set("page", String(params.page));
  }
  if (params?.limit) {
    searchParams.set("limit", String(params.limit));
  }

  const query = searchParams.toString();
  const path = query ? `${endpoints.belgeTakip}?${query}` : endpoints.belgeTakip;
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const data = response.data;
  const record = typeof data === "object" && data !== null ? (data as Record<string, unknown>) : {};
  const normalized = normalizePaginatedList<unknown>(
    {
      ...response,
      data: { items: record.items ?? [] }
    },
    {
      requestedPage: params?.page,
      requestedLimit: params?.limit
    }
  );

  return {
    ozet: normalizeBelgeTakipOzet(record.ozet ?? record.summary),
    items: normalized.items.map((item) => normalizeBelgeTakipSatir(item)),
    pagination: normalized.pagination
  };
}

export async function downloadPersonelBelgeDosya(
  id: number | string,
  suggestedFilename?: string
): Promise<void> {
  const path = endpoints.personelBelgeKayitlari.download(id);
  const { resolveDemoApiResponse } = await import("./mock-demo");
  const demoResponse = resolveDemoApiResponse(path, { method: "GET" });
  if (demoResponse !== null) {
    const demoData = demoResponse.data;
    if (typeof demoData === "string") {
      const binary = atob(demoData);
      const bytes = new Uint8Array(binary.length);
      for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
      }
      const blob = new Blob([bytes], { type: "application/pdf" });
      triggerBlobDownload(blob, suggestedFilename ?? `belge-${id}.pdf`);
      return;
    }

    const blob = new Blob(["%PDF-1.4 demo"], { type: "application/pdf" });
    triggerBlobDownload(blob, suggestedFilename ?? `belge-${id}.pdf`);
    return;
  }

  const headers = new Headers();
  const token = getAuthTokenForApi();
  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }
  const subeHeader = getActiveSubeIdForApiHeader();
  if (subeHeader) {
    headers.set("X-Active-Sube-Id", subeHeader);
  }

  const response = await fetch(buildApiUrl(path), { headers });
  if (!response.ok) {
    throw new ApiRequestError(getApiErrorMessage(await response.json().catch(() => null), "Belge indirilemedi."), response.status);
  }

  const blob = await response.blob();
  const disposition = response.headers.get("Content-Disposition") ?? "";
  const filenameMatch = /filename=\"?([^\";]+)\"?/i.exec(disposition);
  triggerBlobDownload(blob, filenameMatch?.[1] ?? suggestedFilename ?? `belge-${id}.pdf`);
}

function triggerBlobDownload(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}
