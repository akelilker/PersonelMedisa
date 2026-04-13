import type { Personel } from "../types/personel";
import type { Surec } from "../types/surec";
import type { FinansKalem } from "../types/finans";
import type { GunlukPuantaj } from "../types/puantaj";
import { dataCacheKeys, getActiveSube, getAppData, getAppDataRevision } from "../data/data-manager";
import { getReportCacheMeta, recordOfflineReportGeneration } from "./report-cache-meta";
import { hesaplaAylikKapanisListesi } from "../services/dashboard-rapor-servisi";

export { getReportCacheMeta };
import type { ModuleFilterBase } from "../lib/filters/module-filter-schema";
import { matchesDateRange } from "../lib/filters/module-filter-schema";

export type ReportEngineType = "personel-ozet" | "izin-durumu" | "puantaj" | "finans";

export type ReportEngineRow = Record<string, string | number | boolean | null>;

function subeForEngine(filters: ModuleFilterBase): number | null {
  if (filters.sube_id !== undefined && filters.sube_id !== null) {
    return filters.sube_id;
  }
  return getActiveSube();
}

function readCachedPersonelFirstPage(sube: number | null, filters: ModuleFilterBase): Personel[] {
  const key = dataCacheKeys.personellerList(sube, "", "tum", "", "", 1);
  const env = getAppData().cache[key];
  if (!env || typeof env.data !== "object" || env.data === null) {
    return [];
  }
  const data = env.data as { items?: Personel[] };
  let list = data.items ?? [];
  if (filters.personel_id != null) {
    list = list.filter((p) => p.id === filters.personel_id);
  }
  if (filters.durum && (filters.durum === "AKTIF" || filters.durum === "PASIF")) {
    list = list.filter((p) => p.aktif_durum === filters.durum);
  }
  return list;
}

function readCachedSureclerFirstPage(sube: number | null, filters: ModuleFilterBase): Surec[] {
  const key = dataCacheKeys.sureclerList(sube, "", "", "", "", "", 1);
  const env = getAppData().cache[key];
  if (!env || typeof env.data !== "object" || env.data === null) {
    return [];
  }
  const data = env.data as { items?: Surec[] };
  let list = data.items ?? [];
  if (filters.personel_id != null) {
    list = list.filter((s) => s.personel_id === filters.personel_id);
  }
  if (filters.durum) {
    const d = filters.durum;
    list = list.filter((s) => (s.state ?? "").toUpperCase() === d.toUpperCase());
  }
  list = list.filter((s) => matchesDateRange(s.baslangic_tarihi ?? s.bitis_tarihi, filters.date_range));
  return list;
}

function readCachedFinansFirstPage(sube: number | null, filters: ModuleFilterBase): FinansKalem[] {
  const key = dataCacheKeys.finansList(sube, "", "", "", "", 1);
  const env = getAppData().cache[key];
  if (!env || typeof env.data !== "object" || env.data === null) {
    return [];
  }
  const data = env.data as { items?: FinansKalem[] };
  let list = data.items ?? [];
  if (filters.personel_id != null) {
    list = list.filter((f) => f.personel_id === filters.personel_id);
  }
  if (filters.durum) {
    const d = filters.durum;
    list = list.filter((f) => (f.state ?? "").toUpperCase() === d.toUpperCase());
  }
  return list;
}

function collectPuantajFromCache(filters: ModuleFilterBase): GunlukPuantaj[] {
  const out: GunlukPuantaj[] = [];
  const cache = getAppData().cache;
  for (const key of Object.keys(cache)) {
    if (!key.startsWith("puantaj:")) {
      continue;
    }
    const env = cache[key];
    const row = env?.data as GunlukPuantaj | null | undefined;
    if (!row || typeof row !== "object") {
      continue;
    }
    if (filters.personel_id != null && row.personel_id !== filters.personel_id) {
      continue;
    }
    if (!matchesDateRange(row.tarih, filters.date_range)) {
      continue;
    }
    out.push(row);
  }
  return out;
}

/**
 * Onbellekteki ham verilerden rapor satirlari uretir (ek ag cagrisi yok).
 */
export function generateReport(type: ReportEngineType, filters: ModuleFilterBase): ReportEngineRow[] {
  const sube = subeForEngine(filters);
  let rows: ReportEngineRow[];

  switch (type) {
    case "personel-ozet": {
      rows = readCachedPersonelFirstPage(sube, filters).map((p) => ({
        personel_id: p.id,
        ad: p.ad,
        soyad: p.soyad,
        aktif_durum: p.aktif_durum,
        tc_kimlik_no: p.tc_kimlik_no
      }));
      break;
    }
    case "izin-durumu": {
      rows = readCachedSureclerFirstPage(sube, filters)
        .filter((s) => /IZIN|İZIN|RAPOR|URLA/i.test(s.surec_turu))
        .map((s) => ({
          surec_id: s.id,
          personel_id: s.personel_id,
          tur: s.surec_turu,
          baslangic: s.baslangic_tarihi ?? "",
          bitis: s.bitis_tarihi ?? "",
          state: s.state ?? ""
        }));
      break;
    }
    case "puantaj": {
      const kayitlar = collectPuantajFromCache(filters);
      const personeller = readCachedPersonelFirstPage(sube, { ...filters, personel_id: null });
      rows = hesaplaAylikKapanisListesi(personeller, kayitlar, {
        personel_id: filters.personel_id ?? undefined,
        aktiflik: filters.durum === "AKTIF" || filters.durum === "PASIF" ? filters.durum.toLowerCase() as "aktif" | "pasif" : "tum",
        baslangic_tarihi: filters.date_range?.bas,
        bitis_tarihi: filters.date_range?.bit
      }).map((satir) => ({
        personel_id: satir.personel_id,
        ad_soyad: satir.personel_adi,
        donem: satir.donem,
        eksik_gun_sayisi: satir.eksik_gun_sayisi,
        eksik_gun_nedeni_kodu: satir.eksik_gun_nedeni_kodu ?? "-",
        sgk_prim_gun: satir.sgk_prim_gun
      }));
      break;
    }
    case "finans": {
      const fin = readCachedFinansFirstPage(sube, filters);
      const toplam = fin.reduce((acc, r) => acc + (r.tutar ?? 0), 0);
      const mapped = fin.map((r) => ({
        id: r.id,
        personel_id: r.personel_id,
        donem: r.donem,
        kalem: r.kalem_turu,
        tutar: r.tutar,
        state: r.state ?? ""
      }));
      rows = [
        ...mapped,
        {
          id: -1,
          personel_id: 0,
          donem: "",
          kalem: "TOPLAM (onbellek 1. sayfa)",
          tutar: toplam,
          state: ""
        }
      ];
      break;
    }
    default:
      rows = [];
  }

  recordOfflineReportGeneration(getAppDataRevision());
  return rows;
}
