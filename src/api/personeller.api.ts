import type { ApiResponse, PaginatedResult } from "../types/api";
import type { Personel, PersonelAktifDurum } from "../types/personel";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type PersonellerListParams = {
  search?: string;
  departman_id?: number;
  sube_id?: number;
  aktiflik?: "aktif" | "pasif" | "tum";
  personel_tipi_id?: number;
  page?: number;
  limit?: number;
};

export type CreatePersonelPayload = {
  tc_kimlik_no: string;
  ad: string;
  soyad: string;
  dogum_tarihi: string;
  telefon: string;
  acil_durum_kisi: string;
  acil_durum_telefon: string;
  sicil_no: string;
  ise_giris_tarihi: string;
  departman_id: number;
  gorev_id: number;
  personel_tipi_id: number;
  aktif_durum: "AKTIF" | "PASIF";
  dogum_yeri?: string;
  kan_grubu?: string;
  bagli_amir_id?: number;
};

export type UpdatePersonelPayload = Partial<CreatePersonelPayload>;

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function pickValue(sources: Array<Record<string, unknown> | null>, keys: string[]): unknown {
  for (const source of sources) {
    if (!source) {
      continue;
    }

    for (const key of keys) {
      if (key in source) {
        const value = source[key];
        if (value !== undefined) {
          return value;
        }
      }
    }
  }

  return undefined;
}

function readStringValue(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : undefined;
}

function readNullableStringValue(value: unknown): string | null | undefined {
  if (value === null) {
    return null;
  }

  return readStringValue(value);
}

function readNumberValue(value: unknown): number | undefined {
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

function readString(sources: Array<Record<string, unknown> | null>, ...keys: string[]): string | undefined {
  return readStringValue(pickValue(sources, keys));
}

function readNullableString(
  sources: Array<Record<string, unknown> | null>,
  ...keys: string[]
): string | null | undefined {
  return readNullableStringValue(pickValue(sources, keys));
}

function readNumber(sources: Array<Record<string, unknown> | null>, ...keys: string[]): number | undefined {
  return readNumberValue(pickValue(sources, keys));
}

function readRequiredString(
  sources: Array<Record<string, unknown> | null>,
  fieldLabel: string,
  ...keys: string[]
): string {
  const value = readString(sources, ...keys);
  if (!value) {
    throw new Error(`Personel yaniti ${fieldLabel} alanini icermiyor.`);
  }
  return value;
}

function readRequiredNumber(
  sources: Array<Record<string, unknown> | null>,
  fieldLabel: string,
  ...keys: string[]
): number {
  const value = readNumber(sources, ...keys);
  if (value === undefined) {
    throw new Error(`Personel yaniti ${fieldLabel} alanini icermiyor.`);
  }
  return value;
}

function normalizeAktifDurum(value: unknown): PersonelAktifDurum | null {
  if (value === "AKTIF" || value === "PASIF") {
    return value;
  }

  return null;
}

function normalizePersonel(data: unknown): Personel {
  const root = toRecord(data);
  if (!root) {
    throw new Error("Personel yaniti beklenen formatta degil.");
  }

  const anaKart = toRecord(root.ana_kart) ?? root;
  const sistemOzeti = toRecord(root.sistem_ozeti);
  const pasiflikDurumu = toRecord(root.pasiflik_durumu);
  const referansAdlari = toRecord(root.referans_adlari);

  const baseSources = [anaKart, root];
  const summarySources = [sistemOzeti, root];
  const referenceSources = [referansAdlari, root];
  const aktifDurum = normalizeAktifDurum(
    pickValue([pasiflikDurumu, ...baseSources], ["aktif_durum"])
  );

  if (!aktifDurum) {
    throw new Error("Personel yaniti aktif_durum alanini icermiyor.");
  }

  return {
    id: readRequiredNumber(baseSources, "id", "id"),
    tc_kimlik_no: readRequiredString(baseSources, "tc_kimlik_no", "tc_kimlik_no"),
    ad: readRequiredString(baseSources, "ad", "ad"),
    soyad: readRequiredString(baseSources, "soyad", "soyad"),
    aktif_durum: aktifDurum,
    sube_id: readNumber(baseSources, "sube_id"),
    telefon: readString(baseSources, "telefon"),
    dogum_tarihi: readString(baseSources, "dogum_tarihi"),
    sicil_no: readString(baseSources, "sicil_no"),
    dogum_yeri: readString(baseSources, "dogum_yeri"),
    kan_grubu: readString(baseSources, "kan_grubu"),
    ise_giris_tarihi: readString(baseSources, "ise_giris_tarihi"),
    acil_durum_kisi: readString(baseSources, "acil_durum_kisi"),
    acil_durum_telefon: readString(baseSources, "acil_durum_telefon"),
    departman_id: readNumber(baseSources, "departman_id"),
    gorev_id: readNumber(baseSources, "gorev_id"),
    personel_tipi_id: readNumber(baseSources, "personel_tipi_id"),
    bagli_amir_id: readNumber(baseSources, "bagli_amir_id"),
    sube_adi: readString(referenceSources, "sube", "sube_adi", "subeAdi"),
    departman_adi: readString(referenceSources, "departman", "departman_adi", "departmanAdi"),
    gorev_adi: readString(referenceSources, "gorev", "gorev_adi", "gorevAdi"),
    personel_tipi_adi: readString(
      referenceSources,
      "personel_tipi",
      "personel_tipi_adi",
      "personelTipi",
      "personelTipiAdi"
    ),
    bagli_amir_adi: readString(
      referenceSources,
      "bagli_amir",
      "bagli_amir_adi",
      "bagliAmir",
      "bagliAmirAdi"
    ),
    hizmet_suresi: readString(summarySources, "hizmet_suresi"),
    toplam_izin_hakki: readNumber(summarySources, "toplam_izin_hakki"),
    kullanilan_izin: readNumber(summarySources, "kullanilan_izin"),
    kalan_izin: readNumber(summarySources, "kalan_izin"),
    sgk_donem: readString(summarySources, "sgk_donem"),
    sgk_prim_gun: readNumber(summarySources, "sgk_prim_gun"),
    sgk_eksik_gun_sayisi: readNumber(summarySources, "sgk_eksik_gun_sayisi"),
    sgk_eksik_gun_nedeni_kodu: readNullableString(summarySources, "sgk_eksik_gun_nedeni_kodu"),
    sgk_ayin_takvim_gun_sayisi: readNumber(summarySources, "sgk_ayin_takvim_gun_sayisi"),
    sgk_hesaplama_modu: readString(summarySources, "sgk_hesaplama_modu"),
    pasiflik_durumu_etiketi: readNullableString(
      [pasiflikDurumu, root],
      "etiket",
      "pasiflik_durumu_etiketi",
      "pasiflikDurumuEtiketi"
    )
  };
}

export async function fetchPersonellerList(
  params?: PersonellerListParams
): Promise<PaginatedResult<Personel>> {
  const path = appendQueryParams(endpoints.personeller.list, {
    search: params?.search,
    departman_id: params?.departman_id,
    sube_id: params?.sube_id,
    aktiflik: params?.aktiflik,
    personel_tipi_id: params?.personel_tipi_id,
    page: params?.page,
    limit: params?.limit
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizePaginatedList<Personel>(response, {
    requestedPage: params?.page,
    requestedLimit: params?.limit
  });
}

export async function createPersonel(payload: CreatePersonelPayload): Promise<Personel> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.list, {
    method: "POST",
    body: JSON.stringify(payload)
  });

  const created = normalizePersonel(response.data);
  logAction({ action: "PERSONEL_CREATE", payload: { personel_id: created.id } });
  return created;
}

export async function fetchPersonelDetail(personelId: number | string): Promise<Personel> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.detail(personelId));
  return normalizePersonel(response.data);
}

export async function updatePersonel(
  personelId: number | string,
  payload: UpdatePersonelPayload
): Promise<Personel> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.detail(personelId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  const updated = normalizePersonel(response.data);
  logAction({ action: "PERSONEL_UPDATE", payload: { personel_id: updated.id } });
  return updated;
}
