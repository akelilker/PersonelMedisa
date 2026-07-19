import type { ApiResponse, PaginatedResult } from "../types/api";
import type {
  BildirimPuantajEtkiAdayApplyPayload,
  BildirimPuantajEtkiAdayApplyResult,
  BildirimPuantajEtkiAdayConflictResolvePayload,
  BildirimPuantajEtkiAdayConflictResolveResult,
  BildirimPuantajEtkiAdayDetail,
  BildirimPuantajEtkiAdayDismissPayload,
  BildirimPuantajEtkiAdayDismissResult,
  BildirimPuantajEtkiAdayListItem,
  BildirimPuantajEtkiAdayManualApplyPayload,
  BildirimPuantajEtkiAdayManualApplyResult,
  BildirimPuantajEtkiAdayOzet,
  BildirimPuantajEtkiAdayState,
  BildirimPuantajEtkiCakismaCozumOzet,
  BildirimPuantajEtkiManualKararTuru,
  BildirimPuantajEtkiPuantajOzet,
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

function normalizePuantajOzet(data: unknown): BildirimPuantajEtkiPuantajOzet | null {
  if (typeof data !== "object" || data === null) {
    return null;
  }
  const record = data as Record<string, unknown>;
  const personelId = toNumber(record.personel_id);
  const tarih = toStringValue(record.tarih);
  if (!personelId || !tarih) {
    return null;
  }
  return {
    id: record.id === null || record.id === undefined ? null : toNumber(record.id) ?? null,
    personel_id: personelId,
    tarih,
    state: toStringValue(record.state) ?? "ACIK",
    gun_tipi: record.gun_tipi === null ? null : toStringValue(record.gun_tipi) ?? null,
    hareket_durumu: record.hareket_durumu === null ? null : toStringValue(record.hareket_durumu) ?? null,
    dayanak: record.dayanak === null ? null : toStringValue(record.dayanak) ?? null,
    hesap_etkisi: record.hesap_etkisi === null ? null : toStringValue(record.hesap_etkisi) ?? null,
    durumu_bildirdi_mi:
      record.durumu_bildirdi_mi === null || record.durumu_bildirdi_mi === undefined
        ? null
        : Boolean(record.durumu_bildirdi_mi),
    durum_bildirim_aciklamasi:
      record.durum_bildirim_aciklamasi === null
        ? null
        : toStringValue(record.durum_bildirim_aciklamasi) ?? null,
    beklenen_giris_saati:
      record.beklenen_giris_saati === null ? null : toStringValue(record.beklenen_giris_saati) ?? null,
    beklenen_cikis_saati:
      record.beklenen_cikis_saati === null ? null : toStringValue(record.beklenen_cikis_saati) ?? null,
    giris_saati: record.giris_saati === null ? null : toStringValue(record.giris_saati) ?? null,
    cikis_saati: record.cikis_saati === null ? null : toStringValue(record.cikis_saati) ?? null,
    gec_kalma_dakika:
      record.gec_kalma_dakika === null || record.gec_kalma_dakika === undefined
        ? null
        : toNumber(record.gec_kalma_dakika) ?? null,
    erken_cikis_dakika:
      record.erken_cikis_dakika === null || record.erken_cikis_dakika === undefined
        ? null
        : toNumber(record.erken_cikis_dakika) ?? null,
    gercek_mola_dakika:
      record.gercek_mola_dakika === null || record.gercek_mola_dakika === undefined
        ? null
        : toNumber(record.gercek_mola_dakika) ?? null,
    hesaplanan_mola_dakika:
      record.hesaplanan_mola_dakika === null || record.hesaplanan_mola_dakika === undefined
        ? null
        : toNumber(record.hesaplanan_mola_dakika) ?? null,
    net_calisma_suresi_dakika:
      record.net_calisma_suresi_dakika === null || record.net_calisma_suresi_dakika === undefined
        ? null
        : toNumber(record.net_calisma_suresi_dakika) ?? null,
    gunluk_brut_sure_dakika:
      record.gunluk_brut_sure_dakika === null || record.gunluk_brut_sure_dakika === undefined
        ? null
        : toNumber(record.gunluk_brut_sure_dakika) ?? null,
    hafta_tatili_hak_kazandi_mi:
      record.hafta_tatili_hak_kazandi_mi === null || record.hafta_tatili_hak_kazandi_mi === undefined
        ? null
        : Boolean(record.hafta_tatili_hak_kazandi_mi),
    kontrol_durumu: toStringValue(record.kontrol_durumu) ?? "BEKLIYOR",
    kaynak: record.kaynak === null ? null : toStringValue(record.kaynak) ?? null,
    aciklama: record.aciklama === null ? null : toStringValue(record.aciklama) ?? null,
    muhur_id: record.muhur_id === null || record.muhur_id === undefined ? null : toNumber(record.muhur_id) ?? null,
    updated_at: record.updated_at === null ? null : toStringValue(record.updated_at) ?? null
  };
}

function normalizeCakismaCozum(data: unknown): BildirimPuantajEtkiCakismaCozumOzet | null {
  if (typeof data !== "object" || data === null) {
    return null;
  }
  const record = data as Record<string, unknown>;
  const id = toNumber(record.id);
  const adayId = toNumber(record.aday_id);
  const kararVeren = toNumber(record.karar_veren_user_id);
  const kararZamani = toStringValue(record.karar_zamani);
  const kararTuru = toStringValue(record.karar_turu);
  const conflictClass = toStringValue(record.conflict_class);
  const gerekce = toStringValue(record.gerekce);
  const requestHash = toStringValue(record.request_hash);
  const sonucHash = toStringValue(record.sonuc_hash);
  if (!id || !adayId || !kararVeren || !kararZamani || !kararTuru || !conflictClass || !gerekce || !requestHash || !sonucHash) {
    return null;
  }
  return {
    id,
    aday_id: adayId,
    puantaj_id: record.puantaj_id === null ? null : toNumber(record.puantaj_id) ?? null,
    conflict_class: conflictClass,
    karar_turu: kararTuru,
    gerekce,
    request_hash: requestHash,
    sonuc_hash: sonucHash,
    karar_veren_user_id: kararVeren,
    karar_zamani: kararZamani
  };
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
    uygulama_hash: record.uygulama_hash === null ? null : toStringValue(record.uygulama_hash) ?? null,
    mevcut_puantaj: normalizePuantajOzet(record.mevcut_puantaj),
    current_puantaj_hash:
      record.current_puantaj_hash === null ? null : toStringValue(record.current_puantaj_hash) ?? null,
    conflict_class: record.conflict_class === null ? null : toStringValue(record.conflict_class) ?? null,
    conflict_default_karar:
      record.conflict_default_karar === null ? null : toStringValue(record.conflict_default_karar) ?? null,
    conflict_revise_allowed: Boolean(record.conflict_revise_allowed),
    conflict_risk: record.conflict_risk === null ? null : toStringValue(record.conflict_risk) ?? null,
    revize_onizleme: normalizeJsonObject(record.revize_onizleme),
    cakisma_cozum: normalizeCakismaCozum(record.cakisma_cozum)
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

function normalizeConflictResolveResult(data: unknown): BildirimPuantajEtkiAdayConflictResolveResult {
  if (typeof data !== "object" || data === null) {
    throw new Error("Cakisma cozum yaniti beklenen formatta degil.");
  }
  const record = data as Record<string, unknown>;
  const adayRaw = record.aday;
  if (!adayRaw) {
    throw new Error("Cakisma cozum yaniti aday bilgisi icermiyor.");
  }
  return {
    aday: normalizeDetail(adayRaw),
    puantaj: normalizePuantajOzet(record.puantaj),
    conflict_class: record.conflict_class === null ? null : toStringValue(record.conflict_class) ?? null,
    karar_turu: record.karar_turu === null ? null : toStringValue(record.karar_turu) ?? null,
    cakisma_cozum: normalizeCakismaCozum(record.cakisma_cozum),
    onceki_ozet: normalizeJsonObject(record.onceki_ozet),
    sonraki_ozet: normalizeJsonObject(record.sonraki_ozet),
    idempotent: Boolean(record.idempotent)
  };
}

export async function cakismaCozBildirimPuantajEtkiAdayi(
  id: number | string,
  payload: BildirimPuantajEtkiAdayConflictResolvePayload,
  context?: { subeId?: number | null }
): Promise<BildirimPuantajEtkiAdayConflictResolveResult> {
  const path = appendQueryParams(endpoints.puantaj.bildirimEtkiAdaylari.cakismaCoz(id), {
    sube_id: context?.subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path, {
    method: "POST",
    body: JSON.stringify({
      expected_state: payload.expected_state,
      karar_turu: payload.karar_turu,
      gerekce: payload.gerekce.trim(),
      expected_puantaj_id: payload.expected_puantaj_id,
      expected_puantaj_hash: payload.expected_puantaj_hash
    })
  });
  return normalizeConflictResolveResult(response.data);
}

export type GenerateBildirimPuantajEtkiAdaylariPayload = {
  genel_yonetici_bildirim_onayi_id: number;
};

export async function generateBildirimPuantajEtkiAdaylari(
  payload: GenerateBildirimPuantajEtkiAdaylariPayload,
  context?: { subeId?: number | null }
): Promise<unknown> {
  const path = appendQueryParams(endpoints.puantaj.bildirimEtkiAdaylari.hazirla, {
    sube_id: context?.subeId
  });
  const response = await apiRequest<ApiResponse<unknown>>(path, {
    method: "POST",
    body: JSON.stringify({
      genel_yonetici_bildirim_onayi_id: payload.genel_yonetici_bildirim_onayi_id
    })
  });
  return response.data;
}
