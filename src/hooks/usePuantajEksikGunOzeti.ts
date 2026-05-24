import { useMemo } from "react";
import {
  dataCacheKeys,
  ensureAppData,
  getCacheEntry,
  useAppDataRevision
} from "../data/data-manager";
import { hesaplaAylikPuantajEksikGunOzeti } from "../services/puantaj-hesap-motoru";
import { useAuth } from "../state/auth.store";
import type { Personel } from "../types/personel";
import type { GunlukPuantaj } from "../types/puantaj";

export type PuantajEksikGunOzetiDurum =
  | "hazir"
  | "manuel_inceleme"
  | "veri_kapsami_eksik";

export type PuantajEksikGunOzetiView = {
  donem: string;
  durum: PuantajEksikGunOzetiDurum;
  durumLabel: string;
  toplamKayitSayisi: number;
  donemGunSayisi: number;
  eksikGunAdayiKayitSayisi: number;
  sgkPrimGununuDusurenEksikGunSayisi: number;
  manuelIncelemeKayitSayisi: number;
  dakikaBazliUcretEtkisiAdayiSayisi: number;
  gunlukKesintiAdayiSayisi: number;
  ucretKorunanKayitSayisi: number;
  haberliYoklukSinyaliSayisi: number;
  habersizYoklukSinyaliSayisi: number;
  kesinSgkPrimGunuHesaplanabilirMi: boolean;
  aciklama: string;
  kayitKapsamiNotu: string | null;
};

export const PUANTAJ_EKSIK_GUN_VERI_KAPSAMI_EKSIK_ACIKLAMA =
  "Bu dönem için tüm günlük puantaj kayıtları yüklenmeden eksik gün / SGK prim günü özeti kesinleştirilemez.";

function parseDonemFromPersonel(personel: Personel): { yil: number; ay: number; donem: string } | null {
  const raw = personel.sgk_donem?.trim();
  if (!raw) {
    return null;
  }

  const match = raw.match(/^(\d{4})-(\d{2})$/);
  if (!match) {
    return null;
  }

  const yil = Number.parseInt(match[1], 10);
  const ay = Number.parseInt(match[2], 10);
  if (!Number.isInteger(yil) || !Number.isInteger(ay) || ay < 1 || ay > 12) {
    return null;
  }

  return { yil, ay, donem: `${yil}-${String(ay).padStart(2, "0")}` };
}

function listDonemTarihleri(yil: number, ay: number): string[] {
  const daysInMonth = new Date(yil, ay, 0).getDate();
  const ayStr = String(ay).padStart(2, "0");
  const out: string[] = [];
  for (let day = 1; day <= daysInMonth; day++) {
    out.push(`${yil}-${ayStr}-${String(day).padStart(2, "0")}`);
  }
  return out;
}

function buildKayitKapsamiNotu(kayitSayisi: number, donemGunSayisi: number): string {
  return `Bu dönem için önbellekte ${kayitSayisi}/${donemGunSayisi} günlük kayıt bulundu.`;
}

function toplaDonemPuantajKayitlari(
  activeSube: number | null,
  personelId: number,
  yil: number,
  ay: number
): GunlukPuantaj[] {
  const tarihler = listDonemTarihleri(yil, ay);
  const byTarih = new Map<string, GunlukPuantaj>();

  for (const tarih of tarihler) {
    const key = dataCacheKeys.puantajDetail(activeSube, personelId, tarih);
    const cached = getCacheEntry<GunlukPuantaj | null>(key);
    if (cached != null) {
      byTarih.set(tarih, cached);
    }
  }

  const activeSubePersonelPrefix = dataCacheKeys.puantajDetail(activeSube, personelId, "");
  const donemPrefix = `${yil}-${String(ay).padStart(2, "0")}-`;
  for (const key of Object.keys(ensureAppData().cache)) {
    if (!key.startsWith(activeSubePersonelPrefix)) {
      continue;
    }

    const match = key.match(/^puantaj:s[^:]+:(\d+)\|(\d{4}-\d{2}-\d{2})$/);
    if (!match) {
      continue;
    }

    const cachedPersonelId = Number.parseInt(match[1], 10);
    const tarih = match[2];
    if (cachedPersonelId !== personelId || !tarih.startsWith(donemPrefix)) {
      continue;
    }

    const cached = getCacheEntry<GunlukPuantaj | null>(key);
    if (cached != null) {
      byTarih.set(tarih, cached);
    }
  }

  return [...byTarih.values()];
}

export function mapAylikPuantajEksikGunOzetiToView(
  sonuc: ReturnType<typeof hesaplaAylikPuantajEksikGunOzeti>,
  donem: string,
  kayitSayisi: number,
  donemGunSayisi: number
): PuantajEksikGunOzetiView {
  const kapsamEksik = kayitSayisi < donemGunSayisi;
  const kesinSgkPrimGunuHesaplanabilirMi =
    sonuc.kesin_sgk_prim_gunu_hesaplanabilir_mi && !kapsamEksik;

  let durum: PuantajEksikGunOzetiDurum = "hazir";
  let durumLabel = "Hesaplanabilir";
  let aciklama = sonuc.aciklama;

  if (kapsamEksik) {
    durum = "veri_kapsami_eksik";
    durumLabel = "Veri Kapsamı Eksik";
    aciklama = PUANTAJ_EKSIK_GUN_VERI_KAPSAMI_EKSIK_ACIKLAMA;
  } else if (sonuc.manuel_inceleme_kayit_sayisi > 0) {
    durum = "manuel_inceleme";
    durumLabel = "Manuel İnceleme Gerekli";
  }

  return {
    donem,
    durum,
    durumLabel,
    toplamKayitSayisi: sonuc.toplam_kayit_sayisi,
    donemGunSayisi,
    eksikGunAdayiKayitSayisi: sonuc.eksik_gun_adayi_kayit_sayisi,
    sgkPrimGununuDusurenEksikGunSayisi: sonuc.sgk_prim_gununu_dusuren_eksik_gun_sayisi,
    manuelIncelemeKayitSayisi: sonuc.manuel_inceleme_kayit_sayisi,
    dakikaBazliUcretEtkisiAdayiSayisi: sonuc.dakika_bazli_ucret_etkisi_adayi_sayisi,
    gunlukKesintiAdayiSayisi: sonuc.gunluk_kesinti_adayi_sayisi,
    ucretKorunanKayitSayisi: sonuc.ucret_korunan_kayit_sayisi,
    haberliYoklukSinyaliSayisi: sonuc.haberli_yokluk_sinyali_sayisi,
    habersizYoklukSinyaliSayisi: sonuc.habersiz_yokluk_sinyali_sayisi,
    kesinSgkPrimGunuHesaplanabilirMi,
    aciklama,
    kayitKapsamiNotu: kapsamEksik ? buildKayitKapsamiNotu(kayitSayisi, donemGunSayisi) : null
  };
}

export function usePuantajEksikGunOzeti(personel: Personel): PuantajEksikGunOzetiView | null {
  const { session } = useAuth();
  const activeSube = session?.active_sube_id ?? null;
  const appDataRevision = useAppDataRevision();

  return useMemo(() => {
    void appDataRevision;

    const parsedDonem = parseDonemFromPersonel(personel);
    if (!parsedDonem) {
      return null;
    }

    const gunlukKayitlar = toplaDonemPuantajKayitlari(
      activeSube,
      personel.id,
      parsedDonem.yil,
      parsedDonem.ay
    );
    const donemGunSayisi = listDonemTarihleri(parsedDonem.yil, parsedDonem.ay).length;

    const sonuc = hesaplaAylikPuantajEksikGunOzeti({ kayitlar: gunlukKayitlar });

    return mapAylikPuantajEksikGunOzetiToView(
      sonuc,
      parsedDonem.donem,
      gunlukKayitlar.length,
      donemGunSayisi
    );
  }, [activeSube, appDataRevision, personel.id, personel.sgk_donem]);
}
