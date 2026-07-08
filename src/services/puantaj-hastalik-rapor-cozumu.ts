import { dataCacheKeys, getCacheEntry } from "../data/data-manager";
import type { PaginatedResult } from "../types/api";
import type { Surec } from "../types/surec";
import type { GunlukPuantaj } from "../types/puantaj";
import {
  cozumleHastalikRaporGunu,
  type HastalikRaporSureci
} from "./hastalik-rapor-politikasi";
import type { PuantajEksikGunSiniflandirmaGirdisi } from "./puantaj-hesap-motoru";

export type GunlukPuantajEksikGunSiniflandirmaKaynagi = Pick<
  GunlukPuantaj,
  "personel_id" | "tarih" | "hareket_durumu" | "dayanak" | "durumu_bildirdi_mi"
>;

export function mapSurecToHastalikRaporSureci(surec: Surec): HastalikRaporSureci | null {
  const baslangicTarihi = surec.baslangic_tarihi?.trim();
  if (!baslangicTarihi) {
    return null;
  }

  return {
    id: surec.id,
    personel_id: surec.personel_id,
    surec_turu: surec.surec_turu,
    alt_tur: surec.alt_tur ?? null,
    baslangic_tarihi: baslangicTarihi,
    bitis_tarihi: surec.bitis_tarihi ?? null,
    state: surec.state ?? null,
    ilk_iki_gun_firma_oder_mi: surec.ilk_iki_gun_firma_oder_mi ?? null
  };
}

export function buildHastalikRaporSurecList(surecler: Surec[]): HastalikRaporSureci[] {
  return surecler
    .map(mapSurecToHastalikRaporSureci)
    .filter((surec): surec is HastalikRaporSureci => surec !== null);
}

function readPersonelSurecCache(
  activeSube: number | null,
  personelId: number
): PaginatedResult<Surec> | undefined {
  const key = dataCacheKeys.sureclerList(activeSube, String(personelId), "", "", "", "", 1);
  return getCacheEntry<PaginatedResult<Surec>>(key);
}

export function readHastalikRaporSurecleriFromCache(
  activeSube: number | null,
  personelId: number
): HastalikRaporSureci[] | null {
  const cached = readPersonelSurecCache(activeSube, personelId);
  if (cached === undefined) {
    return null;
  }

  return buildHastalikRaporSurecList(cached.items ?? []);
}

export function buildGunlukPuantajEksikGunSiniflandirmaGirdisi(
  puantaj: GunlukPuantajEksikGunSiniflandirmaKaynagi,
  hastalikRaporSurecleri: HastalikRaporSureci[] | null
): PuantajEksikGunSiniflandirmaGirdisi {
  const girdi: PuantajEksikGunSiniflandirmaGirdisi = {
    hareket_durumu: puantaj.hareket_durumu,
    dayanak: puantaj.dayanak,
    durumu_bildirdi_mi: puantaj.durumu_bildirdi_mi
  };

  if (puantaj.dayanak === "Raporlu_Hastalik" && hastalikRaporSurecleri !== null) {
    girdi.hastalik_rapor_cozumu = cozumleHastalikRaporGunu(hastalikRaporSurecleri, {
      personelId: puantaj.personel_id,
      tarih: puantaj.tarih
    });
  }

  return girdi;
}
