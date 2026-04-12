import type { GunlukPuantaj } from "../types/puantaj";
import type { Personel } from "../types/personel";
import type { Surec } from "../types/surec";
import { hesaplaIzinBakiye, type IzinBakiye } from "./izin-hesap-motoru";

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
