import type { ApiResponse } from "../types/api";
import type {
  AylikOzetFilters,
  AylikOzetResponse,
  AylikOzetRow,
  AylikOzetSummary,
  KayitDurumu,
  KullaniciTipi,
  UpsertYonetimKullaniciPayload,
  UpsertYonetimSubePayload,
  YonetimKullanici,
  YonetimSube
} from "../types/yonetim";
import type { UserRole } from "../types/auth";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { extractListItems } from "./response-normalizers";

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function readString(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }

  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : undefined;
}

function readStringOrNull(value: unknown): string | null {
  if (value === null) {
    return null;
  }

  return readString(value) ?? null;
}

function readNumber(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string" && value.trim() !== "") {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return undefined;
}

function readBoolean(value: unknown): boolean {
  if (typeof value === "boolean") {
    return value;
  }

  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase();
    return normalized === "true" || normalized === "1" || normalized === "evet";
  }

  return false;
}

function normalizeKayitDurumu(value: unknown): KayitDurumu {
  return value === "PASIF" ? "PASIF" : "AKTIF";
}

function normalizeKullaniciTipi(value: unknown): KullaniciTipi {
  return value === "HARICI" ? "HARICI" : "IC_PERSONEL";
}

function normalizeUserRole(value: unknown): UserRole {
  if (
    value === "GENEL_YONETICI" ||
    value === "BOLUM_YONETICISI" ||
    value === "MUHASEBE" ||
    value === "BIRIM_AMIRI"
  ) {
    return value;
  }

  return "BIRIM_AMIRI";
}

function readNumberArray(value: unknown): number[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .map((item) => readNumber(item))
    .filter((item): item is number => typeof item === "number");
}

function normalizeYonetimKullanici(data: unknown): YonetimKullanici {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Kullanici yaniti beklenen formatta degil.");
  }

  const id = readNumber(record.id);
  const adSoyad = readString(record.ad_soyad);
  if (!id || !adSoyad) {
    throw new Error("Kullanici yaniti zorunlu alanlari icermiyor.");
  }

  return {
    id,
    ad_soyad: adSoyad,
    telefon: readString(record.telefon),
    kullanici_tipi: normalizeKullaniciTipi(record.kullanici_tipi),
    rol: normalizeUserRole(record.rol),
    personel_id: readNumber(record.personel_id) ?? null,
    personel_ad_soyad: readStringOrNull(record.personel_ad_soyad),
    sube_ids: readNumberArray(record.sube_ids),
    varsayilan_sube_id: readNumber(record.varsayilan_sube_id) ?? null,
    durum: normalizeKayitDurumu(record.durum),
    notlar: readString(record.notlar)
  };
}

function normalizeYonetimSube(data: unknown): YonetimSube {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Sube yaniti beklenen formatta degil.");
  }

  const id = readNumber(record.id);
  const kod = readString(record.kod);
  const ad = readString(record.ad);
  if (!id || !kod || !ad) {
    throw new Error("Sube yaniti zorunlu alanlari icermiyor.");
  }

  return {
    id,
    kod,
    ad,
    departmanlar: Array.isArray(record.departmanlar)
      ? record.departmanlar
          .map((item) => readString(item))
          .filter((item): item is string => typeof item === "string")
      : [],
    durum: normalizeKayitDurumu(record.durum)
  };
}

function normalizeAylikOzetSummary(data: unknown): AylikOzetSummary {
  const record = toRecord(data);
  return {
    toplam_personel: readNumber(record?.toplam_personel) ?? 0,
    toplam_devamsizlik_gun: readNumber(record?.toplam_devamsizlik_gun) ?? 0,
    toplam_gec_kalma: readNumber(record?.toplam_gec_kalma) ?? 0,
    toplam_izinli_gelmedi: readNumber(record?.toplam_izinli_gelmedi) ?? 0,
    toplam_izinsiz_gelmedi: readNumber(record?.toplam_izinsiz_gelmedi) ?? 0,
    toplam_raporlu: readNumber(record?.toplam_raporlu) ?? 0,
    toplam_tesvik_tutari: readNumber(record?.toplam_tesvik_tutari) ?? 0,
    toplam_ceza_kesinti_tutari: readNumber(record?.toplam_ceza_kesinti_tutari) ?? 0
  };
}

function normalizeAylikOzetRow(data: unknown): AylikOzetRow {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Aylik ozet satiri beklenen formatta degil.");
  }

  const personelId = readNumber(record.personel_id);
  const adSoyad = readString(record.ad_soyad);
  if (!personelId || !adSoyad) {
    throw new Error("Aylik ozet satiri zorunlu alanlari icermiyor.");
  }

  return {
    personel_id: personelId,
    ad_soyad: adSoyad,
    sicil_no: readString(record.sicil_no),
    sube: readString(record.sube) ?? "-",
    bolum: readString(record.bolum) ?? "-",
    birim_amiri: readString(record.birim_amiri) ?? "-",
    devamsizlik_gun: readNumber(record.devamsizlik_gun) ?? 0,
    gec_kalma_adet: readNumber(record.gec_kalma_adet) ?? 0,
    izinli_gelmedi: readNumber(record.izinli_gelmedi) ?? 0,
    izinsiz_gelmedi: readNumber(record.izinsiz_gelmedi) ?? 0,
    raporlu: readNumber(record.raporlu) ?? 0,
    tesvik_tutari: readNumber(record.tesvik_tutari) ?? 0,
    ceza_kesinti_tutari: readNumber(record.ceza_kesinti_tutari) ?? 0,
    bolum_onay_durumu:
      record.bolum_onay_durumu === "BOLUM_ONAYLANDI" ||
      record.bolum_onay_durumu === "KAPANDI" ||
      record.bolum_onay_durumu === "REVIZE_ISTENDI"
        ? record.bolum_onay_durumu
        : "BOLUM_ONAYINDA",
    revize_var_mi: readBoolean(record.revize_var_mi),
    son_islem: readString(record.son_islem) ?? "-",
    kapanis_durumu: record.kapanis_durumu === "KAPANDI" ? "KAPANDI" : "ACIK"
  };
}

export async function fetchYonetimKullanicilari(): Promise<YonetimKullanici[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullanicilar);
  return extractListItems(response.data).map(normalizeYonetimKullanici);
}

export async function createYonetimKullanici(
  payload: UpsertYonetimKullaniciPayload
): Promise<YonetimKullanici> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullanicilar, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimKullanici(response.data);
}

export async function updateYonetimKullanici(
  kullaniciId: number | string,
  payload: UpsertYonetimKullaniciPayload
): Promise<YonetimKullanici> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.kullaniciDetail(kullaniciId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimKullanici(response.data);
}

export async function fetchYonetimSubeleri(): Promise<YonetimSube[]> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.subeler);
  return extractListItems(response.data).map(normalizeYonetimSube);
}

export async function createYonetimSube(payload: UpsertYonetimSubePayload): Promise<YonetimSube> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.subeler, {
    method: "POST",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimSube(response.data);
}

export async function updateYonetimSube(
  subeId: number | string,
  payload: UpsertYonetimSubePayload
): Promise<YonetimSube> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.subeDetail(subeId), {
    method: "PUT",
    body: JSON.stringify(payload)
  });
  return normalizeYonetimSube(response.data);
}

export async function fetchAylikKapanisOzeti(filters: AylikOzetFilters): Promise<AylikOzetResponse> {
  const path = appendQueryParams(endpoints.yonetim.aylikOzet, {
    ay: filters.ay,
    sube_id: filters.sube_id,
    departman_id: filters.departman_id,
    sadece_revizeli: filters.sadece_revizeli
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const data = toRecord(response.data);

  return {
    ay: readString(data?.ay) ?? filters.ay,
    state:
      data?.state === "BOLUM_ONAYLANDI" ||
      data?.state === "REVIZE_ISTENDI" ||
      data?.state === "KAPANDI"
        ? data.state
        : "BOLUM_ONAYINDA",
    summary: normalizeAylikOzetSummary(data?.summary),
    items: extractListItems(data?.items).map(normalizeAylikOzetRow),
    pending_bolum_onayi: readNumber(data?.pending_bolum_onayi) ?? 0
  };
}

export async function bolumOnayiVer(filters: AylikOzetFilters): Promise<AylikOzetResponse> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.aylikOzetBolumOnay, {
    method: "POST",
    body: JSON.stringify(filters)
  });
  const data = toRecord(response.data);
  return {
    ay: readString(data?.ay) ?? filters.ay,
    state:
      data?.state === "BOLUM_ONAYLANDI" ||
      data?.state === "REVIZE_ISTENDI" ||
      data?.state === "KAPANDI"
        ? data.state
        : "BOLUM_ONAYINDA",
    summary: normalizeAylikOzetSummary(data?.summary),
    items: extractListItems(data?.items).map(normalizeAylikOzetRow),
    pending_bolum_onayi: readNumber(data?.pending_bolum_onayi) ?? 0
  };
}

export async function ayiKapat(filters: AylikOzetFilters): Promise<AylikOzetResponse> {
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.yonetim.aylikOzetKapat, {
    method: "POST",
    body: JSON.stringify(filters)
  });
  const data = toRecord(response.data);
  return {
    ay: readString(data?.ay) ?? filters.ay,
    state:
      data?.state === "BOLUM_ONAYLANDI" ||
      data?.state === "REVIZE_ISTENDI" ||
      data?.state === "KAPANDI"
        ? data.state
        : "BOLUM_ONAYINDA",
    summary: normalizeAylikOzetSummary(data?.summary),
    items: extractListItems(data?.items).map(normalizeAylikOzetRow),
    pending_bolum_onayi: readNumber(data?.pending_bolum_onayi) ?? 0
  };
}
