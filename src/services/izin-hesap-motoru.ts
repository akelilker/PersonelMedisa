import type { Surec } from "../types/surec";

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

export type IzinBakiye = {
  hak_edis: IzinHakEdis;
  kullanilan_gun: number;
  kalan_gun: number;
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

// ---------------------------------------------------------------------------
// İş Kanunu md.53 – Yıllık İzin Hak Ediş
//
//   1-5 yıl   → 14 gün
//   5-15 yıl  → 20 gün
//   15+ yıl   → 26 gün
//
// 50 yaş istisnası: yaş >= 50 ise minimum 20 gün
// 1 yılını doldurmamış personel → 0 gün (henüz hak kazanmadı)
// ---------------------------------------------------------------------------

export function hesaplaYillikIzinGun(kidemYil: number, yas: number | null): {
  gun: number;
  yas_istisna_uygulandi: boolean;
} {
  if (kidemYil < 1) {
    return { gun: 0, yas_istisna_uygulandi: false };
  }

  let gun: number;

  if (kidemYil >= 15) {
    gun = 26;
  } else if (kidemYil >= 5) {
    gun = 20;
  } else {
    gun = 14;
  }

  let yasIstisna = false;

  if (yas !== null && yas >= 50 && gun < 20) {
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
  const { gun, yas_istisna_uygulandi } = hesaplaYillikIzinGun(kidemYil, yas);

  return {
    kidem_yil: kidemYil,
    yas,
    yillik_izin_gun: gun,
    yas_istisna_uygulandi
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

export function hesaplaKullanilanIzinGun(surecler: Surec[]): number {
  let toplam = 0;

  for (const surec of surecler) {
    if (surec.surec_turu !== "IZIN") continue;
    if (surec.alt_tur && surec.alt_tur !== "YILLIK_IZIN") continue;
    if (surec.state === "IPTAL") continue;

    const bas = surec.baslangic_tarihi ? parseDate(surec.baslangic_tarihi) : null;
    const bit = surec.bitis_tarihi ? parseDate(surec.bitis_tarihi) : null;

    if (bas && bit) {
      const diffMs = bit.getTime() - bas.getTime();
      const gun = Math.max(Math.ceil(diffMs / (1000 * 60 * 60 * 24)) + 1, 1);
      toplam += gun;
    } else if (bas) {
      toplam += 1;
    }
  }

  return toplam;
}

// ---------------------------------------------------------------------------
// Bakiye hesaplama (hak ediş - kullanılan)
// ---------------------------------------------------------------------------

export function hesaplaIzinBakiye(girdi: IzinHesapGirdisi, surecler: Surec[]): IzinBakiye {
  const hakEdis = hesaplaIzinHakEdis(girdi);
  const kullanilan = hesaplaKullanilanIzinGun(surecler);
  const kalan = Math.max(hakEdis.yillik_izin_gun - kullanilan, 0);

  return {
    hak_edis: hakEdis,
    kullanilan_gun: kullanilan,
    kalan_gun: kalan
  };
}
