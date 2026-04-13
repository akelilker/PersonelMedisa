import type {
  ComplianceUyari,
  GunlukPuantaj,
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajHesapEtkisi
} from "../types/puantaj";

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
  state?: string
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
    compliance_uyarilari: sonuc.compliance_uyarilari
  };
}
