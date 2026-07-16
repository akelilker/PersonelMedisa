import { getActiveSubeIdForApiHeader } from "../auth/auth-manager";
import { getAuthTokenForApi } from "../auth/auth-token-provider";
import type { ApiResponse } from "../types/api";
import type { BildirimPuantajEtkiAdayState } from "../types/bildirim-puantaj-etki-aday";
import { appendQueryParams } from "../utils/append-query-params";
import { ApiRequestError, apiRequest, buildApiUrl } from "./api-client";
import { normalizePaginatedList } from "./response-normalizers";

const REPORT_PATH = "/puantaj/bildirim-etki-adaylari/rapor";

export type BildirimEtkiRaporFilters = {
  ay: string;
  sube_id: number;
  departman_id?: number;
  personel_id?: number;
  state?: BildirimPuantajEtkiAdayState | "";
  conflict_code?: string;
  etki_turu?: string;
  uygulama_modu?: string;
  karar_turu?: string;
  page?: number;
  limit?: number;
};

export type BildirimEtkiRaporRow = {
  id: number;
  personel_id: number;
  personel_ad_soyad: string;
  sicil_no: string | null;
  sube_ad: string | null;
  departman_ad: string | null;
  tarih: string;
  bildirim_turu: string;
  etki_turu: string;
  effective_miktar: number | null;
  effective_birim: string | null;
  state: string;
  conflict_code: string | null;
  mevcut_puantaj_ozet: string | null;
  uygulanan_puantaj_ozet: string | null;
  karar_turu: string | null;
  karar_veren: string | null;
  karar_zamani: string | null;
  uygulama_modu: string | null;
  projection_version: number | null;
  source_integrity: string | null;
  audit_integrity: string | null;
};

export type BildirimEtkiRaporSummary = {
  toplam_aday: number;
  otomatik_uygulanan: number;
  manuel_uygulanan: number;
  koru: number;
  revize: number;
  yok_sayilan: number;
  bekleyen: number;
  conflict_dagilimi: Record<string, number>;
  toplam_gec_kalma_dakika: number;
  toplam_erken_cikis_dakika: number;
  toplam_devamsizlik_gun: number;
};

export type BildirimEtkiRaporResult = {
  items: BildirimEtkiRaporRow[];
  summary: BildirimEtkiRaporSummary;
  page: number;
  limit: number;
  total: number;
  total_pages: number;
  has_next_page: boolean;
  has_prev_page: boolean;
};

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }
  return value as Record<string, unknown>;
}

function toNumber(value: unknown): number | null {
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

function toStringValue(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }
  const trimmed = value.trim();
  return trimmed || undefined;
}

function toStringMap(value: unknown): Record<string, number> {
  const record = toRecord(value);
  if (!record) {
    return {};
  }
  const out: Record<string, number> = {};
  Object.entries(record).forEach(([key, entry]) => {
    const parsed = toNumber(entry);
    if (parsed !== null) {
      out[key] = parsed;
    }
  });
  return out;
}

function normalizeSummary(data: unknown): BildirimEtkiRaporSummary {
  const record = toRecord(data);
  if (!record) {
    return {
      toplam_aday: 0,
      otomatik_uygulanan: 0,
      manuel_uygulanan: 0,
      koru: 0,
      revize: 0,
      yok_sayilan: 0,
      bekleyen: 0,
      conflict_dagilimi: {},
      toplam_gec_kalma_dakika: 0,
      toplam_erken_cikis_dakika: 0,
      toplam_devamsizlik_gun: 0
    };
  }

  return {
    toplam_aday: toNumber(record.toplam_aday) ?? 0,
    otomatik_uygulanan: toNumber(record.otomatik_uygulanan) ?? 0,
    manuel_uygulanan: toNumber(record.manuel_uygulanan) ?? 0,
    koru: toNumber(record.koru) ?? 0,
    revize: toNumber(record.revize) ?? 0,
    yok_sayilan: toNumber(record.yok_sayilan) ?? 0,
    bekleyen: toNumber(record.bekleyen) ?? 0,
    conflict_dagilimi: toStringMap(record.conflict_dagilimi),
    toplam_gec_kalma_dakika: toNumber(record.toplam_gec_kalma_dakika) ?? 0,
    toplam_erken_cikis_dakika: toNumber(record.toplam_erken_cikis_dakika) ?? 0,
    toplam_devamsizlik_gun: toNumber(record.toplam_devamsizlik_gun) ?? 0
  };
}

function normalizeRow(data: unknown): BildirimEtkiRaporRow {
  const record = toRecord(data);
  if (!record) {
    throw new Error("Etki adayi rapor satiri beklenen formatta degil.");
  }

  const id = toNumber(record.id);
  const personelId = toNumber(record.personel_id);
  const tarih = toStringValue(record.tarih);

  if (!id || !personelId || !tarih) {
    throw new Error("Etki adayi rapor satiri eksik alan iceriyor.");
  }

  return {
    id,
    personel_id: personelId,
    personel_ad_soyad: toStringValue(record.personel_ad_soyad) ?? `Personel #${personelId}`,
    sicil_no: toStringValue(record.sicil_no) ?? null,
    sube_ad: toStringValue(record.sube_ad) ?? null,
    departman_ad: toStringValue(record.departman_ad) ?? null,
    tarih,
    bildirim_turu: toStringValue(record.bildirim_turu) ?? "-",
    etki_turu: toStringValue(record.etki_turu) ?? "-",
    effective_miktar: toNumber(record.effective_miktar),
    effective_birim: toStringValue(record.effective_birim) ?? null,
    state: toStringValue(record.state) ?? "-",
    conflict_code: toStringValue(record.conflict_code) ?? null,
    mevcut_puantaj_ozet: toStringValue(record.mevcut_puantaj_ozet) ?? null,
    uygulanan_puantaj_ozet: toStringValue(record.uygulanan_puantaj_ozet) ?? null,
    karar_turu: toStringValue(record.karar_turu) ?? null,
    karar_veren: toStringValue(record.karar_veren) ?? null,
    karar_zamani: toStringValue(record.karar_zamani) ?? null,
    uygulama_modu: toStringValue(record.uygulama_modu) ?? null,
    projection_version: toNumber(record.projection_version),
    source_integrity: toStringValue(record.source_integrity) ?? null,
    audit_integrity: toStringValue(record.audit_integrity) ?? null
  };
}

async function downloadAuthenticatedFile(path: string, filename: string) {
  const { resolveDemoApiResponse } = await import("./mock-demo");
  const demoResponse = resolveDemoApiResponse(path, { method: "GET" });
  if (demoResponse !== null) {
    const csvContent =
      typeof demoResponse.data === "string"
        ? demoResponse.data
        : "id,personel_id,tarih\n1,1,2026-06-03\n";
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
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
    throw new ApiRequestError("CSV indirilemedi.", response.status);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}

function buildReportQuery(filters: BildirimEtkiRaporFilters, suffix = "") {
  return appendQueryParams(`${REPORT_PATH}${suffix}`, {
    ay: filters.ay,
    sube_id: filters.sube_id,
    departman_id: filters.departman_id,
    personel_id: filters.personel_id,
    state: filters.state || undefined,
    conflict_code: filters.conflict_code || undefined,
    etki_turu: filters.etki_turu || undefined,
    uygulama_modu: filters.uygulama_modu || undefined,
    karar_turu: filters.karar_turu || undefined,
    page: filters.page,
    limit: filters.limit
  });
}

export async function fetchBildirimEtkiRapor(filters: BildirimEtkiRaporFilters): Promise<BildirimEtkiRaporResult> {
  const response = await apiRequest<ApiResponse<unknown>>(buildReportQuery(filters));
  const dataRecord = toRecord(response.data);
  const paginated = normalizePaginatedList<unknown>(response, {
    requestedPage: filters.page,
    requestedLimit: filters.limit
  });
  const summary = normalizeSummary(dataRecord?.summary ?? response.meta);
  const pagination = paginated.pagination;

  return {
    items: paginated.items.map(normalizeRow),
    summary,
    page: pagination.page ?? filters.page ?? 1,
    limit: pagination.limit ?? filters.limit ?? 20,
    total: pagination.total ?? paginated.items.length,
    total_pages: pagination.totalPages ?? 1,
    has_next_page: pagination.hasNextPage ?? false,
    has_prev_page: pagination.hasPreviousPage ?? false
  };
}

export async function downloadBildirimEtkiRaporCsv(filters: BildirimEtkiRaporFilters): Promise<void> {
  const path = buildReportQuery(filters, "/export.csv");
  const filename = `etki-adayi-raporu-${filters.ay}.csv`;
  await downloadAuthenticatedFile(path, filename);
}
