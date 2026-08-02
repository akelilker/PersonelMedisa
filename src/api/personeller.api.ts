import type { ApiResponse, PaginatedResult } from "../types/api";
import type { Personel, PersonelAktifDurum } from "../types/personel";
import { appendQueryParams } from "../utils/append-query-params";
import { logAction } from "../audit/audit-service";
import { ApiRequestError, apiRequest } from "./api-client";
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
  sube_id: number;
  departman_id: number;
  gorev_id: number;
  personel_tipi_id: number;
  aktif_durum: "AKTIF" | "PASIF";
  dogum_yeri?: string;
  kan_grubu?: string;
  bagli_amir_id?: number;
  ucret_tipi_id?: number;
  net_maas_tutari?: number;
  maas_tutari?: number;
  prim_kurali_id?: number;
};

export type UpdatePersonelPayload = Omit<
  Partial<CreatePersonelPayload>,
  "departman_id" | "gorev_id" | "bagli_amir_id"
> & {
  departman_id?: number | null;
  gorev_id?: number | null;
  bagli_amir_id?: number | null;
  ucret_tipi_id?: number | null;
  net_maas_tutari?: number | null;
  maas_tutari?: number | null;
  prim_kurali_id?: number | null;
  effective_date?: string;
};

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

function readNullableNumberValue(value: unknown): number | null | undefined {
  if (value === null) {
    return null;
  }

  return readNumberValue(value);
}

function readNullableNumber(
  sources: Array<Record<string, unknown> | null>,
  ...keys: string[]
): number | null | undefined {
  return readNullableNumberValue(pickValue(sources, keys));
}

function resolveMaasFields(sources: Array<Record<string, unknown> | null>): {
  net_maas_tutari?: number;
  maas_tutari?: number;
} {
  const rawNet = readNumber(sources, "net_maas_tutari", "netMaasTutari");
  const rawMaas = readNumber(sources, "maas_tutari", "maasTutari");
  const net_maas_tutari = rawNet ?? rawMaas;
  const maas_tutari = rawMaas ?? rawNet;

  return {
    ...(net_maas_tutari !== undefined ? { net_maas_tutari } : {}),
    ...(maas_tutari !== undefined ? { maas_tutari } : {})
  };
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

  const maasFields = resolveMaasFields(baseSources);
  const brut_maas_tutari = readNullableNumber(baseSources, "brut_maas_tutari", "brutMaasTutari");
  const brut_hesaplama_modeli = readNullableString(
    baseSources,
    "brut_hesaplama_modeli",
    "brutHesaplamaModeli"
  );
  const brut_hesaplama_donemi = readNullableString(
    baseSources,
    "brut_hesaplama_donemi",
    "brutHesaplamaDonemi"
  );
  const model_versiyonu = readNullableString(baseSources, "model_versiyonu", "modelVersiyonu");

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
    ),
    ucret_tipi_id: readNumber(baseSources, "ucret_tipi_id", "ucretTipiId"),
    ucret_tipi_adi: readString(
      [...baseSources, ...referenceSources],
      "ucret_tipi_adi",
      "ucretTipiAdi",
      "ucret_tipi"
    ),
    ...maasFields,
    ...(brut_maas_tutari !== undefined ? { brut_maas_tutari } : {}),
    ...(brut_hesaplama_modeli !== undefined ? { brut_hesaplama_modeli } : {}),
    ...(brut_hesaplama_donemi !== undefined ? { brut_hesaplama_donemi } : {}),
    ...(model_versiyonu !== undefined ? { model_versiyonu } : {}),
    prim_kurali_id: readNumber(baseSources, "prim_kurali_id", "primKuraliId"),
    prim_kurali_adi: readString(
      [...baseSources, ...referenceSources],
      "prim_kurali_adi",
      "primKuraliAdi",
      "prim_kurali"
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

export type PersonelImportDryRunRow = {
  satir_no: number;
  sicil_no: string;
  tc_kimlik_no_masked: string;
  durum: "GECERLI" | "HATALI" | "MEVCUT" | string;
  hata_kodlari: string[];
  uyarilar: string[];
};

export type PersonelImportDryRunResult = {
  ozet: {
    toplam_satir: number;
    gecerli_satir: number;
    hatali_satir: number;
    warning_sayisi: number;
    kayit_olusturulacak_aday: number;
    veritabaninda_mevcut: number;
  };
  satirlar: PersonelImportDryRunRow[];
  source_sha256: string;
  manifest_hash: string;
  schema_version: string;
  row_count: number;
  valid_row_count: number;
  can_apply: boolean;
  yazma: {
    personel_write: boolean;
    salary_write: boolean;
    wage_model_assumption: boolean;
  };
};

export type PersonelImportApplyCreatedRow = {
  satir_no: number;
  personel_id: number;
  sicil_no: string;
  ad: string;
  soyad: string;
  tc_kimlik_no_masked: string;
};

export type PersonelImportApplyResult = {
  import_id: number;
  status: "COMPLETED" | string;
  idempotent_replay: boolean;
  source_sha256: string;
  manifest_hash: string;
  created_count: number;
  created: PersonelImportApplyCreatedRow[];
  yazma: {
    personel_write: boolean;
    salary_write: boolean;
    bordro_scope_write: boolean;
    carryover_write: boolean;
    sgk_status_write: boolean;
    wage_model_assumption: boolean;
  };
};

function normalizeImportDryRunResult(value: unknown): PersonelImportDryRunResult {
  const record = toRecord(value) ?? {};
  const ozet = toRecord(record.ozet) ?? {};
  const yazma = toRecord(record.yazma) ?? {};
  const satirlarRaw = Array.isArray(record.satirlar) ? record.satirlar : [];
  const toplam = Number(ozet.toplam_satir ?? 0);
  const gecerli = Number(ozet.gecerli_satir ?? 0);
  const hatali = Number(ozet.hatali_satir ?? 0);

  return {
    ozet: {
      toplam_satir: toplam,
      gecerli_satir: gecerli,
      hatali_satir: hatali,
      warning_sayisi: Number(ozet.warning_sayisi ?? 0),
      kayit_olusturulacak_aday: Number(ozet.kayit_olusturulacak_aday ?? 0),
      veritabaninda_mevcut: Number(ozet.veritabaninda_mevcut ?? 0)
    },
    satirlar: satirlarRaw.map((row) => {
      const r = toRecord(row) ?? {};
      return {
        satir_no: Number(r.satir_no ?? 0),
        sicil_no: String(r.sicil_no ?? ""),
        tc_kimlik_no_masked: String(r.tc_kimlik_no_masked ?? "***********"),
        durum: String(r.durum ?? "HATALI"),
        hata_kodlari: Array.isArray(r.hata_kodlari)
          ? r.hata_kodlari.map((code) => String(code))
          : [],
        uyarilar: Array.isArray(r.uyarilar) ? r.uyarilar.map((u) => String(u)) : []
      };
    }),
    source_sha256: String(record.source_sha256 ?? ""),
    manifest_hash: String(record.manifest_hash ?? ""),
    schema_version: String(record.schema_version ?? ""),
    row_count: Number(record.row_count ?? toplam),
    valid_row_count: Number(record.valid_row_count ?? gecerli),
    can_apply: Boolean(record.can_apply ?? (toplam > 0 && hatali === 0 && gecerli === toplam)),
    yazma: {
      personel_write: Boolean(yazma.personel_write),
      salary_write: Boolean(yazma.salary_write),
      wage_model_assumption: Boolean(yazma.wage_model_assumption)
    }
  };
}

function normalizeImportApplyResult(value: unknown): PersonelImportApplyResult {
  const record = toRecord(value) ?? {};
  const yazma = toRecord(record.yazma) ?? {};
  const createdRaw = Array.isArray(record.created) ? record.created : [];

  return {
    import_id: Number(record.import_id ?? 0),
    status: String(record.status ?? ""),
    idempotent_replay: Boolean(record.idempotent_replay),
    source_sha256: String(record.source_sha256 ?? ""),
    manifest_hash: String(record.manifest_hash ?? ""),
    created_count: Number(record.created_count ?? 0),
    created: createdRaw.map((row) => {
      const r = toRecord(row) ?? {};
      return {
        satir_no: Number(r.satir_no ?? 0),
        personel_id: Number(r.personel_id ?? 0),
        sicil_no: String(r.sicil_no ?? ""),
        ad: String(r.ad ?? ""),
        soyad: String(r.soyad ?? ""),
        tc_kimlik_no_masked: String(r.tc_kimlik_no_masked ?? "***********")
      };
    }),
    yazma: {
      personel_write: Boolean(yazma.personel_write),
      salary_write: Boolean(yazma.salary_write),
      bordro_scope_write: Boolean(yazma.bordro_scope_write),
      carryover_write: Boolean(yazma.carryover_write),
      sgk_status_write: Boolean(yazma.sgk_status_write),
      wage_model_assumption: Boolean(yazma.wage_model_assumption)
    }
  };
}

export async function downloadPersonelImportTemplateCsv(): Promise<void> {
  const { ApiRequestError, buildApiUrl, shouldPreferDemoApi } = await import("./api-client");
  const { getAuthTokenForApi } = await import("../auth/auth-token-provider");
  const { getActiveSubeIdForApiHeader } = await import("../auth/auth-manager");
  const filename = "personel-import-sablon.csv";

  if (shouldPreferDemoApi()) {
    const { resolveDemoApiResponse } = await import("./mock-demo");
    const demoResponse = resolveDemoApiResponse(endpoints.personeller.importTemplate, {
      method: "GET"
    });
    if (demoResponse !== null) {
      const csvContent =
        typeof demoResponse.data === "string"
          ? demoResponse.data
          : "tc_kimlik_no;sicil_no;ad;soyad;dogum_tarihi;dogum_yeri;telefon;kan_grubu;acil_durum_kisi;acil_durum_telefon;ise_giris_tarihi;sube;departman;gorev;personel_tipi\r\n";
      const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8" });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = filename;
      anchor.click();
      URL.revokeObjectURL(url);
      return;
    }
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

  const response = await fetch(buildApiUrl(endpoints.personeller.importTemplate), { headers });
  if (!response.ok) {
    throw new ApiRequestError("Personel import şablonu indirilemedi.", response.status);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

export async function dryRunPersonelImport(file: File): Promise<PersonelImportDryRunResult> {
  const csv = await file.text();
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.importDryRun, {
    method: "POST",
    body: JSON.stringify({ csv })
  });

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    const first = response.errors[0];
    throw new ApiRequestError(first?.message ?? "Personel import dry-run başarısız.", 400, first);
  }

  return normalizeImportDryRunResult(response.data);
}

export async function applyPersonelImport(
  file: File,
  input: {
    manifest_hash: string;
    source_sha256: string;
    idempotency_key: string;
    confirmation: "PERSONEL_IMPORT_ONAYLIYORUM";
  }
): Promise<PersonelImportApplyResult> {
  const csv = await file.text();
  const response = await apiRequest<ApiResponse<unknown>>(endpoints.personeller.importApply, {
    method: "POST",
    body: JSON.stringify({
      csv,
      manifest_hash: input.manifest_hash,
      source_sha256: input.source_sha256,
      idempotency_key: input.idempotency_key,
      confirmation: input.confirmation
    })
  });

  if (Array.isArray(response.errors) && response.errors.length > 0) {
    const first = response.errors[0];
    throw new ApiRequestError(first?.message ?? "Personel import apply başarısız.", 400, first);
  }

  return normalizeImportApplyResult(response.data);
}
