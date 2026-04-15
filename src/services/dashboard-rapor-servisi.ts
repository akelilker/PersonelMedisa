import type { GunlukPuantaj } from "../types/puantaj";
import type { Personel } from "../types/personel";
import type { Surec } from "../types/surec";
import { hesaplaIzinBakiye } from "./izin-hesap-motoru";
import {
  hesaplaSgkPrimGunu,
  type SgkPrimGunuHesaplamaModu,
  type SgkUcretTipi
} from "./sgk-prim-gunu-hesap";

// ---------------------------------------------------------------------------
// Dashboard KPI çıktı tipleri
// ---------------------------------------------------------------------------

export type DashboardKpi = {
  toplam_personel: number;
  aktif_personel: number;
  pasif_personel: number;
  toplam_muhurlenen_puantaj: number;
  toplam_acik_puantaj: number;
  toplam_izinsiz_devamsizlik: number;
  toplam_net_calisma_dakika: number;
  ortalama_gunluk_net_calisma_dakika: number;
  ortalama_kalan_izin: number;
  hafta_tatili_hak_kaybi_sayisi: number;
};

export type AylikSgkPuantajOzeti = {
  yil: number;
  ay: number;
  donem: string;
  kayit_gun_sayisi: number;
  eksik_gun_sayisi: number;
  eksik_gun_nedeni_kodu: string | null;
  sgk_prim_gun: number;
  ayin_takvim_gun_sayisi: number;
  hesaplama_modu: SgkPrimGunuHesaplamaModu;
  ucret_tipi: SgkUcretTipi;
};

function parsePuantajYearMonth(tarih: string | undefined) {
  if (typeof tarih !== "string") {
    return null;
  }

  const match = tarih.trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) {
    return null;
  }

  const yil = Number.parseInt(match[1], 10);
  const ay = Number.parseInt(match[2], 10);
  if (!Number.isInteger(yil) || !Number.isInteger(ay) || ay < 1 || ay > 12) {
    return null;
  }

  return {
    yil,
    ay,
    donem: `${match[1]}-${match[2]}`,
    gunAnahtari: `${match[1]}-${match[2]}-${match[3]}`
  };
}

function shouldCountAsEksikGun(kayit: GunlukPuantaj) {
  if (kayit.hareket_durumu !== "Gelmedi") {
    return false;
  }

  return (
    kayit.dayanak === undefined ||
    kayit.dayanak === "Yok_Izinsiz" ||
    kayit.dayanak === "Raporlu_Hastalik" ||
    kayit.dayanak === "Raporlu_Is_Kazasi"
  );
}

function hesaplaEksikGunNedeniKodu(kayitlar: GunlukPuantaj[], yil: number, ay: number) {
  let raporVar = false;
  let devamsizlikVar = false;

  for (const kayit of kayitlar) {
    const parsed = parsePuantajYearMonth(kayit.tarih);
    if (!parsed || parsed.yil !== yil || parsed.ay !== ay || !shouldCountAsEksikGun(kayit)) {
      continue;
    }

    if (kayit.dayanak === "Raporlu_Hastalik" || kayit.dayanak === "Raporlu_Is_Kazasi") {
      raporVar = true;
      continue;
    }

    if (kayit.dayanak === "Yok_Izinsiz" || kayit.dayanak === undefined) {
      devamsizlikVar = true;
    }
  }

  if (raporVar && devamsizlikVar) {
    return "12 - Birden Fazla";
  }

  if (raporVar) {
    return "01 - İstirahat";
  }

  if (devamsizlikVar) {
    return "15 - Devamsızlık";
  }

  return null;
}

export function hesaplaAylikSgkPuantajOzeti(
  kayitlar: GunlukPuantaj[],
  yil: number,
  ay: number,
  ucretTipi: SgkUcretTipi = "MAKTU_AYLIK"
): AylikSgkPuantajOzeti {
  const donem = `${yil}-${String(ay).padStart(2, "0")}`;
  const gunAnahtarlari = new Set<string>();
  const eksikGunAnahtarlari = new Set<string>();

  for (const kayit of kayitlar) {
    const parsed = parsePuantajYearMonth(kayit.tarih);
    if (!parsed || parsed.yil !== yil || parsed.ay !== ay) {
      continue;
    }

    gunAnahtarlari.add(parsed.gunAnahtari);
    if (shouldCountAsEksikGun(kayit)) {
      eksikGunAnahtarlari.add(parsed.gunAnahtari);
    }
  }

  const sgkSonucu = hesaplaSgkPrimGunu({
    yil,
    ay,
    eksik_gun_sayisi: eksikGunAnahtarlari.size,
    ucret_tipi: ucretTipi
  });
  const eksikGunNedeniKodu =
    sgkSonucu.eksik_gun_sayisi > 0 ? hesaplaEksikGunNedeniKodu(kayitlar, yil, ay) : null;

  return {
    yil,
    ay,
    donem,
    kayit_gun_sayisi: gunAnahtarlari.size,
    eksik_gun_sayisi: sgkSonucu.eksik_gun_sayisi,
    eksik_gun_nedeni_kodu: eksikGunNedeniKodu,
    sgk_prim_gun: sgkSonucu.sgk_prim_gun,
    ayin_takvim_gun_sayisi: sgkSonucu.ayin_takvim_gun_sayisi,
    hesaplama_modu: sgkSonucu.hesaplama_modu,
    ucret_tipi: sgkSonucu.ucret_tipi
  };
}

export function hesaplaAylikSgkPuantajOzetleri(
  kayitlar: GunlukPuantaj[],
  ucretTipi: SgkUcretTipi = "MAKTU_AYLIK"
): AylikSgkPuantajOzeti[] {
  const donemler = new Map<string, { yil: number; ay: number }>();

  for (const kayit of kayitlar) {
    const parsed = parsePuantajYearMonth(kayit.tarih);
    if (!parsed) {
      continue;
    }

    donemler.set(parsed.donem, { yil: parsed.yil, ay: parsed.ay });
  }

  return Array.from(donemler.values())
    .map((donem) => hesaplaAylikSgkPuantajOzeti(kayitlar, donem.yil, donem.ay, ucretTipi))
    .sort((left, right) => right.donem.localeCompare(left.donem, "tr"));
}

// ---------------------------------------------------------------------------
// Personel istatistikleri
// ---------------------------------------------------------------------------

export function hesaplaPersonelIstatistikleri(personeller: Personel[]): {
  toplam: number;
  aktif: number;
  pasif: number;
} {
  let aktif = 0;
  let pasif = 0;

  for (const p of personeller) {
    if (p.aktif_durum === "PASIF") {
      pasif++;
    } else {
      aktif++;
    }
  }

  return { toplam: personeller.length, aktif, pasif };
}

// ---------------------------------------------------------------------------
// Puantaj istatistikleri
// ---------------------------------------------------------------------------

export function hesaplaPuantajIstatistikleri(kayitlar: GunlukPuantaj[]): {
  muhurlenen: number;
  acik: number;
  izinsizDevamsizlik: number;
  toplamNetDakika: number;
  ortalamaNetDakika: number;
  haftaTatiliHakKaybı: number;
} {
  let muhurlenen = 0;
  let acik = 0;
  let izinsizDevamsizlik = 0;
  let toplamNetDakika = 0;
  let hesaplananKayitSayisi = 0;
  let hakKaybi = 0;

  for (const k of kayitlar) {
    if (k.state === "MUHURLENDI") {
      muhurlenen++;
    } else {
      acik++;
    }

    if (k.hareket_durumu === "Gelmedi" && (k.dayanak === "Yok_Izinsiz" || k.dayanak === undefined)) {
      izinsizDevamsizlik++;
    }

    if (k.net_calisma_suresi_dakika !== undefined && k.net_calisma_suresi_dakika > 0) {
      toplamNetDakika += k.net_calisma_suresi_dakika;
      hesaplananKayitSayisi++;
    }

    if (k.hafta_tatili_hak_kazandi_mi === false) {
      hakKaybi++;
    }
  }

  const ortalamaNetDakika = hesaplananKayitSayisi > 0 ? Math.round(toplamNetDakika / hesaplananKayitSayisi) : 0;

  return {
    muhurlenen,
    acik,
    izinsizDevamsizlik,
    toplamNetDakika,
    ortalamaNetDakika,
    haftaTatiliHakKaybı: hakKaybi
  };
}

// ---------------------------------------------------------------------------
// İzin bakiye ortalaması
// ---------------------------------------------------------------------------

export function hesaplaOrtalamaKalanIzin(
  personeller: Personel[],
  surecler: Surec[]
): number {
  const aktifPersoneller = personeller.filter((p) => p.aktif_durum === "AKTIF" && p.ise_giris_tarihi);

  if (aktifPersoneller.length === 0) return 0;

  let toplamKalan = 0;

  for (const p of aktifPersoneller) {
    const personelSurecleri = surecler.filter((s) => s.personel_id === p.id);
    const bakiye = hesaplaIzinBakiye(
      { ise_giris_tarihi: p.ise_giris_tarihi!, dogum_tarihi: p.dogum_tarihi },
      personelSurecleri
    );
    toplamKalan += bakiye.kalan_gun;
  }

  return Math.round(toplamKalan / aktifPersoneller.length);
}

// ---------------------------------------------------------------------------
// Ana dashboard KPI hesaplama (saf fonksiyon)
// ---------------------------------------------------------------------------

export function hesaplaDashboardKpi(
  personeller: Personel[],
  puantajKayitlari: GunlukPuantaj[],
  surecler: Surec[]
): DashboardKpi {
  const personelStats = hesaplaPersonelIstatistikleri(personeller);
  const puantajStats = hesaplaPuantajIstatistikleri(puantajKayitlari);
  const ortalamaKalanIzin = hesaplaOrtalamaKalanIzin(personeller, surecler);

  return {
    toplam_personel: personelStats.toplam,
    aktif_personel: personelStats.aktif,
    pasif_personel: personelStats.pasif,
    toplam_muhurlenen_puantaj: puantajStats.muhurlenen,
    toplam_acik_puantaj: puantajStats.acik,
    toplam_izinsiz_devamsizlik: puantajStats.izinsizDevamsizlik,
    toplam_net_calisma_dakika: puantajStats.toplamNetDakika,
    ortalama_gunluk_net_calisma_dakika: puantajStats.ortalamaNetDakika,
    ortalama_kalan_izin: ortalamaKalanIzin,
    hafta_tatili_hak_kaybi_sayisi: puantajStats.haftaTatiliHakKaybı
  };
}
