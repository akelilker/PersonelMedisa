import type { Surec } from "../types/surec";
import type { PuantajGunTipi } from "../types/puantaj";

// ---------------------------------------------------------------------------
// Giriş tipi
// ---------------------------------------------------------------------------

export type IzinHesapGirdisi = {
  ise_giris_tarihi: string;
  dogum_tarihi?: string;
  referans_tarih?: string;
};

// ---------------------------------------------------------------------------
// Çıkış tipi
// ---------------------------------------------------------------------------

export type IzinHakEdis = {
  kidem_yil: number;
  yas: number | null;
  yillik_izin_gun: number;
  yas_istisna_uygulandi: boolean;
};

export type CanonicalIzinTakvimGunu = {
  tarih: string;
  gun_tipi?: PuantajGunTipi;
};

export type IzinKullanimOzeti = {
  kullanilan_gun: number | null;
  sayilan_normal_gun: number;
  haric_tutulan_hafta_tatili_gun: number;
  haric_tutulan_ubgt_gun: number;
  takvim_dogrulandi_mi: boolean;
  eksik_takvim_tarihleri: string[];
};

export type IzinBakiye = {
  hak_edis: IzinHakEdis;
  kullanilan_gun: number | null;
  kalan_gun: number | null;
  kullanim_ozeti: IzinKullanimOzeti;
};

// ---------------------------------------------------------------------------
// Tarih yardımcıları
// ---------------------------------------------------------------------------

function parseDate(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) return null;
  return new Date(
    Number.parseInt(match[1], 10),
    Number.parseInt(match[2], 10) - 1,
    Number.parseInt(match[3], 10)
  );
}

function today(): Date {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

// ---------------------------------------------------------------------------
// Kıdem yılı hesaplama
// ---------------------------------------------------------------------------

export function hesaplaKidemYil(iseGirisTarihi: string, referansTarih?: string): number {
  const giris = parseDate(iseGirisTarihi);
  if (!giris) return 0;

  const ref = referansTarih ? parseDate(referansTarih) ?? today() : today();
  const diffMs = ref.getTime() - giris.getTime();
  if (diffMs < 0) return 0;

  const diffYil = ref.getFullYear() - giris.getFullYear();
  const ayFark = ref.getMonth() - giris.getMonth();
  const gunFark = ref.getDate() - giris.getDate();

  if (ayFark < 0 || (ayFark === 0 && gunFark < 0)) {
    return Math.max(diffYil - 1, 0);
  }

  return diffYil;
}

// ---------------------------------------------------------------------------
// Yaş hesaplama
// ---------------------------------------------------------------------------

export function hesaplaYas(dogumTarihi: string, referansTarih?: string): number | null {
  const dogum = parseDate(dogumTarihi);
  if (!dogum) return null;

  const ref = referansTarih ? parseDate(referansTarih) ?? today() : today();
  const diffYil = ref.getFullYear() - dogum.getFullYear();
  const ayFark = ref.getMonth() - dogum.getMonth();
  const gunFark = ref.getDate() - dogum.getDate();

  if (ayFark < 0 || (ayFark === 0 && gunFark < 0)) {
    return Math.max(diffYil - 1, 0);
  }

  return diffYil;
}

export function isYillikIzinYasIstisnasiKapsaminda(yas: number | null): boolean {
  return yas !== null && (yas <= 18 || yas >= 50);
}

// ---------------------------------------------------------------------------
// İş Kanunu md.53 – Yıllık İzin Hak Ediş
//
//   1-5 yıl (5 dahil) → 14 gün
//   5 yıldan fazla, 15 yıldan az → 20 gün
//   15+ yıl   → 26 gün
//
// Yaş istisnası: yaş <= 18 veya yaş >= 50 ise minimum 20 gün
// 1 yılını doldurmamış personel → 0 gün (henüz hak kazanmadı)
// ---------------------------------------------------------------------------

export function hesaplaYillikIzinGun(girdi: IzinHesapGirdisi): {
  gun: number;
  yas_istisna_uygulandi: boolean;
} {
  const giris = parseDate(girdi.ise_giris_tarihi);
  if (!giris) {
    return { gun: 0, yas_istisna_uygulandi: false };
  }

  const ref = girdi.referans_tarih ? parseDate(girdi.referans_tarih) ?? today() : today();
  if (ref < giris) {
    return { gun: 0, yas_istisna_uygulandi: false };
  }

  const kidemYil = hesaplaKidemYil(girdi.ise_giris_tarihi, girdi.referans_tarih);
  const yas = girdi.dogum_tarihi ? hesaplaYas(girdi.dogum_tarihi, girdi.referans_tarih) : null;

  if (kidemYil < 1) {
    return { gun: 0, yas_istisna_uygulandi: false };
  }

  let gun: number;

  if (kidemYil >= 15) {
    gun = 26;
  } else if (
    kidemYil > 5 ||
    (kidemYil === 5 && (ref.getMonth() !== giris.getMonth() || ref.getDate() !== giris.getDate()))
  ) {
    gun = 20;
  } else {
    gun = 14;
  }

  let yasIstisna = false;

  if (isYillikIzinYasIstisnasiKapsaminda(yas) && gun < 20) {
    gun = 20;
    yasIstisna = true;
  }

  return { gun, yas_istisna_uygulandi: yasIstisna };
}

// ---------------------------------------------------------------------------
// Hak ediş hesaplama (ana fonksiyon)
// ---------------------------------------------------------------------------

export function hesaplaIzinHakEdis(girdi: IzinHesapGirdisi): IzinHakEdis {
  const kidemYil = hesaplaKidemYil(girdi.ise_giris_tarihi, girdi.referans_tarih);
  const yas = girdi.dogum_tarihi ? hesaplaYas(girdi.dogum_tarihi, girdi.referans_tarih) : null;
  const { gun, yas_istisna_uygulandi } = hesaplaYillikIzinGun(girdi);

  return {
    kidem_yil: kidemYil,
    yas,
    yillik_izin_gun: gun,
    yas_istisna_uygulandi
  };
}

export type BirikmisYasalHak = {
  kidem_yil: number;
  yas: number | null;
  mevcut_yillik_hak_gun: number;
  birikmis_yasal_hak_gun: number;
  yas_istisna_uygulandi: boolean;
  accrual_breakdown: Array<{
    completed_year: number;
    anniversary: string;
    gun: number;
    yas: number | null;
    yas_istisna_uygulandi: boolean;
  }>;
};

function formatDateKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(
    date.getDate()
  ).padStart(2, "0")}`;
}

function anniversaryDate(hire: Date, completedYears: number): Date {
  return new Date(hire.getFullYear() + completedYears, hire.getMonth(), hire.getDate());
}

/**
 * Owner B — cumulative statutory accrued entitlement through referans_tarih.
 * Sums Owner A (annual band) at each completed service-year anniversary.
 * Age exception is evaluated per anniversary. Pre-first anniversary = 0.
 */
export function hesaplaBirikmisYasalHak(girdi: IzinHesapGirdisi): BirikmisYasalHak {
  const current = hesaplaIzinHakEdis(girdi);
  const giris = parseDate(girdi.ise_giris_tarihi);
  const ref = girdi.referans_tarih ? parseDate(girdi.referans_tarih) ?? today() : today();

  if (!giris || ref < giris) {
    return {
      kidem_yil: current.kidem_yil,
      yas: current.yas,
      mevcut_yillik_hak_gun: current.yillik_izin_gun,
      birikmis_yasal_hak_gun: 0,
      yas_istisna_uygulandi: current.yas_istisna_uygulandi,
      accrual_breakdown: []
    };
  }

  let birikmis = 0;
  const breakdown: BirikmisYasalHak["accrual_breakdown"] = [];

  for (let completedYear = 1; completedYear <= current.kidem_yil; completedYear += 1) {
    const anniversary = anniversaryDate(giris, completedYear);
    if (anniversary > ref) continue;
    const anniversaryKey = formatDateKey(anniversary);
    const band = hesaplaYillikIzinGun({
      ise_giris_tarihi: girdi.ise_giris_tarihi,
      dogum_tarihi: girdi.dogum_tarihi,
      referans_tarih: anniversaryKey
    });
    const yasAtAnniversary = girdi.dogum_tarihi
      ? hesaplaYas(girdi.dogum_tarihi, anniversaryKey)
      : null;
    birikmis += band.gun;
    breakdown.push({
      completed_year: completedYear,
      anniversary: anniversaryKey,
      gun: band.gun,
      yas: yasAtAnniversary,
      yas_istisna_uygulandi: band.yas_istisna_uygulandi
    });
  }

  return {
    kidem_yil: current.kidem_yil,
    yas: current.yas,
    mevcut_yillik_hak_gun: current.yillik_izin_gun,
    birikmis_yasal_hak_gun: birikmis,
    yas_istisna_uygulandi: current.yas_istisna_uygulandi,
    accrual_breakdown: breakdown
  };
}

// ---------------------------------------------------------------------------
// Süreç listesinden kullanılan yıllık izin günü hesaplama
//
// Yalnızca:
//   - surec_turu === "IZIN"
//   - alt_tur === "YILLIK_IZIN" (varsa; yoksa "IZIN" türünü sayar)
//   - state !== "IPTAL"
// ---------------------------------------------------------------------------

function listInclusiveDateKeys(baslangic: Date, bitis: Date): string[] {
  if (bitis < baslangic) return [];

  const cursor = new Date(baslangic.getFullYear(), baslangic.getMonth(), baslangic.getDate());
  const son = new Date(bitis.getFullYear(), bitis.getMonth(), bitis.getDate());
  const tarihler: string[] = [];

  while (cursor <= son) {
    tarihler.push(
      `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, "0")}-${String(
        cursor.getDate()
      ).padStart(2, "0")}`
    );
    cursor.setDate(cursor.getDate() + 1);
  }

  return tarihler;
}

function buildCanonicalTakvimMap(
  gunler: readonly CanonicalIzinTakvimGunu[]
): Map<string, PuantajGunTipi | null> {
  const takvim = new Map<string, PuantajGunTipi | null>();

  for (const gun of gunler) {
    if (!parseDate(gun.tarih) || !gun.gun_tipi) continue;
    if (!takvim.has(gun.tarih)) {
      takvim.set(gun.tarih, gun.gun_tipi);
      continue;
    }

    const mevcut = takvim.get(gun.tarih);
    if (mevcut !== gun.gun_tipi) {
      takvim.set(gun.tarih, null);
    }
  }

  return takvim;
}

export function hesaplaKullanilanIzinOzeti(
  surecler: Surec[],
  canonicalTakvimGunleri: readonly CanonicalIzinTakvimGunu[] = [],
  referansTarih?: string
): IzinKullanimOzeti {
  let sayilanNormalGun = 0;
  let haricTutulanHaftaTatiliGun = 0;
  let haricTutulanUbgtGun = 0;
  const eksikTakvimTarihleri = new Set<string>();
  const canonicalTakvim = buildCanonicalTakvimMap(canonicalTakvimGunleri);
  const asOf = referansTarih ? parseDate(referansTarih) : null;
  const asOfKey = asOf ? formatDateKey(asOf) : null;

  for (const surec of surecler) {
    if (surec.surec_turu !== "IZIN") continue;
    if (surec.alt_tur && surec.alt_tur !== "YILLIK_IZIN") continue;
    if (surec.state === "IPTAL") continue;

    const bas = surec.baslangic_tarihi ? parseDate(surec.baslangic_tarihi) : null;
    const bit = surec.bitis_tarihi ? parseDate(surec.bitis_tarihi) : null;

    if (!bas) continue;
    if (asOf && bas > asOf) continue;

    const effectiveBit = bit ?? bas;
    const cappedBit = asOf && effectiveBit > asOf ? asOf : effectiveBit;
    const tarihler = listInclusiveDateKeys(bas, cappedBit);
    for (const tarih of tarihler) {
      if (asOfKey && tarih > asOfKey) continue;
      const gunTipi = canonicalTakvim.get(tarih);
      if (!gunTipi) {
        eksikTakvimTarihleri.add(tarih);
      } else if (gunTipi === "Hafta_Tatili_Pazar") {
        haricTutulanHaftaTatiliGun += 1;
      } else if (gunTipi === "UBGT_Resmi_Tatil") {
        haricTutulanUbgtGun += 1;
      } else {
        sayilanNormalGun += 1;
      }
    }
  }

  const eksikTarihler = [...eksikTakvimTarihleri].sort();
  const takvimDogrulandiMi = eksikTarihler.length === 0;

  return {
    kullanilan_gun: takvimDogrulandiMi ? sayilanNormalGun : null,
    sayilan_normal_gun: sayilanNormalGun,
    haric_tutulan_hafta_tatili_gun: haricTutulanHaftaTatiliGun,
    haric_tutulan_ubgt_gun: haricTutulanUbgtGun,
    takvim_dogrulandi_mi: takvimDogrulandiMi,
    eksik_takvim_tarihleri: eksikTarihler
  };
}

export function hesaplaKullanilanIzinGun(
  surecler: Surec[],
  canonicalTakvimGunleri: readonly CanonicalIzinTakvimGunu[] = []
): number | null {
  return hesaplaKullanilanIzinOzeti(surecler, canonicalTakvimGunleri).kullanilan_gun;
}

// ---------------------------------------------------------------------------
// Bakiye hesaplama (hak ediş - kullanılan)
// ---------------------------------------------------------------------------

export function hesaplaIzinBakiye(
  girdi: IzinHesapGirdisi,
  surecler: Surec[],
  canonicalTakvimGunleri: readonly CanonicalIzinTakvimGunu[] = []
): IzinBakiye {
  const hakEdis = hesaplaIzinHakEdis(girdi);
  const kullanimOzeti = hesaplaKullanilanIzinOzeti(surecler, canonicalTakvimGunleri);
  const kullanilan = kullanimOzeti.kullanilan_gun;
  const kalan = kullanilan === null ? null : Math.max(hakEdis.yillik_izin_gun - kullanilan, 0);

  return {
    hak_edis: hakEdis,
    kullanilan_gun: kullanilan,
    kalan_gun: kalan,
    kullanim_ozeti: kullanimOzeti
  };
}
