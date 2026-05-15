import type {
  ComplianceUyari,
  GunlukPuantaj,
  PuantajAmirKontrolDurumu,
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajHesapEtkisi
} from "../types/puantaj";
import { hesaplaYas } from "./izin-hesap-motoru";

// ---------------------------------------------------------------------------
// Giriş tipi: hesapla() fonksiyonuna verilen ham günlük kayıt
// ---------------------------------------------------------------------------

export type HesapGirdisi = {
  personel_id: number;
  tarih: string;
  gun_tipi?: PuantajGunTipi;
  hareket_durumu?: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  hesap_etkisi?: PuantajHesapEtkisi;
  giris_saati?: string;
  cikis_saati?: string;
  gercek_mola_dakika?: number;
};

// ---------------------------------------------------------------------------
// Çıkış tipi: hesapla() fonksiyonunun ürettiği zenginleştirilmiş kayıt
// ---------------------------------------------------------------------------

export type HesapSonucu = {
  personel_id: number;
  tarih: string;
  gun_tipi: PuantajGunTipi;
  hareket_durumu: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  hesap_etkisi?: PuantajHesapEtkisi;
  giris_saati?: string;
  cikis_saati?: string;
  gercek_mola_dakika?: number;
  hesaplanan_mola_dakika: number;
  gunluk_brut_sure_dakika: number;
  net_calisma_suresi_dakika: number;
  hafta_tatili_hak_kazandi_mi: boolean;
  compliance_uyarilari: ComplianceUyari[];
};

// ---------------------------------------------------------------------------
// Haftalık net çalışma özeti (45 saat / 2700 dk eşiği)
// ---------------------------------------------------------------------------

/** Aylık tam zamanlı model: haftalık normal çalışma üst sınırı (45 saat). */
export const HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA = 45 * 60; // 2700

/** Haftalık toplamda kullanılacak günlük net süre girdisi (diğer alanlar yok sayılır). */
export type HaftalikGunNetCalisma = {
  net_calisma_suresi_dakika?: number;
};

export type HaftalikCalismaOzeti = {
  toplam_net_dakika: number;
  normal_calisma_dakika: number;
  fazla_calisma_dakika: number;
  haftalik_esik_dakika: number;
};

function haftalikNetDakikaSatir(value: number | undefined): number {
  if (value === undefined || value === null || Number.isNaN(value)) {
    return 0;
  }
  return value < 0 ? 0 : value;
}

/**
 * Bir haftaya ait günlük net çalışma dakikalarından haftalık özeti üretir.
 * - toplam: satırların net dakikalarının toplamı (güvenli satır birleştirme)
 * - normal: min(toplam, haftalık eşik)
 * - fazla: max(toplam − eşik, 0)
 */
export function hesaplaHaftalikCalismaOzeti(
  gunler: readonly HaftalikGunNetCalisma[]
): HaftalikCalismaOzeti {
  const haftalik_esik_dakika = HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA;
  const toplam_net_dakika = gunler.reduce(
    (acc, g) => acc + haftalikNetDakikaSatir(g.net_calisma_suresi_dakika),
    0
  );
  const normal_calisma_dakika = Math.min(toplam_net_dakika, haftalik_esik_dakika);
  const fazla_calisma_dakika = Math.max(toplam_net_dakika - haftalik_esik_dakika, 0);

  return {
    toplam_net_dakika,
    normal_calisma_dakika,
    fazla_calisma_dakika,
    haftalik_esik_dakika
  };
}

// ---------------------------------------------------------------------------
// Hafta aralığı (Pazartesi – Pazar) ve tarihten haftalık özet
// ---------------------------------------------------------------------------

/** Haftalık filtre / özet için günlük satırda gerekli alanlar. */
export type HaftalikPuantajSatirGirdi = {
  tarih: string;
  net_calisma_suresi_dakika?: number;
};

export type HaftaAraligi = {
  hafta_baslangic: string;
  hafta_bitis: string;
};

/** YYYY-MM-DD; takvim taşması / geçersiz gün → null (throw yok). */
function parseGGAATarihStrict(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) return null;
  const y = Number.parseInt(match[1], 10);
  const m = Number.parseInt(match[2], 10);
  const day = Number.parseInt(match[3], 10);
  if (m < 1 || m > 12 || day < 1 || day > 31) return null;
  const d = new Date(y, m - 1, day);
  if (Number.isNaN(d.getTime())) return null;
  if (d.getFullYear() !== y || d.getMonth() !== m - 1 || d.getDate() !== day) {
    return null;
  }
  return d;
}

function formatGGAATarih(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

/** Yerel takvimde Pazartesi 00:00 günü (aynı haftanın ilk iş günü başlangıcı). */
function haftaninPazartesiBaslangici(d: Date): Date {
  const lokal = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const gun = lokal.getDay();
  const pazartesidenItibaren = (gun + 6) % 7;
  lokal.setDate(lokal.getDate() - pazartesidenItibaren);
  return lokal;
}

function haftaninPazarSonu(pazartesi: Date): Date {
  const son = new Date(pazartesi.getFullYear(), pazartesi.getMonth(), pazartesi.getDate());
  son.setDate(son.getDate() + 6);
  return son;
}

/**
 * Verilen tarihin düştüğü haftanın Pazartesi–Pazar aralığı (yerel tarih).
 * Geçersiz `tarih` → `null`.
 */
export function hesaplaHaftaAraligi(tarih: string): HaftaAraligi | null {
  const d = parseGGAATarihStrict(tarih);
  if (!d) return null;
  const bas = haftaninPazartesiBaslangici(d);
  const bit = haftaninPazarSonu(bas);
  return {
    hafta_baslangic: formatGGAATarih(bas),
    hafta_bitis: formatGGAATarih(bit)
  };
}

/**
 * `referansTarih` ile aynı Pazartesi–Pazar haftasındaki satırlar.
 * Geçersiz referans veya satır `tarih` → o satır seçilmez; referans geçersizse `[]`.
 */
export function filtreleHaftalikPuantajSatirlari<T extends { tarih: string }>(
  gunler: readonly T[],
  referansTarih: string
): T[] {
  const aralik = hesaplaHaftaAraligi(referansTarih);
  if (!aralik) return [];
  const { hafta_baslangic, hafta_bitis } = aralik;
  return gunler.filter((g) => {
    const parsed = parseGGAATarihStrict(g.tarih);
    if (!parsed) return false;
    const ymd = formatGGAATarih(parsed);
    return ymd >= hafta_baslangic && ymd <= hafta_bitis;
  });
}

/** Aynı haftayı süzer ve `hesaplaHaftalikCalismaOzeti` sonucunu döner. */
export function hesaplaTarihtenHaftalikCalismaOzeti(
  gunler: readonly HaftalikPuantajSatirGirdi[],
  referansTarih: string
): HaftalikCalismaOzeti {
  const hafta = filtreleHaftalikPuantajSatirlari(gunler, referansTarih);
  return hesaplaHaftalikCalismaOzeti(hafta);
}

// ---------------------------------------------------------------------------
// Haftalık fazla çalışma ücreti (aylık: saatlik = maaş / 225, FM × 1.5)
// ---------------------------------------------------------------------------

/** Aylık modele göre brüt aylık maaşın bölündüğü ay içi “mesai saati” paydası. */
export const AYLIK_MAAS_SAAT_PAYDASI = 225;

export const FAZLA_CALISMA_UCRET_CARPANI = 1.5;

function ucretIcinGuvenliPozitifMaas(n: number): number {
  if (typeof n !== "number" || Number.isNaN(n) || !Number.isFinite(n) || n <= 0) {
    return 0;
  }
  return n;
}

function ucretIcinGuvenliNegatifOlmayanSayi(n: number): number {
  if (typeof n !== "number" || Number.isNaN(n) || !Number.isFinite(n) || n < 0) {
    return 0;
  }
  return n;
}

/** Para alanları için 2 ondalık (yarım yukarı yuvarlama). */
function yuvarlaParaIkiliOndalik(value: number): number {
  if (!Number.isFinite(value)) return 0;
  return Math.round(value * 100) / 100;
}

/**
 * Aylık brüt maaştan saatlik ücret: maaş / 225.
 * Maaş 0, negatif, NaN, ±Infinity → 0 (throw yok).
 */
export function hesaplaSaatlikUcret(maasTutari: number): number {
  const maas = ucretIcinGuvenliPozitifMaas(maasTutari);
  if (maas === 0) return 0;
  return maas / AYLIK_MAAS_SAAT_PAYDASI;
}

/**
 * Fazla mesai tutarı: saatlik × 1.5 × (fazla dakika / 60).
 * Negatif / NaN dakika veya saatlik → 0 kabul; tutar 2 ondalığa yuvarlanır.
 */
export function hesaplaFazlaCalismaTutari(fazlaCalismaDakika: number, saatlikUcret: number): number {
  const dk = ucretIcinGuvenliNegatifOlmayanSayi(fazlaCalismaDakika);
  const su = ucretIcinGuvenliNegatifOlmayanSayi(saatlikUcret);
  const fazlaSaat = dk / 60;
  const ham = su * FAZLA_CALISMA_UCRET_CARPANI * fazlaSaat;
  return yuvarlaParaIkiliOndalik(ham);
}

/** Haftalık süre özeti + saatlik ücret ve FM tutarı. */
export type HaftalikCalismaVeFazlaUcretOzeti = HaftalikCalismaOzeti & {
  saatlik_ucret: number;
  fazla_calisma_saat: number;
  fazla_calisma_tutari: number;
};

/**
 * `HaftalikCalismaOzeti` üzerinden saatlik ücret ve haftalık fazla çalışma parasını ekler.
 */
export function hesaplaHaftalikFazlaCalismaUcreti(
  ozet: HaftalikCalismaOzeti,
  maasTutari: number
): HaftalikCalismaVeFazlaUcretOzeti {
  const hamSaatlik = hesaplaSaatlikUcret(maasTutari);
  const fazla_dk = ucretIcinGuvenliNegatifOlmayanSayi(ozet.fazla_calisma_dakika);
  const fazla_calisma_saat = fazla_dk / 60;
  const fazla_calisma_tutari = hesaplaFazlaCalismaTutari(ozet.fazla_calisma_dakika, hamSaatlik);

  return {
    ...ozet,
    saatlik_ucret: yuvarlaParaIkiliOndalik(hamSaatlik),
    fazla_calisma_saat,
    fazla_calisma_tutari
  };
}

// ---------------------------------------------------------------------------
// Günlük puantaj → haftalık fazla mesai ücret özeti (servis adapter)
// ---------------------------------------------------------------------------

/** Haftalık süre + ücret + hafta aralığı (geçersiz referansta aralık `null`). */
export type HaftalikPuantajUcretOzeti = HaftalikCalismaVeFazlaUcretOzeti & {
  hafta_baslangic: string | null;
  hafta_bitis: string | null;
};

/**
 * `GunlukPuantaj` satırları (çağıranın tek personel listesi vermesi beklenir),
 * referans tarih ve maaş ile haftalık fazla mesai ücret özetini üretir.
 * Motorları birleştirir; veri çekmez, throw etmez.
 */
export function hesaplaHaftalikPuantajUcretOzeti(
  gunler: readonly GunlukPuantaj[],
  referansTarih: string,
  maasTutari: number
): HaftalikPuantajUcretOzeti {
  const aralik = hesaplaHaftaAraligi(referansTarih);
  const ozet = hesaplaTarihtenHaftalikCalismaOzeti(gunler, referansTarih);
  const ucret = hesaplaHaftalikFazlaCalismaUcreti(ozet, maasTutari);

  return {
    ...ucret,
    hafta_baslangic: aralik?.hafta_baslangic ?? null,
    hafta_bitis: aralik?.hafta_bitis ?? null
  };
}

// ---------------------------------------------------------------------------
// Günlük kesinti (geç kalma / erken çıkma / devamsızlık — aylık model)
// ---------------------------------------------------------------------------

/** Aylık brüt maaşın bölündüğü takvim ayı gün paydası (günlük ücret). */
export const AYLIK_MAAS_GUN_PAYDASI = 30;

/**
 * Günlük ücret: maaş / 30.
 * Geçersiz maaş → 0; tutar `yuvarlaParaIkiliOndalik` ile 2 ondalık.
 */
export function hesaplaGunlukUcret(maasTutari: number): number {
  const maas = ucretIcinGuvenliPozitifMaas(maasTutari);
  if (maas === 0) return 0;
  return yuvarlaParaIkiliOndalik(maas / AYLIK_MAAS_GUN_PAYDASI);
}

/**
 * Eksik çalışılan süre için saatlik ücret kadar kesinti (FM çarpanı yok).
 * Negatif / NaN dakika → 0; kesinti 2 ondalık.
 */
export function hesaplaSaatlikKesintiTutari(eksikDakika: number, maasTutari: number): number {
  const dk = ucretIcinGuvenliNegatifOlmayanSayi(eksikDakika);
  const su = hesaplaSaatlikUcret(maasTutari);
  const eksikSaat = dk / 60;
  return yuvarlaParaIkiliOndalik(su * eksikSaat);
}

export type GecKalmaErkenCikmaKesintiOzeti = {
  gercek_eksik_dakika: number;
  kesintiye_esas_dakika: number;
  kesintiye_esas_saat: number;
  saatlik_ucret: number;
  kesinti_tutari: number;
};

export const GEC_ERKEN_TOLERANS_DAKIKA = 0;

export type GecErkenEksikSureGirdisi = {
  hareket_durumu?: PuantajHareketDurumu;
  giris_saati?: string;
  cikis_saati?: string;
  beklenen_giris_saati?: string;
  beklenen_cikis_saati?: string;
};

export type GecErkenEksikSureSonucu = {
  hesaplanabilir_mi: boolean;
  eksik_dakika: number;
  neden?:
    | "BEKLENEN_SAAT_YOK"
    | "GERCEK_SAAT_YOK"
    | "GECERSIZ_SAAT"
    | "HAREKET_DURUMU_UYGUN_DEGIL";
  tip?: "GEC_KALMA" | "ERKEN_CIKMA";
};

function hesaplaKesintiyeEsasDakika(gercekEksikDakika: number): number {
  const dk = ucretIcinGuvenliNegatifOlmayanSayi(gercekEksikDakika);
  if (dk === 0) {
    return 0;
  }

  return Math.ceil(dk / 30) * 30;
}

/**
 * Geç kalma / erken çıkma için beklenen ve gerçek saatlerden güvenli eksik süre üretir.
 * Hesap yapılamayan durumlarda throw etmez; neden kodu ile 0 dakika döner.
 */
export function hesaplaGecErkenEksikSure(
  girdi: GecErkenEksikSureGirdisi
): GecErkenEksikSureSonucu {
  if (girdi.hareket_durumu !== "Gec_Geldi" && girdi.hareket_durumu !== "Erken_Cikti") {
    return {
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "HAREKET_DURUMU_UYGUN_DEGIL"
    };
  }

  if (girdi.hareket_durumu === "Gec_Geldi") {
    if (!girdi.beklenen_giris_saati?.trim()) {
      return {
        hesaplanabilir_mi: false,
        eksik_dakika: 0,
        neden: "BEKLENEN_SAAT_YOK"
      };
    }
    if (!girdi.giris_saati?.trim()) {
      return {
        hesaplanabilir_mi: false,
        eksik_dakika: 0,
        neden: "GERCEK_SAAT_YOK"
      };
    }

    const beklenen = parseTimeToMinutes(girdi.beklenen_giris_saati);
    const gercek = parseTimeToMinutes(girdi.giris_saati);
    if (beklenen === null || gercek === null) {
      return {
        hesaplanabilir_mi: false,
        eksik_dakika: 0,
        neden: "GECERSIZ_SAAT"
      };
    }

    return {
      hesaplanabilir_mi: true,
      eksik_dakika: Math.max(gercek - beklenen - GEC_ERKEN_TOLERANS_DAKIKA, 0),
      tip: "GEC_KALMA"
    };
  }

  if (!girdi.beklenen_cikis_saati?.trim()) {
    return {
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "BEKLENEN_SAAT_YOK"
    };
  }
  if (!girdi.cikis_saati?.trim()) {
    return {
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "GERCEK_SAAT_YOK"
    };
  }

  const beklenen = parseTimeToMinutes(girdi.beklenen_cikis_saati);
  const gercek = parseTimeToMinutes(girdi.cikis_saati);
  if (beklenen === null || gercek === null) {
    return {
      hesaplanabilir_mi: false,
      eksik_dakika: 0,
      neden: "GECERSIZ_SAAT"
    };
  }

  return {
    hesaplanabilir_mi: true,
    eksik_dakika: Math.max(beklenen - gercek - GEC_ERKEN_TOLERANS_DAKIKA, 0),
    tip: "ERKEN_CIKMA"
  };
}

/**
 * Geç kalma veya erken çıkma için eksik süre × saatlik ücret özeti.
 */
export function hesaplaGecKalmaErkenCikmaKesintiOzeti(
  eksikDakika: number,
  maasTutari: number
): GecKalmaErkenCikmaKesintiOzeti {
  const gercek_eksik_dakika = ucretIcinGuvenliNegatifOlmayanSayi(eksikDakika);
  const kesintiye_esas_dakika = hesaplaKesintiyeEsasDakika(gercek_eksik_dakika);
  const kesintiye_esas_saat = kesintiye_esas_dakika / 60;
  const hamSaatlik = hesaplaSaatlikUcret(maasTutari);
  const kesinti_tutari = hesaplaSaatlikKesintiTutari(kesintiye_esas_dakika, maasTutari);

  return {
    gercek_eksik_dakika,
    kesintiye_esas_dakika,
    kesintiye_esas_saat,
    saatlik_ucret: yuvarlaParaIkiliOndalik(hamSaatlik),
    kesinti_tutari
  };
}

export type DevamsizlikKesintiGirdi = {
  devamsizlik_gun_sayisi: number;
  /** Hafta tatili hakkı kaybı vb. için ek kesinti gün eşdeğeri (varsayılan 0). */
  hafta_tatili_kaybi_gun_sayisi?: number;
};

export type DevamsizlikKesintiOzeti = {
  gunluk_ucret: number;
  devamsizlik_gun_sayisi: number;
  hafta_tatili_kaybi_gun_sayisi: number;
  toplam_kesinti_gun_esdegeri: number;
  toplam_kesinti_tutari: number;
};

/**
 * Devamsızlık: günlük ücret × (devamsızlık günü + hafta tatili kaybı gün eşdeğeri).
 * Gün sayıları negatif/NaN ise 0; toplam kesinti 2 ondalık. Maaş geçersizse tutar 0, gün eşdeğeri yine hesaplanır.
 */
export function hesaplaDevamsizlikKesintiOzeti(
  maasTutari: number,
  girdi: DevamsizlikKesintiGirdi
): DevamsizlikKesintiOzeti {
  const devamsizlik_gun_sayisi = ucretIcinGuvenliNegatifOlmayanSayi(girdi.devamsizlik_gun_sayisi);
  const hafta_tatili_kaybi_gun_sayisi = ucretIcinGuvenliNegatifOlmayanSayi(
    girdi.hafta_tatili_kaybi_gun_sayisi ?? 0
  );
  const toplam_kesinti_gun_esdegeri = devamsizlik_gun_sayisi + hafta_tatili_kaybi_gun_sayisi;
  const gunluk_ucret = hesaplaGunlukUcret(maasTutari);
  const toplam_kesinti_tutari = yuvarlaParaIkiliOndalik(gunluk_ucret * toplam_kesinti_gun_esdegeri);

  return {
    gunluk_ucret,
    devamsizlik_gun_sayisi,
    hafta_tatili_kaybi_gun_sayisi,
    toplam_kesinti_gun_esdegeri,
    toplam_kesinti_tutari
  };
}

// ---------------------------------------------------------------------------
// Hafta tatili (Pazar) — hak → etki karar motoru (salt karar, UI bağımsız)
// ---------------------------------------------------------------------------

/** `hesaplaHaftaTatiliPazarEtkisi` çıktısı: Pazar / hafta tatili hak durumuna göre özet. */
export type HaftaTatiliPazarEtkisiSonucu = {
  hafta_tatili_hak_kazandi_mi: boolean;
  pazar_calisildi_mi: boolean;
  hafta_tatili_kaybi_var_mi: boolean;
  ek_odeme_gun_carpani: number;
  ek_odeme_tutari: number;
  manuel_inceleme_gerekli_mi: boolean;
  aciklama: string;
};

/**
 * Hafta tatili hakkı ve Pazar çalışması için saf karar ağacı (devamsızlık / tatil ek ödeme motorlarından ayrı).
 * `gunlukUcret` yok veya geçerli pozitif sayı değilse `ek_odeme_tutari` 0; hak + Pazar çalıştı ise çarpan yine 1.5 kalabilir.
 */
export function hesaplaHaftaTatiliPazarEtkisi(
  haftaTatiliHakKazandiMi: boolean,
  pazarCalisildiMi: boolean,
  gunlukUcret?: number
): HaftaTatiliPazarEtkisiSonucu {
  const hafta_tatili_hak_kazandi_mi = haftaTatiliHakKazandiMi;
  const pazar_calisildi_mi = pazarCalisildiMi;

  const guvenliGunluk = ucretIcinGuvenliPozitifMaas(gunlukUcret ?? Number.NaN);

  if (haftaTatiliHakKazandiMi && !pazarCalisildiMi) {
    return {
      hafta_tatili_hak_kazandi_mi,
      pazar_calisildi_mi,
      hafta_tatili_kaybi_var_mi: false,
      ek_odeme_gun_carpani: 0,
      ek_odeme_tutari: 0,
      manuel_inceleme_gerekli_mi: false,
      aciklama: "normal ücretli hafta tatili, ek ödeme yok"
    };
  }

  if (haftaTatiliHakKazandiMi && pazarCalisildiMi) {
    const ek_odeme_gun_carpani = 1.5;
    const ek_odeme_tutari =
      guvenliGunluk > 0 ? yuvarlaParaIkiliOndalik(guvenliGunluk * ek_odeme_gun_carpani) : 0;
    return {
      hafta_tatili_hak_kazandi_mi,
      pazar_calisildi_mi,
      hafta_tatili_kaybi_var_mi: false,
      ek_odeme_gun_carpani,
      ek_odeme_tutari,
      manuel_inceleme_gerekli_mi: false,
      aciklama:
        "hafta tatiline hak kazanmış personelin Pazar çalışması için +1.5 günlük ek ödeme"
    };
  }

  if (!haftaTatiliHakKazandiMi && !pazarCalisildiMi) {
    return {
      hafta_tatili_hak_kazandi_mi,
      pazar_calisildi_mi,
      hafta_tatili_kaybi_var_mi: true,
      ek_odeme_gun_carpani: 0,
      ek_odeme_tutari: 0,
      manuel_inceleme_gerekli_mi: false,
      aciklama: "hafta tatili hakkı kaybedilmiş, ek ödeme yok"
    };
  }

  return {
    hafta_tatili_hak_kazandi_mi,
    pazar_calisildi_mi,
    hafta_tatili_kaybi_var_mi: true,
    ek_odeme_gun_carpani: 0,
    ek_odeme_tutari: 0,
    manuel_inceleme_gerekli_mi: true,
    aciklama:
      "hafta tatili hakkı kaybedilmişken Pazar çalışması var; otomatik ödeme üretilmez, manuel inceleme gerekir"
  };
}

export type TatilEkOdemeTuru = "UBGT" | "HAFTA_TATILI";

/** Günlük kayıt + maaştan türetilen tatil mesai ek ödeme ön izlemesi (tek gün, salt okunur). */
export type TatilEkOdemeOzeti = {
  tur: TatilEkOdemeTuru;
  gunluk_ucret: number;
  carpani: number;
  ek_odeme_tutari: number;
  /** Pazar günü: `hesaplaHaftaTatiliPazarEtkisi` tam çıktısı (hak bilgisi güvenli olduğunda). */
  hafta_tatili_pazar_karar?: HaftaTatiliPazarEtkisiSonucu;
};

/**
 * UBGT / hafta tatili mesaisi için ek ödeme ön izlemesi.
 * Koşullar: `hesap_etkisi === "Mesai_Yaz"`, gün tipi resmi tatil veya pazar.
 * UBGT için en az bir saat alanı dolu olmalı. Pazar için saat yoksa yalnızca `hafta_tatili_hak_kazandi_mi` boolean ise özet üretilir (hak → etki).
 * Hak bilgisi güvenli değilken Pazar + mesai saati varken otomatik +1,5 ücret üretilmez.
 * Diğer durumlarda `null` (kart gösterilmez).
 */
export function hesaplaTatilEkOdemeOzeti(
  maasTutari: number,
  kayit: Pick<GunlukPuantaj, "gun_tipi" | "hesap_etkisi" | "giris_saati" | "cikis_saati"> & {
    hafta_tatili_hak_kazandi_mi?: boolean;
  }
): TatilEkOdemeOzeti | null {
  if (kayit.hesap_etkisi !== "Mesai_Yaz") {
    return null;
  }

  const hasSaat =
    Boolean(kayit.giris_saati?.trim()) || Boolean(kayit.cikis_saati?.trim());
  const isPazar = kayit.gun_tipi === "Hafta_Tatili_Pazar";

  if (!hasSaat && !isPazar) {
    return null;
  }
  if (kayit.gun_tipi === "UBGT_Resmi_Tatil" && !hasSaat) {
    return null;
  }

  const gunluk_ucret = hesaplaGunlukUcret(maasTutari);

  if (kayit.gun_tipi === "UBGT_Resmi_Tatil") {
    const carpani = 1;
    const ek_odeme_tutari = yuvarlaParaIkiliOndalik(gunluk_ucret * carpani);
    return { tur: "UBGT", gunluk_ucret, carpani, ek_odeme_tutari };
  }

  if (kayit.gun_tipi === "Hafta_Tatili_Pazar") {
    const hak = kayit.hafta_tatili_hak_kazandi_mi;
    if (typeof hak !== "boolean") {
      if (!hasSaat) {
        return null;
      }
      return {
        tur: "HAFTA_TATILI",
        gunluk_ucret,
        carpani: 0,
        ek_odeme_tutari: 0
      };
    }

    const pazarKarar = hesaplaHaftaTatiliPazarEtkisi(hak, hasSaat, gunluk_ucret);

    return {
      tur: "HAFTA_TATILI",
      gunluk_ucret,
      carpani: pazarKarar.ek_odeme_gun_carpani,
      ek_odeme_tutari: pazarKarar.ek_odeme_tutari,
      hafta_tatili_pazar_karar: pazarKarar
    };
  }

  return null;
}

// ---------------------------------------------------------------------------
// Saat ayrıştırma
// ---------------------------------------------------------------------------

function parseTimeToMinutes(value: string): number | null {
  const match = /^(\d{1,2}):(\d{2})$/.exec(value.trim());
  if (!match) return null;
  const hours = Number.parseInt(match[1], 10);
  const minutes = Number.parseInt(match[2], 10);
  if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return null;
  return hours * 60 + minutes;
}

export function geceBandinaGiriyor(giris?: string, cikis?: string): boolean {
  const girisMin = giris ? parseTimeToMinutes(giris) : null;
  const cikisMin = cikis ? parseTimeToMinutes(cikis) : null;

  if (girisMin !== null && girisMin < 6 * 60) {
    return true;
  }

  if (cikisMin !== null && cikisMin >= 20 * 60) {
    return true;
  }

  return false;
}

// ---------------------------------------------------------------------------
// Brüt çalışma süresi (giriş-çıkış farkı, dakika)
// ---------------------------------------------------------------------------

export function hesaplaBrutSure(giris?: string, cikis?: string): number {
  if (!giris || !cikis) return 0;
  const g = parseTimeToMinutes(giris);
  const c = parseTimeToMinutes(cikis);
  if (g === null || c === null) return 0;
  const fark = c - g;
  return fark > 0 ? fark : 0;
}

// ---------------------------------------------------------------------------
// İş Kanunu md.68 – Otomatik mola düşümü
//   0 dk                      → 0 dk mola
//   1 – 240 dk (4 saat)       → 15 dk mola
//   241 – 450 dk (4-7.5 saat) → 30 dk mola
//   > 450 dk (7.5 saat+)      → 60 dk mola
// ---------------------------------------------------------------------------

export function hesaplaYasalMolaDakika(brutDakika: number): number {
  if (brutDakika <= 0) return 0;
  if (brutDakika <= 240) return 15;
  if (brutDakika <= 450) return 30;
  return 60;
}

// ---------------------------------------------------------------------------
// Net çalışma süresi = brüt - max(gerçek mola, yasal minimum mola)
// ---------------------------------------------------------------------------

export function hesaplaNetSure(
  brutDakika: number,
  gercekMolaDakika: number | undefined,
  yasalMolaDakika: number
): number {
  const uygulanacakMola = Math.max(gercekMolaDakika ?? 0, yasalMolaDakika);
  const net = brutDakika - uygulanacakMola;
  return net > 0 ? net : 0;
}

// ---------------------------------------------------------------------------
// Gün tipi türetme (tarihten)
// ---------------------------------------------------------------------------

function parseDateOnly(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) return null;
  return new Date(
    Number.parseInt(match[1], 10),
    Number.parseInt(match[2], 10) - 1,
    Number.parseInt(match[3], 10)
  );
}

export function deriveGunTipi(tarih: string, explicit?: PuantajGunTipi): PuantajGunTipi {
  if (explicit) return explicit;
  const d = parseDateOnly(tarih);
  if (d?.getDay() === 0) return "Hafta_Tatili_Pazar";
  return "Normal_Is_Gunu";
}

// ---------------------------------------------------------------------------
// Hareket durumu türetme
// ---------------------------------------------------------------------------

export function deriveHareketDurumu(
  explicit?: PuantajHareketDurumu,
  giris?: string,
  cikis?: string,
  dayanak?: PuantajDayanak
): PuantajHareketDurumu {
  if (explicit) return explicit;
  if (giris || cikis) return "Geldi";
  return "Gelmedi";
}

// ---------------------------------------------------------------------------
// Dayanak türetme
// ---------------------------------------------------------------------------

export function deriveDayanak(
  explicit?: PuantajDayanak,
  hareketDurumu?: PuantajHareketDurumu,
  giris?: string,
  cikis?: string
): PuantajDayanak | undefined {
  if (explicit) return explicit;
  if (!giris && !cikis && hareketDurumu === "Gelmedi") return "Yok_Izinsiz";
  return undefined;
}

// ---------------------------------------------------------------------------
// Hesap etkisi türetme
// ---------------------------------------------------------------------------

export function deriveHesapEtkisi(
  gunTipi: PuantajGunTipi,
  hareketDurumu: PuantajHareketDurumu,
  dayanak?: PuantajDayanak,
  giris?: string,
  cikis?: string,
  explicit?: PuantajHesapEtkisi
): PuantajHesapEtkisi | undefined {
  if (explicit) return explicit;

  if (hareketDurumu === "Gelmedi" && dayanak === "Yok_Izinsiz") {
    return "Kesinti_Yap";
  }

  if (
    (gunTipi === "Hafta_Tatili_Pazar" || gunTipi === "UBGT_Resmi_Tatil") &&
    (giris || cikis)
  ) {
    return "Mesai_Yaz";
  }

  if (dayanak && dayanak !== "Yok_Izinsiz") {
    return "Tam_Yevmiye_Ver";
  }

  if (hareketDurumu === "Geldi" || hareketDurumu === "Gec_Geldi" || hareketDurumu === "Erken_Cikti") {
    return "Tam_Yevmiye_Ver";
  }

  return undefined;
}

// ---------------------------------------------------------------------------
// Hafta tatili hakkı (İş Kanunu md.46)
//
// Normal iş gününde Gelmedi + Yok_Izinsiz → hak KAYBI (false)
// Ücretli izin, raporlu, yıllık izin, iş kazası vb. → hak KORUNUR (true)
// Geldi / Geç geldi / Erken çıktı → hak KORUNUR (true)
// ---------------------------------------------------------------------------

export function hesaplaHaftaTatiliHakki(
  hareketDurumu: PuantajHareketDurumu,
  dayanak?: PuantajDayanak
): boolean {
  if (hareketDurumu === "Gelmedi" && dayanak === "Yok_Izinsiz") {
    return false;
  }

  if (hareketDurumu === "Gelmedi" && (dayanak === undefined || dayanak === "Yok_Izinsiz")) {
    return false;
  }

  return true;
}

// ---------------------------------------------------------------------------
// Compliance uyarıları üretimi
// ---------------------------------------------------------------------------

const MAX_GUNLUK_CALISMA_DAKIKA = 660; // 11 saat
const GECE_MESAI_BASLANGIC = 20 * 60; // 20:00

export function uretComplianceUyarilari(
  brutDakika: number,
  netDakika: number,
  giris?: string,
  cikis?: string
): ComplianceUyari[] {
  const uyarilar: ComplianceUyari[] = [];

  if (netDakika > MAX_GUNLUK_CALISMA_DAKIKA) {
    uyarilar.push({
      code: "MAX_DAILY_LIMIT",
      message: "Günlük çalışma süresi yasal üst sınırı (11 saat) aşıyor.",
      level: "KRITIK"
    });
  } else if (netDakika > 450) {
    uyarilar.push({
      code: "MAX_DAILY_LIMIT",
      message: "Günlük çalışma süresi kritik eşikte.",
      level: "UYARI"
    });
  }

  if (cikis) {
    const cikisMin = parseTimeToMinutes(cikis);
    if (cikisMin !== null && cikisMin >= GECE_MESAI_BASLANGIC) {
      uyarilar.push({
        code: "GECE_MESAI",
        message: "Çıkış saati gece mesai bandında (20:00 sonrası).",
        level: "BILGI"
      });
    }
  }

  return uyarilar;
}

export function hesaplaYasKuraliBlokMesaji(
  girdi: Pick<
    HesapGirdisi,
    "tarih" | "gun_tipi" | "hareket_durumu" | "dayanak" | "hesap_etkisi" | "giris_saati" | "cikis_saati"
  > & {
    dogum_tarihi?: string;
  }
): string | null {
  if (!girdi.dogum_tarihi) {
    return null;
  }

  const yas = hesaplaYas(girdi.dogum_tarihi, girdi.tarih);
  if (yas === null || yas > 18) {
    return null;
  }

  const gunTipi = deriveGunTipi(girdi.tarih, girdi.gun_tipi);
  const hareketDurumu = deriveHareketDurumu(
    girdi.hareket_durumu,
    girdi.giris_saati,
    girdi.cikis_saati,
    girdi.dayanak
  );
  const dayanak = deriveDayanak(
    girdi.dayanak,
    hareketDurumu,
    girdi.giris_saati,
    girdi.cikis_saati
  );
  const hesapEtkisi = deriveHesapEtkisi(
    gunTipi,
    hareketDurumu,
    dayanak,
    girdi.giris_saati,
    girdi.cikis_saati,
    girdi.hesap_etkisi
  );

  if (geceBandinaGiriyor(girdi.giris_saati, girdi.cikis_saati)) {
    return "Yasal Uyari: 18 yas alti personele gece calismasi girilemez.";
  }

  if (hesapEtkisi === "Mesai_Yaz") {
    return "Yasal Uyari: 18 yas alti personele fazla mesai girilemez.";
  }

  return null;
}

// ---------------------------------------------------------------------------
// ANA HESAPLAMA FONKSİYONU
//
// Saf fonksiyon: UI/React bağımlılığı yok. Girdi alır, sonuç döner.
// ---------------------------------------------------------------------------

export function hesapla(girdi: HesapGirdisi): HesapSonucu {
  const gunTipi = deriveGunTipi(girdi.tarih, girdi.gun_tipi);
  const hareketDurumu = deriveHareketDurumu(
    girdi.hareket_durumu,
    girdi.giris_saati,
    girdi.cikis_saati,
    girdi.dayanak
  );
  const dayanak = deriveDayanak(
    girdi.dayanak,
    hareketDurumu,
    girdi.giris_saati,
    girdi.cikis_saati
  );
  const hesapEtkisi = deriveHesapEtkisi(
    gunTipi,
    hareketDurumu,
    dayanak,
    girdi.giris_saati,
    girdi.cikis_saati,
    girdi.hesap_etkisi
  );

  const brutDakika = hesaplaBrutSure(girdi.giris_saati, girdi.cikis_saati);
  const yasalMola = hesaplaYasalMolaDakika(brutDakika);
  const netDakika = hesaplaNetSure(brutDakika, girdi.gercek_mola_dakika, yasalMola);
  const haftaTatiliHakki = hesaplaHaftaTatiliHakki(hareketDurumu, dayanak);
  const uyarilar = uretComplianceUyarilari(
    brutDakika,
    netDakika,
    girdi.giris_saati,
    girdi.cikis_saati
  );

  return {
    personel_id: girdi.personel_id,
    tarih: girdi.tarih,
    gun_tipi: gunTipi,
    hareket_durumu: hareketDurumu,
    dayanak,
    hesap_etkisi: hesapEtkisi,
    giris_saati: girdi.giris_saati,
    cikis_saati: girdi.cikis_saati,
    gercek_mola_dakika: girdi.gercek_mola_dakika,
    hesaplanan_mola_dakika: yasalMola,
    gunluk_brut_sure_dakika: brutDakika,
    net_calisma_suresi_dakika: netDakika,
    hafta_tatili_hak_kazandi_mi: haftaTatiliHakki,
    compliance_uyarilari: uyarilar
  };
}

// ---------------------------------------------------------------------------
// GunlukPuantaj'dan HesapGirdisi oluşturma (API → Motor adaptörü)
// ---------------------------------------------------------------------------

export function gunlukPuantajToGirdi(p: GunlukPuantaj): HesapGirdisi {
  return {
    personel_id: p.personel_id,
    tarih: p.tarih,
    gun_tipi: p.gun_tipi,
    hareket_durumu: p.hareket_durumu,
    dayanak: p.dayanak,
    hesap_etkisi: p.hesap_etkisi,
    giris_saati: p.giris_saati,
    cikis_saati: p.cikis_saati,
    gercek_mola_dakika: p.gercek_mola_dakika
  };
}

// ---------------------------------------------------------------------------
// HesapSonucu'ndan GunlukPuantaj'a dönüştürme
// ---------------------------------------------------------------------------

export function hesapSonucuToGunlukPuantaj(
  sonuc: HesapSonucu,
  state?: string,
  options?: { kontrol_durumu?: PuantajAmirKontrolDurumu }
): GunlukPuantaj {
  return {
    personel_id: sonuc.personel_id,
    tarih: sonuc.tarih,
    gun_tipi: sonuc.gun_tipi,
    hareket_durumu: sonuc.hareket_durumu,
    dayanak: sonuc.dayanak,
    hesap_etkisi: sonuc.hesap_etkisi,
    giris_saati: sonuc.giris_saati,
    cikis_saati: sonuc.cikis_saati,
    gercek_mola_dakika: sonuc.gercek_mola_dakika,
    hesaplanan_mola_dakika: sonuc.hesaplanan_mola_dakika,
    net_calisma_suresi_dakika: sonuc.net_calisma_suresi_dakika,
    gunluk_brut_sure_dakika: sonuc.gunluk_brut_sure_dakika,
    hafta_tatili_hak_kazandi_mi: sonuc.hafta_tatili_hak_kazandi_mi,
    state: state ?? "HESAPLANDI",
    kontrol_durumu: options?.kontrol_durumu ?? "BEKLIYOR",
    compliance_uyarilari: sonuc.compliance_uyarilari
  };
}
