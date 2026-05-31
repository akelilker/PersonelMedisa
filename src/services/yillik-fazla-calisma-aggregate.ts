import type {
  HaftalikKapanisSnapshotSatir,
  HaftalikKapanisSonuc,
  YillikFazlaCalismaOzeti
} from "../types/haftalik-kapanis";

export const YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA = 270 * 60;
export const YILLIK_FAZLA_CALISMA_YAKLASMA_ESIK_DAKIKA = 260 * 60;

export type AggregateYillikFazlaCalismaParams = {
  kapanislar: readonly HaftalikKapanisSonuc[];
  personel_id: number;
  yil: number;
};

type HaftaKaydi = {
  satir: HaftalikKapanisSnapshotSatir;
  kapanis_id: number;
};

function guvenliFazlaCalismaDakika(value: number): number {
  if (!Number.isFinite(value) || value < 0) {
    return 0;
  }

  return value;
}

function resolveSatirYili(satir: HaftalikKapanisSnapshotSatir): number | null {
  if (satir.yil !== undefined && Number.isFinite(satir.yil)) {
    return satir.yil;
  }

  const match = /^(\d{4})-/.exec(satir.hafta_baslangic.trim());
  if (!match) {
    return null;
  }

  const parsed = Number.parseInt(match[1], 10);
  return Number.isNaN(parsed) ? null : parsed;
}

function resolveKapanisId(kapanis: HaftalikKapanisSonuc): number {
  const id = kapanis.kapanis_id ?? kapanis.id;
  if (id !== undefined && Number.isFinite(id)) {
    return id;
  }

  return 0;
}

function haftaDedupeAnahtari(
  personelId: number,
  yil: number,
  haftaBaslangic: string
): string {
  return `${personelId}|${yil}|${haftaBaslangic}`;
}

export function aggregateYillikFazlaCalisma(
  params: AggregateYillikFazlaCalismaParams
): YillikFazlaCalismaOzeti {
  const { kapanislar, personel_id, yil } = params;
  const limit = YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA;
  const yaklasmaEsik = YILLIK_FAZLA_CALISMA_YAKLASMA_ESIK_DAKIKA;

  const byHafta = new Map<string, HaftaKaydi[]>();
  let atlanan_eksik_hafta_sayisi = 0;

  for (const kapanis of kapanislar) {
    const kapanis_id = resolveKapanisId(kapanis);

    for (const satir of kapanis.snapshot_satirlari ?? []) {
      if (satir.personel_id !== personel_id) {
        continue;
      }

      if (satir.state !== "KAPANDI") {
        continue;
      }

      const satirYili = resolveSatirYili(satir);
      if (satirYili === null || satirYili !== yil) {
        continue;
      }

      if (!satir.tam_hafta_verisi) {
        atlanan_eksik_hafta_sayisi += 1;
        continue;
      }

      const anahtar = haftaDedupeAnahtari(personel_id, yil, satir.hafta_baslangic);
      const mevcut = byHafta.get(anahtar) ?? [];
      mevcut.push({ satir, kapanis_id });
      byHafta.set(anahtar, mevcut);
    }
  }

  let kullanilan_dakika = 0;
  let atlanan_duplicate_hafta_sayisi = 0;
  let kapanan_hafta_sayisi = 0;

  for (const kayitlar of byHafta.values()) {
    kayitlar.sort((a, b) => b.kapanis_id - a.kapanis_id);
    const kazanan = kayitlar[0];
    atlanan_duplicate_hafta_sayisi += Math.max(0, kayitlar.length - 1);
    kapanan_hafta_sayisi += 1;
    kullanilan_dakika += guvenliFazlaCalismaDakika(kazanan.satir.fazla_calisma_dakika);
  }

  const kalan_dakika = Math.max(0, limit - kullanilan_dakika);

  return {
    personel_id,
    yil,
    yillik_limit_dakika: limit,
    yaklasma_esik_dakika: yaklasmaEsik,
    kullanilan_dakika,
    kalan_dakika,
    limit_asildi_mi: kullanilan_dakika > limit,
    limit_yaklasiyor_mu: kullanilan_dakika >= yaklasmaEsik,
    kapanan_hafta_sayisi,
    atlanan_duplicate_hafta_sayisi,
    atlanan_eksik_hafta_sayisi
  };
}
