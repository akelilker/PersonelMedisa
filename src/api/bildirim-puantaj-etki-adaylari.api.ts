import type { ApiResponse, PaginatedResult } from "../types/api";
import type {
  BildirimPuantajEtkiAdayApplyPayload,
  BildirimPuantajEtkiAdayApplyResult,
  BildirimPuantajEtkiAdayDetail,
  BildirimPuantajEtkiAdayDismissPayload,
  BildirimPuantajEtkiAdayDismissResult,
  BildirimPuantajEtkiAdayListItem,
  BildirimPuantajEtkiAdayManualApplyPayload,
  BildirimPuantajEtkiAdayManualApplyResult,
  BildirimPuantajEtkiAdayOzet,
  BildirimPuantajEtkiAdayState,
  BildirimPuantajEtkiManualKararTuru,
  BildirimPuantajEtkiUygulamaModu
} from "../types/bildirim-puantaj-etki-aday";
import { appendQueryParams } from "../utils/append-query-params";
import { apiRequest } from "./api-client";
import { endpoints } from "./endpoints";
import { normalizePaginatedList } from "./response-normalizers";

export type BildirimPuantajEtkiAdayListParams = {
  ay: string;
  birim_amiri_user_id: number;
  personel_id?: number;
  state?: BildirimPuantajEtkiAdayState | string;
  etki_turu?: string;
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

function normalizeListItem(data: unknown): BildirimPuantajEtkiAdayListItem {
  if (typeof data !== "object" || data === null) {
    throw new Error("Puantaj etki adayi listesi beklenen formatta degil.");
  }
  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const genelYoneticiBildirimOnayiId = toNumber(record.genel_yonetici_bildirim_onayi_id);
  const gunlukBildirimId = toNumber(record.gunluk_bildirim_id);
  const personelId = toNumber(record.personel_id);
  const subeId = toNumber(record.sube_id);
  const birimAmiriUserId = toNumber(record.birim_amiri_user_id);
  const ay = toStringValue(record.ay);
  const tarih = toStringValue(record.tarih);
  const bildirimTuru = toStringValue(record.bildirim_turu);
  const etkiTuru = toStringValue(record.etki_turu);
  const state = toStringValue(record.state) as BildirimPuantajEtkiAdayState | undefined;
  const sourcePriority = toStringValue(record.source_priority);
  const createdAt = toStringValue(record.created_at);

  if (
    !id ||
    !genelYoneticiBildirimOnayiId ||
    !gunlukBildirimId ||
    !personelId ||
    !subeId ||
    !birimAmiriUserId ||
    !ay ||
    !tarih ||
    !bildirimTuru ||
    !etkiTuru ||
    !state ||
    !sourcePriority ||
    !createdAt
  ) {
    throw new Error("Puantaj etki adayi listesi eksik alan iceriyor.");
  }

  const etkiMiktariRaw = record.etki_miktari;
  const etkiMiktari =
    etkiMiktariRaw === null || etkiMiktariRaw === undefined ? null : toNumber(etkiMiktariRaw) ?? null;

  return {
    id,
    genel_yonetici_bildirim_onayi_id: genelYoneticiBildirimOnayiId,
    gunluk_bildirim_id: gunlukBildirimId,
    personel_id: personelId,
    sube_id: subeId,
    birim_amiri_user_id: birimAmiriUserId,
    ay,
    tarih,
    bildirim_turu: bildirimTuru,
    etki_turu: etkiTuru,
    etki_miktari: etkiMiktari,
    etki_birimi: record.etki_birimi === null ? null : toStringValue(record.etki_birimi) ?? null,
    state,
    conflict_code: record.conflict_code === null ? null : toStringValue(record.conflict_code) ?? null,
    source_priority: sourcePriority,
    created_at: createdAt,
    karar_veren_user_id:
      record.karar_veren_user_id === null ? null : toNumber(record.karar_veren_user_id) ?? null,
    karar_zamani: record.karar_zamani === null ? null : toStringValue(record.karar_zamani) ?? null,
    uygulanan_puantaj_id:
      record.uygulanan_puantaj_id === null ? null : toNumber(record.uygulanan_puantaj_id) ?? null,
    uygulama_modu: (toStringValue(record.uygulama_modu) ?? "OTOMATIK") as BildirimPuantajEtkiUygulamaModu,
    manuel_karar_turu:
      record.manuel_karar_turu === null
        ? null
        : (toStringValue(record.manuel_karar_turu) as BildirimPuantajEtkiManualKararTuru | undefined) ?? null,
    manuel_karar_miktari:
      record.manuel_karar_miktari === null || record.manuel_karar_miktari === undefined
        ? null
        : toNumber(record.manuel_karar_miktari) ?? null
  };
}

function normalizeJsonObject(value: unknown): Record<string, unknown> | null {
  if (value === null || value === undefined) {
    return null;
  }
  if (typeof value === "object" && !Array.isArray(value)) {
    return value as Record<string, unknown>;
  }
  return null;
}

function normalizeDetail(data: unknown): BildirimPuantajEtkiAdayDetail {
  const base = normalizeListItem(data);
  if (typeof data !== "object" || data === null) {
    throw new Error("Puantaj etki adayi detayi beklenen formatta degil.");
  }
  const record = data as Record<string, unknown>;
  const aylikBildirimOnayiId = toNumber(record.aylik_bildirim_onayi_id);
  const bildirimCreatedAt = toStringValue(record.bildirim_created_at);
  const bildirimUpdatedAt = toStringValue(record.bildirim_updated_at);
  const updatedAt = toStringValue(record.updated_at);

  if (!aylikBildirimOnayiId || !bildirimCreatedAt || !bildirimUpdatedAt || !updatedAt) {
    throw new Error("Puantaj etki adayi detayi eksik alan iceriyor.");
  }

  return {
    ...base,
    aylik_bildirim_onayi_id: aylikBildirimOnayiId,
    bildirim_alt_tur: record.bildirim_alt_tur === null ? null : toStringValue(record.bildirim_alt_tur) ?? null,
    bildirim_dakika:
      record.bildirim_dakika === null || record.bildirim_dakika === undefined
        ? null
        : toNumber(record.bildirim_dakika) ?? null,
    bildirim_aciklama:
      record.bildirim_aciklama === null ? null : toStringValue(record.bildirim_aciklama) ?? null,
    bildirim_created_at: bildirimCreatedAt,
    bildirim_updated_at: bildirimUpdatedAt,
    conflict_detail: normalizeJsonObject(record.conflict_detail),
    resmi_surec_id: record.resmi_surec_id === null ? null : toNumber(record.resmi_surec_id) ?? null,
    resmi_surec_turu: record.resmi_surec_turu === null ? null : toStringValue(record.resmi_surec_turu) ?? null,
    resmi_surec_alt_tur:
      record.resmi_surec_alt_tur === null ? null : toStringValue(record.resmi_surec_alt_tur) ?? null,
    ucretli_mi_snapshot:
      record.ucretli_mi_snapshot === null || record.ucretli_mi_snapshot === undefined
        ? null
        : Boolean(record.ucretli_mi_snapshot),
    mevcut_puantaj_id:
      record.mevcut_puantaj_id === null ? null : toNumber(record.mevcut_puantaj_id) ?? null,
    source_snapshot: normalizeJsonObject(record.source_snapshot),
    source_hash: record.source_hash === null ? null : toStringValue(record.source_hash) ?? null,
    projection_version:
      record.projection_version === null ? null : toStringValue(record.projection_version) ?? null,
    updated_at: updatedAt,
    karar_gerekcesi:
      record.karar_gerekcesi === null ? null : toStringValue(record.karar_gerekcesi) ?? null,
    onceki_puantaj_snapshot: normalizeJsonObject(record.onceki_puantaj_snapshot),
    sonraki_puantaj_snapshot: normalizeJsonObject(record.sonraki_puantaj_snapshot),
    uygulama_hash: record.uygulama_hash === null ? null : toStringValue(record.uygulama_hash) ?? null
  };
}

function normalizeOzet(data: unknown): BildirimPuantajEtkiAdayOzet {
  if (typeof data !== "object" || data === null) {
    throw new Error("Puantaj etki adayi ozeti beklenen formatta degil.");
  }
  const record = data as Record<string, unknown>;
  const contextRaw = record.context;
  const countsRaw = record.aday_sayilari;
  if (typeof contextRaw !== "object" || contextRaw === null || typeof countsRaw !== "object" || countsRaw === null) {
    throw new Error("Puantaj etki adayi ozeti eksik alan iceriyor.");
  }
  const context = contextRaw as Record<string, unknown>;
  const counts = countsRaw as Record<string, unknown>;
  const gyOnayRaw = record.genel_yonetici_bildirim_onayi;

  return {
    context: {
      genel_yonetici_bildirim_onayi_id: toNumber(context.genel_yonetici_bildirim_onayi_id) ?? 0,
      ay: context.ay === null ? null : toStringValue(context.ay) ?? null,
      ay_baslangic: context.ay_baslangic === null ? null : toStringValue(context.ay_baslangic) ?? null,
      ay_bitis: context.ay_bitis === null ? null : toStringValue(context.ay_bitis) ?? null,
      sube_id: context.sube_id === null ? null : toNumber(context.sube_id) ?? null,
      birim_amiri_user_id:
        context.birim_amiri_user_id === null ? null : toNumber(context.birim_amiri_user_id) ?? null,
      aylik_bildirim_onayi_id:
        context.aylik_bildirim_onayi_id === null ? null : toNumber(context.aylik_bildirim_onayi_id) ?? null,
      onaylandi_at: context.onaylandi_at === null ? null : toStringValue(context.onaylandi_at) ?? null
    },
    genel_yonetici_bildirim_onayi:
      gyOnayRaw && typeof gyOnayRaw === "object"
        ? {
            id: toNumber((gyOnayRaw as Record<string, unknown>).id) ?? 0,
            state: toStringValue((gyOnayRaw as Record<string, unknown>).state) ?? "—",
            onaylandi_at:
              (gyOnayRaw as Record<string, unknown>).onaylandi_at === null
                ? null
                : toStringValue((gyOnayRaw as Record<string, unknown>).onaylandi_at) ?? null
          }
        : null,
    kaynak_bildirim_sayisi: toNumber(record.kaynak_bildirim_sayisi) ?? 0,
    aday_sayilari: {
      toplam: toNumber(counts.toplam) ?? 0,
      hazir: toNumber(counts.hazir) ?? 0,
      inceleme_gerekli: toNumber(counts.inceleme_gerekli) ?? 0,
      uygulandi: toNumber(counts.uygulandi) ?? 0,
      yok_sayildi: toNumber(counts.yok_sayildi) ?? 0
    },
    muhur_durumu: toStringValue(record.muhur_durumu) === "MUHURLENDI" ? "MUHURLENDI" : "ACIK",
    hazirlanabilir_mi: Boolean(record.hazirlanabilir_mi),
    blok_nedeni: record.blok_nedeni === null ? null : toStringValue(record.blok_nedeni) ?? null
  };
}

function normalizeDismissResult(data: unknown): BildirimPuantajEtkiAdayDismissResult {
  if (typeof data !== "object" || data === null) {
    throw new Error("Puantaj etki adayi yok sayma yaniti beklenen formatta degil.");
  }
  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const state = toStringValue(record.state) as BildirimPuantajEtkiAdayState | undefined;
  if (!id || !state) {
    throw new Error("Puantaj etki adayi yok sayma yaniti eksik alan iceriyor.");
  }
  return {
    id,
    state,
    karar_veren_user_id:
      record.karar_veren_user_id === null ? null : toNumber(record.karar_veren_user_id) ?? null,
    karar_zamani: record.karar_zamani === null ? null : toStringValue(record.karar_zamani) ?? null,
    karar_gerekcesi:
      record.karar_gerekcesi === null ? null : toStringValue(record.karar_gerekcesi) ?? null,
    uygulanan_puantaj_id:
      record.uygulanan_puantaj_id === null ? null : toNumber(record.uygulanan_puantaj_id) ?? null,
    idempotent: Boolean(record.idempotent)
  };
}

function normalizeApplyResult(data: unknown): BildirimPuantajEtkiAdayApplyResult {
  if (typeof data !== "object" || data === null) {
    throw new Error("Puantaj etki adayi uygula yaniti beklenen formatta degil.");
  }
  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const state = toStringValue(record.state) as BildirimPuantajEtkiAdayState | undefined;
  if (!id || !state) {
    throw new Error("Puantaj etki adayi uygula yaniti eksik alan iceriyor.");
  }
  return {
    id,
    state,
    karar_veren_user_id:
      record.karar_veren_user_id === null ? null : toNumber(record.karar_veren_user_id) ?? null,
    karar_zamani: record.karar_zamani === null ? null : toStringValue(record.karar_zamani) ?? null,
    uygulanan_puantaj_id:
      record.uygulanan_puantaj_id === null ? null : toNumber(record.uygulanan_puantaj_id) ?? null,
    onceki_puantaj_snapshot: normalizeJsonObject(record.onceki_puantaj_snapshot),
    sonraki_puantaj_snapshot: normalizeJsonObject(record.sonraki_puantaj_snapshot),
    uygulama_hash: record.uygulama_hash === null ? null : toStringValue(record.uygulama_hash) ?? null,
    uygulama_modu: (toStringValue(record.uygulama_modu) ?? "OTOMATIK") as BildirimPuantajEtkiUygulamaModu,
    manuel_karar_turu:
      record.manuel_karar_turu === null
        ? null
        : (toStringValue(record.manuel_karar_turu) as BildirimPuantajEtkiManualKararTuru | undefined) ?? null,
    manuel_karar_miktari:
      record.manuel_karar_miktari === null || record.manuel_karar_miktari === undefined
        ? null
        : toNumber(record.manuel_karar_miktari) ?? null,
    idempotent: Boolean(record.idempotent)
  };
}

export async function fetchBildirimPuantajEtkiAdayList(
  params: BildirimPuantajEtkiAdayListParams,
  context?: { subeId?: number | null }
): Promise<PaginatedResult<BildirimPuantajEtkiAdayListItem>> {
  const path = appendQueryParams(endpoints.puantaj.bildirimEtkiAdaylari.list, {
    ay: params.ay,
    birim_amiri_user_id: params.birim_amiri_user_id,
    personel_id: params.personel_id,
    state: params.state,
    etki_turu: params.etki_turu,
    page: params.page,
    limit: params.limit,
    sube_id: context?.subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  const normalized = normalizePaginatedList<unknown>(response, {
    requestedPage: params.page,
    requestedLimit: params.limit
  });
  return {
    items: normalized.items.map((item) => normalizeListItem(item)),
    pagination: normalized.pagination
  };
}

export async function fetchBildirimPuantajEtkiAdayDetail(
  id: number | string,
  context?: { subeId?: number | null }
): Promise<BildirimPuantajEtkiAdayDetail> {
  const path = appendQueryParams(endpoints.puantaj.bildirimEtkiAdaylari.detail(id), {
    sube_id: context?.subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizeDetail(response.data);
}

export async function fetchBildirimPuantajEtkiAdayOzet(
  genelYoneticiBildirimOnayiId: number,
  context?: { subeId?: number | null }
): Promise<BildirimPuantajEtkiAdayOzet> {
  const path = appendQueryParams(endpoints.puantaj.bildirimEtkiAdaylari.ozet, {
    genel_yonetici_bildirim_onayi_id: genelYoneticiBildirimOnayiId,
    sube_id: context?.subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path);
  return normalizeOzet(response.data);
}

export async function dismissBildirimPuantajEtkiAday(
  id: number | string,
  payload: BildirimPuantajEtkiAdayDismissPayload,
  context?: { subeId?: number | null }
): Promise<BildirimPuantajEtkiAdayDismissResult> {
  const path = appendQueryParams(endpoints.puantaj.bildirimEtkiAdaylari.yokSay(id), {
    sube_id: context?.subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path, {
    method: "POST",
    body: JSON.stringify({
      expected_state: payload.expected_state,
      gerekce: payload.gerekce.trim()
    })
  });
  return normalizeDismissResult(response.data);
}

export async function applyBildirimPuantajEtkiAday(
  id: number | string,
  payload: BildirimPuantajEtkiAdayApplyPayload,
  context?: { subeId?: number | null }
): Promise<BildirimPuantajEtkiAdayApplyResult> {
  const path = appendQueryParams(endpoints.puantaj.bildirimEtkiAdaylari.uygula(id), {
    sube_id: context?.subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path, {
    method: "POST",
    body: JSON.stringify({
      expected_state: payload.expected_state
    })
  });
  return normalizeApplyResult(response.data);
}

export async function manuelUygulaBildirimPuantajEtkiAdayi(
  id: number | string,
  payload: BildirimPuantajEtkiAdayManualApplyPayload,
  context?: { subeId?: number | null }
): Promise<BildirimPuantajEtkiAdayManualApplyResult> {
  const path = appendQueryParams(endpoints.puantaj.bildirimEtkiAdaylari.manuelUygula(id), {
    sube_id: context?.subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path, {
    method: "POST",
    body: JSON.stringify({
      expected_state: payload.expected_state,
      karar_etki_turu: payload.karar_etki_turu,
      etki_miktari: payload.etki_miktari,
      gerekce: payload.gerekce.trim()
    })
  });
  const result = normalizeApplyResult(response.data);
  const record = response.data as Record<string, unknown>;
  return {
    ...result,
    karar_gerekcesi:
      record.karar_gerekcesi === null ? null : toStringValue(record.karar_gerekcesi) ?? null
  };
}
