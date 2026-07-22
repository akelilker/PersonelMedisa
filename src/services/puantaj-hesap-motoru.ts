import type {
  ComplianceUyari,
  GunlukPuantaj,
  PuantajAmirKontrolDurumu,
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajHesapEtkisi
} from "../types/puantaj";
import type { HastalikRaporGunuCozumu } from "./hastalik-rapor-politikasi";
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
  gec_kalma_dakika?: number | null;
  erken_cikis_dakika?: number | null;
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
 * Fazla Çalışma Yönetmeliği: ödeme esas dakika.
 * Tam saatten kalan süre 30 dk'dan azsa 30, 30'dan fazlaysa 60 sayılır; tam 30/60 değişmez.
 * Yalnız FM/FSC ödeme zincirinde kullanılır; geç/erken kesintisine uygulanmaz.
 */
export function hesaplaMevzuatFazlaCalismaOdemeDakika(gercekDakika: number): number {
  const dk = Math.floor(ucretIcinGuvenliNegatifOlmayanSayi(gercekDakika));
  if (dk <= 0) {
    return 0;
  }

  const tamSaatDakika = Math.floor(dk / 60) * 60;
  const kalan = dk % 60;
  if (kalan === 0 || kalan === 30) {
    return dk;
  }

  return tamSaatDakika + (kalan < 30 ? 30 : 60);
}

/**
 * Bir haftaya ait günlük net çalışma dakikalarından haftalık özeti üretir.
 * - toplam: satırların net dakikalarının toplamı (güvenli satır birleştirme)
 * - normal: min(toplam, haftalık eşik)
 * - fazla: max(toplam − eşik, 0) — ham; mevzuat yuvarlama ödeme katmanında
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
export function hesaplaSaatlikUcret(
  maasTutari: number,
  aylikNormalCalismaSaati = AYLIK_MAAS_SAAT_PAYDASI
): number {
  const maas = ucretIcinGuvenliPozitifMaas(maasTutari);
  const payda = ucretIcinGuvenliPozitifMaas(aylikNormalCalismaSaati);
  if (maas === 0 || payda === 0) return 0;
  // PHP Money::mulDiv gibi saatlik taban once kurusa yuvarlanir.
  return yuvarlaParaIkiliOndalik(maas / payda);
}

function hesaplaCarpanliFazlaCalismaTutari(
  hamDakika: number,
  saatlikUcret: number,
  carpan: number
): number {
  const odemeEsasDakika = hesaplaMevzuatFazlaCalismaOdemeDakika(hamDakika);
  const su = ucretIcinGuvenliNegatifOlmayanSayi(saatlikUcret);
  const oran = ucretIcinGuvenliNegatifOlmayanSayi(carpan);
  const matrah = yuvarlaParaIkiliOndalik(su * (odemeEsasDakika / 60));
  return yuvarlaParaIkiliOndalik(matrah * oran);
}

/**
 * Fazla mesai tutarı: saatlik × 1.5 × (ödeme esas dakika / 60).
 * Ham dakika önce mevzuat yuvarlamasından geçer; tutar 2 ondalığa yuvarlanır.
 */
export function hesaplaFazlaCalismaTutari(fazlaCalismaDakika: number, saatlikUcret: number): number {
  return hesaplaCarpanliFazlaCalismaTutari(
    fazlaCalismaDakika,
    saatlikUcret,
    FAZLA_CALISMA_UCRET_CARPANI
  );
}

/** Haftalık süre özeti + saatlik ücret ve FM tutarı. */
export type HaftalikCalismaVeFazlaUcretOzeti = HaftalikCalismaOzeti & {
  saatlik_ucret: number;
  /** Mevzuat yuvarlaması sonrası ödeme esas FM dakikası. */
  odeme_esas_fazla_calisma_dakika: number;
  fazla_calisma_saat: number;
  fazla_calisma_tutari: number;
};

/**
 * `HaftalikCalismaOzeti` üzerinden saatlik ücret ve haftalık fazla çalışma parasını ekler.
 * `fazla_calisma_dakika` ham kalır; ödeme `odeme_esas_fazla_calisma_dakika` üzerinden yapılır.
 */
export function hesaplaHaftalikFazlaCalismaUcreti(
  ozet: HaftalikCalismaOzeti,
  maasTutari: number
): HaftalikCalismaVeFazlaUcretOzeti {
  const hamSaatlik = hesaplaSaatlikUcret(maasTutari);
  const odeme_esas_fazla_calisma_dakika = hesaplaMevzuatFazlaCalismaOdemeDakika(
    ozet.fazla_calisma_dakika
  );
  const fazla_calisma_saat = odeme_esas_fazla_calisma_dakika / 60;
  const fazla_calisma_tutari = hesaplaFazlaCalismaTutari(ozet.fazla_calisma_dakika, hamSaatlik);

  return {
    ...ozet,
    saatlik_ucret: yuvarlaParaIkiliOndalik(hamSaatlik),
    odeme_esas_fazla_calisma_dakika,
    fazla_calisma_saat,
    fazla_calisma_tutari
  };
}

// ---------------------------------------------------------------------------
// Günlük puantaj → haftalık fazla mesai ücret özeti (servis adapter)
// ---------------------------------------------------------------------------

export const FAZLA_SURELERLE_CALISMA_UCRET_CARPANI = 1.25;
export const YARGITAY_HOLIDAY_OVERTIME_MODE = "YARGITAY_7_5_SAAT_AYRIMI";
export const YARGITAY_HOLIDAY_SPLIT_MINUTES = 450;
export const HOLIDAY_OVERTIME_POLICY_REQUIRED = "HOLIDAY_OVERTIME_POLICY_REQUIRED";
export const TATIL_FSC_FM_CAKISMA_POLITIKASI_EKSIK =
  "TATIL_FSC_FM_CAKISMA_POLITIKASI_EKSIK";
export const HOLIDAY_OVERTIME_POLICY_REQUIRED_MESSAGE =
  "Tatil çalışması ile fazla çalışma çakışma politikası yetkili onayı bekliyor";
export const UBGT_DAY_SCOPE_ERROR_CODE = "UBGT_DAY_SCOPE_REQUIRED";
export const UBGT_DAY_SCOPE_BLOCKER_CODE = "UBGT_GUN_KAPSAMI_EKSIK";
export const UBGT_DAY_SCOPE_ERROR_MESSAGE =
  "Resmî tatilin tam gün veya yarım gün kapsamı doğrulanamadığı için otomatik hesaplama yapılamıyor";
export const HALF_DAY_UBGT_POLICY_ERROR_CODE = "HALF_DAY_UBGT_POLICY_REQUIRED";
export const HALF_DAY_UBGT_POLICY_BLOCKER_CODE = "YARIM_GUN_UBGT_HESAP_POLITIKASI_EKSIK";
export const HALF_DAY_UBGT_POLICY_ERROR_MESSAGE =
  "Yarım günlük resmî tatil çalışma hesabı için tatil dönemi net çalışma süresi ve yetkili hesap politikası eksik";

export type EngineV2TatilHesapModu =
  | "GUNLUK_ILAVE"
  | "SAAT_CARPAN"
  | "GUNLUK_ILAVE_VE_SAAT_CARPAN";

export type EngineV2HaftalikPolitika = {
  gunluk_calisma_saati: number;
  haftalik_is_gunu_sayisi: number;
  aylik_normal_calisma_saati: number;
  hafta_tatili_hesap_modu: EngineV2TatilHesapModu;
  hafta_tatili_carpani: number;
  ubgt_hesap_modu: EngineV2TatilHesapModu;
  ubgt_carpani: number;
  tatil_fsc_fm_cakisma_hesap_modu?: string | null;
};

export const ENGINE_V2_VARSAYILAN_HAFTALIK_POLITIKA: EngineV2HaftalikPolitika = {
  gunluk_calisma_saati: 8,
  haftalik_is_gunu_sayisi: 5,
  aylik_normal_calisma_saati: AYLIK_MAAS_SAAT_PAYDASI,
  hafta_tatili_hesap_modu: "GUNLUK_ILAVE",
  hafta_tatili_carpani: 1,
  ubgt_hesap_modu: "GUNLUK_ILAVE",
  ubgt_carpani: 1
};

/** Engine V2 haftalık FS/FM bantları + ücret + hafta aralığı. */
export type HaftalikPuantajUcretOzeti = {
  hesaplanabilir_mi: boolean;
  hata_kodu:
    | typeof HOLIDAY_OVERTIME_POLICY_REQUIRED
    | typeof UBGT_DAY_SCOPE_ERROR_CODE
    | typeof HALF_DAY_UBGT_POLICY_ERROR_CODE
    | null;
  hata_mesaji: string | null;
  toplam_net_dakika: number;
  normal_calisma_dakika: number;
  haftalik_esik_dakika: number;
  sozlesme_haftalik_dakika: number;
  normal_gun_calisma_dakika: number;
  tatil_calisma_dakika: number;
  fazla_surelerle_calisma_dakika: number;
  odeme_esas_fazla_surelerle_calisma_dakika: number;
  fazla_surelerle_calisma_saat: number;
  fazla_surelerle_calisma_tutari: number;
  fazla_calisma_dakika: number;
  odeme_esas_fazla_calisma_dakika: number;
  fazla_calisma_saat: number;
  fazla_calisma_tutari: number;
  toplam_fazla_calisma_tutari: number;
  saatlik_ucret: number;
  hafta_baslangic: string | null;
  hafta_bitis: string | null;
};

function hesaplaEngineV2HaftalikBantlari(
  toplamDakika: number,
  sozlesmeHaftalikDakika: number
): { fazla_surelerle_calisma_dakika: number; fazla_calisma_dakika: number } {
  const toplam = Math.floor(ucretIcinGuvenliNegatifOlmayanSayi(toplamDakika));
  const sozlesme = Math.min(
    Math.floor(ucretIcinGuvenliNegatifOlmayanSayi(sozlesmeHaftalikDakika)),
    HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA
  );
  const fazla_surelerle_calisma_dakika = Math.min(
    Math.max(toplam - sozlesme, 0),
    Math.max(HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA - sozlesme, 0)
  );
  const fazla_calisma_dakika = Math.max(
    toplam - HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA,
    0
  );

  return { fazla_surelerle_calisma_dakika, fazla_calisma_dakika };
}

type HolidayDayClass = { ht: boolean; ubgt: boolean; both: boolean };

function resolveHolidayOvertimeMode(mode: string | null | undefined): string | null {
  const normalized = String(mode ?? "")
    .trim()
    .toUpperCase();
  if (!normalized || normalized.startsWith("TEST_")) {
    return null;
  }
  return normalized === YARGITAY_HOLIDAY_OVERTIME_MODE ? YARGITAY_HOLIDAY_OVERTIME_MODE : null;
}

function classifyHolidayDay(gun: GunlukPuantaj): HolidayDayClass {
  let ht = gun.gun_tipi === "Hafta_Tatili_Pazar";
  let ubgt = gun.gun_tipi === "UBGT_Resmi_Tatil";
  const siniflar = (gun as GunlukPuantaj & { gun_siniflandirmalari?: string[] })
    .gun_siniflandirmalari;
  if (Array.isArray(siniflar)) {
    for (const sinif of siniflar) {
      if (sinif === "Hafta_Tatili_Pazar") ht = true;
      if (sinif === "UBGT_Resmi_Tatil") ubgt = true;
    }
  }
  if ((gun as GunlukPuantaj & { ht_ubgt_ayni_gun_mi?: boolean }).ht_ubgt_ayni_gun_mi) {
    ht = true;
    ubgt = true;
  }
  return { ht, ubgt, both: ht && ubgt };
}

function holidayOtPoolMinutes(netDk: number, isHoliday: boolean): number {
  const net = Math.max(0, Math.floor(netDk));
  if (!isHoliday) return net;
  return Math.max(0, net - YARGITAY_HOLIDAY_SPLIT_MINUTES);
}

/** Canonical UBGT gün kapsamı. Tarih/net dakika/magic listeden tahmin yok. */
export function resolveUbgtGunKapsami(
  row: Pick<GunlukPuantaj, "ubgt_gun_kapsami" | "tatil_gun_kapsami"> | Record<string, unknown>
): "TAM_GUN" | "YARIM_GUN" | "BILINMIYOR" {
  const extended = row as {
    ubgt_gun_kapsami?: string | null;
    tatil_gun_kapsami?: string | null;
    tatil_siniflandirma_durumu?: string | null;
  };
  const sinif = String(extended.tatil_siniflandirma_durumu ?? "")
    .trim()
    .toUpperCase();
  if (sinif === "CAKISMA" || sinif === "KAYNAK_EKSIK" || sinif === "BILINMIYOR") {
    return "BILINMIYOR";
  }
  let raw: string | null = null;
  if (extended.ubgt_gun_kapsami !== undefined && extended.ubgt_gun_kapsami !== null) {
    raw = String(extended.ubgt_gun_kapsami);
  } else if (extended.tatil_gun_kapsami !== undefined && extended.tatil_gun_kapsami !== null) {
    raw = String(extended.tatil_gun_kapsami);
  }
  if (raw === null) {
    return "BILINMIYOR";
  }
  const normalized = raw.trim().toUpperCase();
  if (normalized === "TAM_GUN" || normalized === "YARIM_GUN") {
    return normalized;
  }
  return "BILINMIYOR";
}

function detectUbgtScopeConflict(gunler: readonly GunlukPuantaj[]): {
  has_conflict: boolean;
  error_code: typeof UBGT_DAY_SCOPE_ERROR_CODE | typeof HALF_DAY_UBGT_POLICY_ERROR_CODE | null;
  message: string | null;
} {
  const unknownRows: GunlukPuantaj[] = [];
  const halfDayRows: GunlukPuantaj[] = [];
  for (const gun of gunler) {
    const net = haftalikNetDakikaSatir(gun.net_calisma_suresi_dakika);
    if (net < 1) continue;
    const cls = classifyHolidayDay(gun);
    // Precedence 1: HT+UBGT aynı gün → HT esas; UBGT kapsam blocker üretilmez.
    if (cls.both || !cls.ubgt || cls.ht) continue;
    const kapsam = resolveUbgtGunKapsami(gun);
    if (kapsam === "BILINMIYOR") unknownRows.push(gun);
    else if (kapsam === "YARIM_GUN") halfDayRows.push(gun);
  }
  if (unknownRows.length > 0) {
    return {
      has_conflict: true,
      error_code: UBGT_DAY_SCOPE_ERROR_CODE,
      message: UBGT_DAY_SCOPE_ERROR_MESSAGE
    };
  }
  if (halfDayRows.length > 0) {
    return {
      has_conflict: true,
      error_code: HALF_DAY_UBGT_POLICY_ERROR_CODE,
      message: HALF_DAY_UBGT_POLICY_ERROR_MESSAGE
    };
  }
  return { has_conflict: false, error_code: null, message: null };
}

function buildFmEvaluationPoolDk(gunler: readonly GunlukPuantaj[]): number {
  let total = 0;
  for (const gun of gunler) {
    const net = haftalikNetDakikaSatir(gun.net_calisma_suresi_dakika);
    if (net < 1) continue;
    const cls = classifyHolidayDay(gun);
    if (cls.both || cls.ht) {
      total += holidayOtPoolMinutes(net, true);
      continue;
    }
    if (cls.ubgt) {
      if (resolveUbgtGunKapsami(gun) !== "TAM_GUN") continue;
      total += holidayOtPoolMinutes(net, true);
      continue;
    }
    if (gun.gun_tipi === "Normal_Is_Gunu") {
      total += net;
    }
  }
  return total;
}

/**
 * `GunlukPuantaj` satırları (çağıranın tek personel listesi vermesi beklenir),
 * referans tarih ve maaş ile haftalık fazla mesai ücret özetini üretir.
 * Motorları birleştirir; veri çekmez, throw etmez.
 */
export function hesaplaHaftalikPuantajUcretOzeti(
  gunler: readonly GunlukPuantaj[],
  referansTarih: string,
  maasTutari: number,
  politika: EngineV2HaftalikPolitika = ENGINE_V2_VARSAYILAN_HAFTALIK_POLITIKA
): HaftalikPuantajUcretOzeti {
  const aralik = hesaplaHaftaAraligi(referansTarih);
  const hafta = filtreleHaftalikPuantajSatirlari(gunler, referansTarih).filter(
    (gun) =>
      gun.gun_tipi !== undefined &&
      ["Normal_Is_Gunu", "Hafta_Tatili_Pazar", "UBGT_Resmi_Tatil"].includes(gun.gun_tipi)
  );
  const scopeConflict = detectUbgtScopeConflict(hafta);
  if (scopeConflict.has_conflict && scopeConflict.error_code && scopeConflict.message) {
    return {
      hesaplanabilir_mi: false,
      hata_kodu: scopeConflict.error_code,
      hata_mesaji: scopeConflict.message,
      toplam_net_dakika: 0,
      normal_calisma_dakika: 0,
      haftalik_esik_dakika: HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA,
      sozlesme_haftalik_dakika: 0,
      normal_gun_calisma_dakika: 0,
      tatil_calisma_dakika: 0,
      fazla_surelerle_calisma_dakika: 0,
      odeme_esas_fazla_surelerle_calisma_dakika: 0,
      fazla_surelerle_calisma_saat: 0,
      fazla_surelerle_calisma_tutari: 0,
      fazla_calisma_dakika: 0,
      odeme_esas_fazla_calisma_dakika: 0,
      fazla_calisma_saat: 0,
      fazla_calisma_tutari: 0,
      toplam_fazla_calisma_tutari: 0,
      saatlik_ucret: 0,
      hafta_baslangic: aralik?.hafta_baslangic ?? null,
      hafta_bitis: aralik?.hafta_bitis ?? null
    };
  }
  const normal_gun_calisma_dakika = hafta
    .filter((gun) => gun.gun_tipi === "Normal_Is_Gunu")
    .reduce(
      (toplam, gun) => toplam + haftalikNetDakikaSatir(gun.net_calisma_suresi_dakika),
      0
    );
  const tatilGunleri = hafta.filter((gun) => {
    const cls = classifyHolidayDay(gun);
    if (cls.both || cls.ht) return true;
    if (cls.ubgt) return resolveUbgtGunKapsami(gun) === "TAM_GUN";
    return false;
  });
  const tatil_calisma_dakika = tatilGunleri.reduce(
    (toplam, gun) => toplam + haftalikNetDakikaSatir(gun.net_calisma_suresi_dakika),
    0
  );
  const toplam_net_dakika = normal_gun_calisma_dakika + tatil_calisma_dakika;
  const gunlukCalismaDakika = Math.round(
    ucretIcinGuvenliNegatifOlmayanSayi(politika.gunluk_calisma_saati) * 60
  );
  const haftalikIsGunu = Math.floor(
    ucretIcinGuvenliNegatifOlmayanSayi(politika.haftalik_is_gunu_sayisi)
  );
  const sozlesme_haftalik_dakika = Math.min(
    gunlukCalismaDakika * haftalikIsGunu,
    HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA
  );
  const normal_calisma_dakika = Math.min(toplam_net_dakika, sozlesme_haftalik_dakika);
  const approvedMode = resolveHolidayOvertimeMode(politika.tatil_fsc_fm_cakisma_hesap_modu);
  const evaluationPoolDk =
    approvedMode !== null ? buildFmEvaluationPoolDk(hafta) : toplam_net_dakika;
  const bantlar = hesaplaEngineV2HaftalikBantlari(
    evaluationPoolDk,
    sozlesme_haftalik_dakika
  );
  const fullPoolBantlar = hesaplaEngineV2HaftalikBantlari(
    toplam_net_dakika,
    sozlesme_haftalik_dakika
  );
  const fazla_surelerle_calisma_dakika = bantlar.fazla_surelerle_calisma_dakika;
  const fazla_calisma_dakika = bantlar.fazla_calisma_dakika;
  const tatilFscFmCakismasi =
    approvedMode === null &&
    tatil_calisma_dakika > 0 &&
    (fullPoolBantlar.fazla_surelerle_calisma_dakika > 0 ||
      fullPoolBantlar.fazla_calisma_dakika > 0);
  const odeme_esas_fazla_surelerle_calisma_dakika = tatilFscFmCakismasi
    ? 0
    : hesaplaMevzuatFazlaCalismaOdemeDakika(fazla_surelerle_calisma_dakika);
  const odeme_esas_fazla_calisma_dakika = tatilFscFmCakismasi
    ? 0
    : hesaplaMevzuatFazlaCalismaOdemeDakika(fazla_calisma_dakika);
  const saatlikUcretHam = hesaplaSaatlikUcret(
    maasTutari,
    politika.aylik_normal_calisma_saati
  );
  const fazla_surelerle_calisma_tutari = tatilFscFmCakismasi
    ? 0
    : hesaplaCarpanliFazlaCalismaTutari(
        fazla_surelerle_calisma_dakika,
        saatlikUcretHam,
        FAZLA_SURELERLE_CALISMA_UCRET_CARPANI
      );
  const fazla_calisma_tutari = tatilFscFmCakismasi
    ? 0
    : hesaplaCarpanliFazlaCalismaTutari(
        fazla_calisma_dakika,
        saatlikUcretHam,
        FAZLA_CALISMA_UCRET_CARPANI
      );

  return {
    hesaplanabilir_mi: !tatilFscFmCakismasi,
    hata_kodu: tatilFscFmCakismasi ? HOLIDAY_OVERTIME_POLICY_REQUIRED : null,
    hata_mesaji: tatilFscFmCakismasi ? HOLIDAY_OVERTIME_POLICY_REQUIRED_MESSAGE : null,
    toplam_net_dakika,
    normal_calisma_dakika,
    haftalik_esik_dakika: HAFTALIK_NORMAL_CALISMA_ESIK_DAKIKA,
    sozlesme_haftalik_dakika,
    normal_gun_calisma_dakika,
    tatil_calisma_dakika,
    fazla_surelerle_calisma_dakika,
    odeme_esas_fazla_surelerle_calisma_dakika,
    fazla_surelerle_calisma_saat: odeme_esas_fazla_surelerle_calisma_dakika / 60,
    fazla_surelerle_calisma_tutari,
    fazla_calisma_dakika,
    odeme_esas_fazla_calisma_dakika,
    fazla_calisma_saat: odeme_esas_fazla_calisma_dakika / 60,
    fazla_calisma_tutari,
    toplam_fazla_calisma_tutari: yuvarlaParaIkiliOndalik(
      fazla_surelerle_calisma_tutari + fazla_calisma_tutari
    ),
    saatlik_ucret: yuvarlaParaIkiliOndalik(saatlikUcretHam),
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
  gec_kalma_dakika?: number | null;
  erken_cikis_dakika?: number | null;
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

/**
 * Geç/erken kesintiye esas dakika (ayrı owner; mevzuat FM yuvarlaması değil).
 * Engine V2 ile aynı şekilde ham tam dakika korunur.
 */
export function hesaplaKesintiyeEsasDakika(gercekEksikDakika: number): number {
  return Math.floor(ucretIcinGuvenliNegatifOlmayanSayi(gercekEksikDakika));
}

function readAuthoritativeDakika(value: number | null | undefined): number | undefined {
  if (value === null || value === undefined) {
    return undefined;
  }

  if (!Number.isFinite(value) || value < 0) {
    return undefined;
  }

  return Math.floor(value);
}

/**
 * Geç kalma / erken çıkma için beklenen ve gerçek saatlerden güvenli eksik süre üretir.
 * Açık dakika alanları (gec_kalma_dakika / erken_cikis_dakika) varsa saat hesabından önce authoritative kabul edilir.
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
    const authoritativeDakika = readAuthoritativeDakika(girdi.gec_kalma_dakika);
    if (authoritativeDakika !== undefined) {
      return {
        hesaplanabilir_mi: true,
        eksik_dakika: authoritativeDakika,
        tip: "GEC_KALMA"
      };
    }

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

  const authoritativeDakika = readAuthoritativeDakika(girdi.erken_cikis_dakika);
  if (authoritativeDakika !== undefined) {
    return {
      hesaplanabilir_mi: true,
      eksik_dakika: authoritativeDakika,
      tip: "ERKEN_CIKMA"
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
// Ay sonu SGK prim günü hesabı (tam gün eksik gün çekirdeği)
// ---------------------------------------------------------------------------

export type PuantajEksikGunUcretEtkisiTuru =
  | "YOK"
  | "DAKIKA_BAZLI_KESINTI_ADAYI"
  | "GUNLUK_KESINTI_ADAYI"
  | "UCRET_KORUNUR"
  | "POLITIKA_INCELEMESI";

export type PuantajEksikGunSiniflandirmaGirdisi = {
  hareket_durumu?: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  durumu_bildirdi_mi?: boolean | null;
  /** S63-2: cozumleHastalikRaporGunu ciktisi; yalnizca Raporlu_Hastalik icin kullanilir. */
  hastalik_rapor_cozumu?: HastalikRaporGunuCozumu;
};

export type PuantajEksikGunSiniflandirmaSonucu = {
  eksik_gun_adayi_mi: boolean;
  eksik_gun_sayisi: number;
  sgk_prim_gununu_dusurur_mu: boolean;
  ucret_etkisi_turu: PuantajEksikGunUcretEtkisiTuru;
  manuel_inceleme_gerekli_mi: boolean;
  haberli_yokluk_sinyali_mi: boolean;
  habersiz_yokluk_sinyali_mi: boolean;
  aciklama: string;
};

function bildirimSinyali(
  hareketDurumu: PuantajHareketDurumu | undefined,
  durumuBildirdiMi: boolean | null | undefined
) {
  return {
    haberli_yokluk_sinyali_mi: hareketDurumu === "Gelmedi" && durumuBildirdiMi === true,
    habersiz_yokluk_sinyali_mi: hareketDurumu === "Gelmedi" && durumuBildirdiMi === false
  };
}

const RAPORLU_HASTALIK_SGK_KORUMA = {
  eksik_gun_sayisi: 0,
  sgk_prim_gununu_dusurur_mu: false
} as const;

/**
 * Hastalik raporu policy resolver ciktisini puantaj eksik gun ucret etkisi siniflandirmasina cevirir.
 * Is kazasi raporu bu fonksiyonla yonetilmez.
 */
export function siniflandirHastalikRaporGunUcretEtkisi(
  cozum: HastalikRaporGunuCozumu
): Pick<
  PuantajEksikGunSiniflandirmaSonucu,
  | "eksik_gun_adayi_mi"
  | "eksik_gun_sayisi"
  | "sgk_prim_gununu_dusurur_mu"
  | "ucret_etkisi_turu"
  | "manuel_inceleme_gerekli_mi"
  | "aciklama"
> {
  switch (cozum.ucret_policy) {
    case "KESINTI_ADAYI":
      return {
        eksik_gun_adayi_mi: true,
        ...RAPORLU_HASTALIK_SGK_KORUMA,
        ucret_etkisi_turu: "GUNLUK_KESINTI_ADAYI",
        manuel_inceleme_gerekli_mi: false,
        aciklama:
          "Hastalik raporu ilk iki gun ve firma odemez: gunluk ucret kesintisi adayi; SGK prim gunu otomatik dusumu uretilmez."
      };
    case "UCRET_KORUNUR":
      return {
        eksik_gun_adayi_mi: false,
        ...RAPORLU_HASTALIK_SGK_KORUMA,
        ucret_etkisi_turu: "UCRET_KORUNUR",
        manuel_inceleme_gerekli_mi: false,
        aciklama:
          "Hastalik raporu ilk iki gun ve firma oder: ucret korunur; SGK prim gunu otomatik dusumu uretilmez."
      };
    case "POLITIKA_INCELEMESI":
      return {
        eksik_gun_adayi_mi: true,
        ...RAPORLU_HASTALIK_SGK_KORUMA,
        ucret_etkisi_turu: "POLITIKA_INCELEMESI",
        manuel_inceleme_gerekli_mi: cozum.manuel_inceleme_gerekli_mi,
        aciklama:
          "Hastalik raporu gunu icin ucret/SGK politikasi manuel inceleme gerektirir; otomatik kesinti veya koruma karari uretilmez."
      };
    case "YOK":
    default:
      return {
        eksik_gun_adayi_mi: true,
        ...RAPORLU_HASTALIK_SGK_KORUMA,
        ucret_etkisi_turu: "POLITIKA_INCELEMESI",
        manuel_inceleme_gerekli_mi: true,
        aciklama:
          "Raporlu hastalik gunu icin eslesen surec bulunamadi; ucret ve SGK prim gunu karari manuel inceleme gerektirir."
      };
  }
}

/**
 * Gunluk puantaj satirini eksik gun / SGK prim gunu / ucret etkisi acisindan
 * siniflandirir. Resmi SGK kodu, bordro tutari veya finans kalemi uretmez.
 */
export function siniflandirPuantajEksikGunEtkisi(
  girdi: PuantajEksikGunSiniflandirmaGirdisi
): PuantajEksikGunSiniflandirmaSonucu {
  const hareketDurumu = girdi.hareket_durumu;
  const dayanak = girdi.dayanak;
  const bildirim = bildirimSinyali(hareketDurumu, girdi.durumu_bildirdi_mi);

  if (hareketDurumu === "Gec_Geldi" || hareketDurumu === "Erken_Cikti") {
    return {
      eksik_gun_adayi_mi: false,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "DAKIKA_BAZLI_KESINTI_ADAYI",
      manuel_inceleme_gerekli_mi: false,
      ...bildirim,
      aciklama: "Gec kalma / erken cikma dakika bazli ucret etkisidir; SGK prim gununu dusuren tam gun eksiklik degildir."
    };
  }

  if (hareketDurumu === "Geldi") {
    return {
      eksik_gun_adayi_mi: false,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "YOK",
      manuel_inceleme_gerekli_mi: false,
      ...bildirim,
      aciklama: "Geldi kaydi eksik gun veya SGK prim gunu dusumu uretmez."
    };
  }

  if (hareketDurumu !== "Gelmedi") {
    return {
      eksik_gun_adayi_mi: false,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true,
      ...bildirim,
      aciklama: "Hareket durumu net olmadigi icin eksik gun karari manuel inceleme gerektirir."
    };
  }

  if (dayanak === "Yok_Izinsiz") {
    return {
      eksik_gun_adayi_mi: true,
      eksik_gun_sayisi: 1,
      sgk_prim_gununu_dusurur_mu: true,
      ucret_etkisi_turu: "GUNLUK_KESINTI_ADAYI",
      manuel_inceleme_gerekli_mi: false,
      ...bildirim,
      aciklama: "Gelmedi + Yok_Izinsiz, ucret hak edilmeyen tam gun eksiklik adayidir."
    };
  }

  if (dayanak === "Ucretli_Izinli" || dayanak === "Yillik_Izin" || dayanak === "Telafi_Calismasi" || dayanak === "Gorevde_Calisma") {
    return {
      eksik_gun_adayi_mi: false,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "UCRET_KORUNUR",
      manuel_inceleme_gerekli_mi: false,
      ...bildirim,
      aciklama: "Ucretli izin / yillik izin / telafi calismasi SGK prim gununu dusuren eksik gun olarak siniflandirilmaz."
    };
  }

  if (dayanak === "Raporlu_Hastalik" && girdi.hastalik_rapor_cozumu) {
    return {
      ...siniflandirHastalikRaporGunUcretEtkisi(girdi.hastalik_rapor_cozumu),
      ...bildirim
    };
  }

  if (dayanak === "Raporlu_Hastalik" || dayanak === "Raporlu_Is_Kazasi") {
    return {
      eksik_gun_adayi_mi: true,
      eksik_gun_sayisi: 0,
      sgk_prim_gununu_dusurur_mu: false,
      ucret_etkisi_turu: "POLITIKA_INCELEMESI",
      manuel_inceleme_gerekli_mi: true,
      ...bildirim,
      aciklama: "Raporlu gun SGK prim gunu dusumu icin otomatik karar uretmez; rapor turu ve bordro politikasi manuel inceleme gerektirir."
    };
  }

  return {
    eksik_gun_adayi_mi: true,
    eksik_gun_sayisi: 0,
    sgk_prim_gununu_dusurur_mu: false,
    ucret_etkisi_turu: "POLITIKA_INCELEMESI",
    manuel_inceleme_gerekli_mi: true,
    ...bildirim,
    aciklama: "Gelmedi kaydinda dayanak netlesmeden ucretli/ucretsiz veya SGK prim gunu karari uretilemez."
  };
}

export type AylikPuantajEksikGunOzetiGirdisi = {
  kayitlar: readonly PuantajEksikGunSiniflandirmaGirdisi[];
};

export type AylikPuantajEksikGunOzetiSonucu = {
  toplam_kayit_sayisi: number;
  eksik_gun_adayi_kayit_sayisi: number;
  sgk_prim_gununu_dusuren_eksik_gun_sayisi: number;
  manuel_inceleme_kayit_sayisi: number;
  dakika_bazli_ucret_etkisi_adayi_sayisi: number;
  gunluk_kesinti_adayi_sayisi: number;
  ucret_korunan_kayit_sayisi: number;
  haberli_yokluk_sinyali_sayisi: number;
  habersiz_yokluk_sinyali_sayisi: number;
  kesin_sgk_prim_gunu_hesaplanabilir_mi: boolean;
  siniflandirmalar: PuantajEksikGunSiniflandirmaSonucu[];
  aciklama: string;
};

export function hesaplaAylikPuantajEksikGunOzeti(
  girdi: AylikPuantajEksikGunOzetiGirdisi
): AylikPuantajEksikGunOzetiSonucu {
  const siniflandirmalar = girdi.kayitlar.map((kayit) => siniflandirPuantajEksikGunEtkisi(kayit));

  const toplam_kayit_sayisi = siniflandirmalar.length;
  const eksik_gun_adayi_kayit_sayisi = siniflandirmalar.filter((s) => s.eksik_gun_adayi_mi).length;
  const sgk_prim_gununu_dusuren_eksik_gun_sayisi = siniflandirmalar.reduce(
    (toplam, s) => toplam + (s.sgk_prim_gununu_dusurur_mu ? s.eksik_gun_sayisi : 0),
    0
  );
  const manuel_inceleme_kayit_sayisi = siniflandirmalar.filter((s) => s.manuel_inceleme_gerekli_mi).length;
  const dakika_bazli_ucret_etkisi_adayi_sayisi = siniflandirmalar.filter(
    (s) => s.ucret_etkisi_turu === "DAKIKA_BAZLI_KESINTI_ADAYI"
  ).length;
  const gunluk_kesinti_adayi_sayisi = siniflandirmalar.filter(
    (s) => s.ucret_etkisi_turu === "GUNLUK_KESINTI_ADAYI"
  ).length;
  const ucret_korunan_kayit_sayisi = siniflandirmalar.filter((s) => s.ucret_etkisi_turu === "UCRET_KORUNUR").length;
  const haberli_yokluk_sinyali_sayisi = siniflandirmalar.filter((s) => s.haberli_yokluk_sinyali_mi).length;
  const habersiz_yokluk_sinyali_sayisi = siniflandirmalar.filter((s) => s.habersiz_yokluk_sinyali_mi).length;
  const kesin_sgk_prim_gunu_hesaplanabilir_mi = manuel_inceleme_kayit_sayisi === 0;

  return {
    toplam_kayit_sayisi,
    eksik_gun_adayi_kayit_sayisi,
    sgk_prim_gununu_dusuren_eksik_gun_sayisi,
    manuel_inceleme_kayit_sayisi,
    dakika_bazli_ucret_etkisi_adayi_sayisi,
    gunluk_kesinti_adayi_sayisi,
    ucret_korunan_kayit_sayisi,
    haberli_yokluk_sinyali_sayisi,
    habersiz_yokluk_sinyali_sayisi,
    kesin_sgk_prim_gunu_hesaplanabilir_mi,
    siniflandirmalar,
    aciklama: kesin_sgk_prim_gunu_hesaplanabilir_mi
      ? "Aylik eksik gun ozeti manuel inceleme gerektiren kayit olmadan hesaplandi."
      : "Aylik eksik gun ozeti manuel inceleme gerektiren kayitlar icerir; kesin SGK prim gunu karari verilmemelidir."
  };
}

export type SgkPrimGunuHesapGirdisi = {
  takvim_gunu: number;
  eksik_gun: number;
  sgk_prim_gununu_dusurur_mu: boolean;
};

export type SgkPrimGunuHesapSonucu = SgkPrimGunuHesapGirdisi & {
  hesaplanabilir_mi: boolean;
  sgk_gunu: number;
  neden?: "GECERSIZ_TAKVIM_GUNU" | "GECERSIZ_EKSIK_GUN";
  aciklama: string;
};

export type SgkUcretTipi = "MAKTU_AYLIK" | "GUNLUK_YEVMIYE";

export type SgkPrimGunuHesaplamaModu = "OTUZ_GUN_STANDART" | "TAKVIM_GUNU";

export type AylikSgkPrimGunuHesapGirdisi = {
  yil: number;
  ay: number;
  eksik_gun_sayisi?: number;
  ucret_tipi?: SgkUcretTipi;
};

export type AylikSgkPrimGunuHesapSonucu = {
  yil: number;
  ay: number;
  ayin_takvim_gun_sayisi: number;
  eksik_gun_sayisi: number;
  ucret_tipi: SgkUcretTipi;
  hesaplama_modu: SgkPrimGunuHesaplamaModu;
  sgk_prim_gun: number;
};

function assertTamSayi(value: number, label: string) {
  if (!Number.isInteger(value)) {
    throw new Error(`${label} tam sayi olmalidir.`);
  }
}

export function hesaplaAyinTakvimGunSayisi(yil: number, ay: number): number {
  assertTamSayi(yil, "Yil");
  assertTamSayi(ay, "Ay");

  if (ay < 1 || ay > 12) {
    throw new Error("Ay 1 ile 12 arasinda olmalidir.");
  }

  return new Date(yil, ay, 0).getDate();
}

function normalizeEksikGunSayisi(eksikGunSayisi: number | undefined, takvimGunSayisi: number): number {
  const normalized = eksikGunSayisi ?? 0;
  assertTamSayi(normalized, "Eksik gun sayisi");

  if (normalized < 0) {
    throw new Error("Eksik gun sayisi sifirdan kucuk olamaz.");
  }

  if (normalized > takvimGunSayisi) {
    throw new Error("Eksik gun sayisi ayin takvim gun sayisini asamaz.");
  }

  return normalized;
}

/**
 * Ay sonu SGK prim günü: yalnız ücret hak edilmeyen ve SGK prim gününü düşüren
 * tam gün eksiklikler için `max(0, min(30, takvim_gunu - eksik_gun))`.
 */
export function hesaplaSgkPrimGunu(
  girdi: AylikSgkPrimGunuHesapGirdisi
): AylikSgkPrimGunuHesapSonucu;
export function hesaplaSgkPrimGunu(
  girdi: SgkPrimGunuHesapGirdisi
): SgkPrimGunuHesapSonucu;
export function hesaplaSgkPrimGunu(
  girdi: SgkPrimGunuHesapGirdisi | AylikSgkPrimGunuHesapGirdisi
): SgkPrimGunuHesapSonucu | AylikSgkPrimGunuHesapSonucu {
  if ("yil" in girdi && "ay" in girdi) {
    const ayinTakvimGunSayisi = hesaplaAyinTakvimGunSayisi(girdi.yil, girdi.ay);
    const eksikGunSayisi = normalizeEksikGunSayisi(girdi.eksik_gun_sayisi, ayinTakvimGunSayisi);
    const ucretTipi = girdi.ucret_tipi ?? "MAKTU_AYLIK";

    if (ucretTipi === "MAKTU_AYLIK" && eksikGunSayisi === 0) {
      return {
        yil: girdi.yil,
        ay: girdi.ay,
        ayin_takvim_gun_sayisi: ayinTakvimGunSayisi,
        eksik_gun_sayisi: eksikGunSayisi,
        ucret_tipi: ucretTipi,
        hesaplama_modu: "OTUZ_GUN_STANDART",
        sgk_prim_gun: 30
      };
    }

    return {
      yil: girdi.yil,
      ay: girdi.ay,
      ayin_takvim_gun_sayisi: ayinTakvimGunSayisi,
      eksik_gun_sayisi: eksikGunSayisi,
      ucret_tipi: ucretTipi,
      hesaplama_modu: "TAKVIM_GUNU",
      sgk_prim_gun: ayinTakvimGunSayisi - eksikGunSayisi
    };
  }

  const { takvim_gunu, eksik_gun, sgk_prim_gununu_dusurur_mu } = girdi;

  if (!Number.isFinite(takvim_gunu) || takvim_gunu <= 0) {
    return {
      ...girdi,
      hesaplanabilir_mi: false,
      sgk_gunu: 0,
      neden: "GECERSIZ_TAKVIM_GUNU",
      aciklama: "Takvim günü pozitif ve geçerli bir sayı olmalıdır."
    };
  }

  if (!sgk_prim_gununu_dusurur_mu) {
    return {
      ...girdi,
      hesaplanabilir_mi: true,
      sgk_gunu: 30,
      aciklama: "SGK prim gününü düşüren tam gün eksiklik yok; prim günü 30 kabul edilir."
    };
  }

  if (!Number.isFinite(eksik_gun) || eksik_gun < 0) {
    return {
      ...girdi,
      hesaplanabilir_mi: false,
      sgk_gunu: 0,
      neden: "GECERSIZ_EKSIK_GUN",
      aciklama: "Eksik gün negatif veya geçersiz olamaz."
    };
  }

  return {
    ...girdi,
    hesaplanabilir_mi: true,
    sgk_gunu: Math.max(0, Math.min(30, takvim_gunu - eksik_gun)),
    aciklama: "SGK prim günü ücret hak edilmeyen tam gün eksiklik üzerinden hesaplandı."
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
  kayit: Pick<
    GunlukPuantaj,
    "gun_tipi" | "hesap_etkisi" | "giris_saati" | "cikis_saati" | "ubgt_gun_kapsami" | "tatil_gun_kapsami"
  > & {
    hafta_tatili_hak_kazandi_mi?: boolean;
    ht_ubgt_ayni_gun_mi?: boolean;
    gun_siniflandirmalari?: string[];
  }
): TatilEkOdemeOzeti | null {
  if (kayit.hesap_etkisi !== "Mesai_Yaz") {
    return null;
  }

  const hasSaat =
    Boolean(kayit.giris_saati?.trim()) || Boolean(kayit.cikis_saati?.trim());
  const isPazar = kayit.gun_tipi === "Hafta_Tatili_Pazar";
  const cls = classifyHolidayDay(kayit as GunlukPuantaj);

  if (!hasSaat && !isPazar) {
    return null;
  }
  if (kayit.gun_tipi === "UBGT_Resmi_Tatil" && !hasSaat) {
    return null;
  }

  const gunluk_ucret = hesaplaGunlukUcret(maasTutari);

  // Yalnız açıkça TAM_GUN UBGT satırı UBGT ödemesi üretir (HT+UBGT aynı gün hariç).
  if (cls.ubgt && !cls.both) {
    const kapsam = resolveUbgtGunKapsami(kayit);
    if (kapsam !== "TAM_GUN") {
      return null;
    }
    const carpani = 1;
    const ek_odeme_tutari = yuvarlaParaIkiliOndalik(gunluk_ucret * carpani);
    return { tur: "UBGT", gunluk_ucret, carpani, ek_odeme_tutari };
  }

  // HT veya HT+UBGT aynı gün: HT esas (tek ödeme kalemi).
  if (cls.ht || kayit.gun_tipi === "Hafta_Tatili_Pazar") {
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

const GECE_BAND_SABAH_BITIS_DK = 6 * 60; // 06:00 (band [00:00, 06:00))
const GECE_BAND_AKSAM_BASLANGIC_DK = 20 * 60; // 20:00 (band [20:00, 24:00))
const GUN_TOPLAM_DK = 24 * 60;

function zamanAraligiKesisimDakika(
  aralikBas: number,
  aralikBit: number,
  bandBas: number,
  bandBit: number
): number {
  return Math.max(0, Math.min(aralikBit, bandBit) - Math.max(aralikBas, bandBas));
}

/**
 * Aynı gün giriş–çıkış kaydında gece bandına (20:00–06:00) düşen brüt çalışma dakikası.
 * Mola gece bandına dağıtılmaz (Faz D3 minimum).
 * Gece yarısı geçişi (çıkış ≤ giriş) veya saat eksikliği → null.
 */
export function hesaplaGeceCalismaDakika(giris?: string, cikis?: string): number | null {
  if (!giris?.trim() || !cikis?.trim()) {
    return null;
  }

  const girisMin = parseTimeToMinutes(giris);
  const cikisMin = parseTimeToMinutes(cikis);
  if (girisMin === null || cikisMin === null) {
    return null;
  }

  if (cikisMin <= girisMin) {
    return null;
  }

  const sabahBandi = zamanAraligiKesisimDakika(girisMin, cikisMin, 0, GECE_BAND_SABAH_BITIS_DK);
  const aksamBandi = zamanAraligiKesisimDakika(
    girisMin,
    cikisMin,
    GECE_BAND_AKSAM_BASLANGIC_DK,
    GUN_TOPLAM_DK
  );

  return sabahBandi + aksamBandi;
}

export function geceBandinaGiriyor(giris?: string, cikis?: string): boolean {
  const girisMin = giris ? parseTimeToMinutes(giris) : null;
  const cikisMin = cikis ? parseTimeToMinutes(cikis) : null;

  if (girisMin !== null && girisMin < GECE_BAND_SABAH_BITIS_DK) {
    return true;
  }

  if (cikisMin !== null && cikisMin >= GECE_BAND_AKSAM_BASLANGIC_DK) {
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
// Hafta tatili günü (V1 varsayılan: Pazar) — tek domain kaynağı
// ---------------------------------------------------------------------------

export type HaftaTatiliGunKodu = 0 | 1 | 2 | 3 | 4 | 5 | 6;

export const VARSAYILAN_HAFTA_TATILI_GUN_KODU: HaftaTatiliGunKodu = 0;

function parseDateOnly(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) return null;
  return new Date(
    Number.parseInt(match[1], 10),
    Number.parseInt(match[2], 10) - 1,
    Number.parseInt(match[3], 10)
  );
}

export function isHaftaTatiliGunu(
  tarih: string,
  haftaTatiliGunKodu: HaftaTatiliGunKodu = VARSAYILAN_HAFTA_TATILI_GUN_KODU
): boolean {
  const d = parseDateOnly(tarih);
  if (!d) return false;
  return d.getDay() === haftaTatiliGunKodu;
}

// ---------------------------------------------------------------------------
// Gün tipi türetme (tarihten)
// ---------------------------------------------------------------------------

export function deriveGunTipi(
  tarih: string,
  explicit?: PuantajGunTipi,
  haftaTatiliGunKodu: HaftaTatiliGunKodu = VARSAYILAN_HAFTA_TATILI_GUN_KODU
): PuantajGunTipi {
  if (explicit) return explicit;
  if (isHaftaTatiliGunu(tarih, haftaTatiliGunKodu)) return "Hafta_Tatili_Pazar";
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

function isRaporDayanak(dayanak?: PuantajDayanak): boolean {
  return dayanak === "Raporlu_Hastalik" || dayanak === "Raporlu_Is_Kazasi";
}

function hasPuantajSaati(giris?: string, cikis?: string): boolean {
  return Boolean(giris?.trim()) || Boolean(cikis?.trim());
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
  if (isRaporDayanak(dayanak)) {
    return undefined;
  }

  if (explicit) return explicit;

  if (hareketDurumu === "Gelmedi" && dayanak === "Yok_Izinsiz") {
    return "Yevmiye_Kes";
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

/** Gece bandı (20:00–06:00) brüt çalışma süresi günlük üst sınırı — Faz D3. */
export const GECE_CALISMA_GUNLUK_ESIK_DAKIKA = 450;

export const GECE_CALISMASI_7_5_SAAT_ASIMI_CODE = "GECE_CALISMASI_7_5_SAAT_ASIMI";

export const GECE_CALISMASI_7_5_SAAT_ASIMI_MESSAGE =
  "Gece çalışma süresi günlük 7,5 saat sınırını aşıyor.";

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

  const geceBandiBrutDakika = hesaplaGeceCalismaDakika(giris, cikis);
  if (
    geceBandiBrutDakika !== null &&
    geceBandiBrutDakika > GECE_CALISMA_GUNLUK_ESIK_DAKIKA
  ) {
    uyarilar.push({
      code: GECE_CALISMASI_7_5_SAAT_ASIMI_CODE,
      message: GECE_CALISMASI_7_5_SAAT_ASIMI_MESSAGE,
      level: "UYARI"
    });
  }

  return uyarilar;
}

function complianceUyariKoduVar(mevcut: readonly ComplianceUyari[], code: string): boolean {
  return mevcut.some((uyari) => uyari.code === code);
}

/** Mazeretsiz devamsızlık — ücret etkisi / hafta tatili kaybı aday uyarıları (Faz B). */
export const DEVAMSIZLIK_UCRET_ETKISI_ADAYI_CODE = "DEVAMSIZLIK_UCRET_ETKISI_ADAYI";

export const DEVAMSIZLIK_UCRET_ETKISI_ADAYI_MESSAGE =
  "Mazeretsiz devamsızlık için ücret etkisi bordro adayıdır; kesin kesinti değildir.";

export const HAFTA_TATILI_HAK_KAYBI_ADAYI_CODE = "HAFTA_TATILI_HAK_KAYBI_ADAYI";

export const HAFTA_TATILI_HAK_KAYBI_ADAYI_MESSAGE =
  "Hafta tatili hakkı kaybı adayı oluştu; ek etki hafta/ay kapanışında kesinleşir.";

export const BORDRO_ETKISI_KESINLESME_NOTU =
  "Bordro etkisi hafta/ay kapanışında kesinleşir.";

export type MazeretsizDevamsizlikKayitGirdi = {
  hareket_durumu?: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  hafta_tatili_hak_kazandi_mi?: boolean;
};

export function isMazeretsizDevamsizlikKaydi(kayit: MazeretsizDevamsizlikKayitGirdi): boolean {
  return kayit.hareket_durumu === "Gelmedi" && kayit.dayanak === "Yok_Izinsiz";
}

export function mazeretsizDevamsizlikParasalNetKilitliMi(kayit: MazeretsizDevamsizlikKayitGirdi): boolean {
  return isMazeretsizDevamsizlikKaydi(kayit);
}

/** Parasal ön izlemede mazeretsiz devamsızlık kesintisi net etkiden düşülmez (referans alanı ayrı). */
export function parasalNetEtkidenDusulecekKesintiTutari(
  toplamKesintiTutari: number,
  mazeretsizDevamsizlikAdayi: boolean
): number {
  return mazeretsizDevamsizlikAdayi ? 0 : toplamKesintiTutari;
}

export function uretDevamsizlikUcretEtkisiAdayiUyari(): ComplianceUyari {
  return {
    code: DEVAMSIZLIK_UCRET_ETKISI_ADAYI_CODE,
    message: DEVAMSIZLIK_UCRET_ETKISI_ADAYI_MESSAGE,
    level: "UYARI"
  };
}

export function uretHaftaTatiliHakKaybiAdayiUyari(): ComplianceUyari {
  return {
    code: HAFTA_TATILI_HAK_KAYBI_ADAYI_CODE,
    message: HAFTA_TATILI_HAK_KAYBI_ADAYI_MESSAGE,
    level: "UYARI"
  };
}

/**
 * Gelmedi + Yok_Izinsiz kaydına devamsızlık / hafta tatili kaybı aday compliance uyarılarını ekler.
 */
export function birlestirMazeretsizDevamsizlikAdayUyariari(
  mevcut: readonly ComplianceUyari[],
  kayit: MazeretsizDevamsizlikKayitGirdi
): ComplianceUyari[] {
  if (!isMazeretsizDevamsizlikKaydi(kayit)) {
    return [...mevcut];
  }

  let result = [...mevcut];
  if (!complianceUyariKoduVar(result, DEVAMSIZLIK_UCRET_ETKISI_ADAYI_CODE)) {
    result = [...result, uretDevamsizlikUcretEtkisiAdayiUyari()];
  }
  if (
    kayit.hafta_tatili_hak_kazandi_mi === false &&
    !complianceUyariKoduVar(result, HAFTA_TATILI_HAK_KAYBI_ADAYI_CODE)
  ) {
    result = [...result, uretHaftaTatiliHakKaybiAdayiUyari()];
  }
  return result;
}

/** UBGT mesai günü + haftalık fazla mesai çakışması (Faz A — yalnız uyarı, tutar değiştirmez). */
export const UBGT_FAZLA_MESAI_CAKISMASI_CODE = "UBGT_FAZLA_MESAI_CAKISMASI";

export const UBGT_FAZLA_MESAI_CAKISMASI_MESSAGE =
  "UBGT çalışması ile haftalık fazla mesai aynı haftada çakışıyor. Bordro etkisi manuel incelenmelidir.";

export type UbgtFazlaMesaiCakismaGunGirdi = {
  gun_tipi?: PuantajGunTipi;
  hesap_etkisi?: PuantajHesapEtkisi;
  net_calisma_suresi_dakika?: number;
};

export function isUbgtMesaiCalismaGunu(gun: UbgtFazlaMesaiCakismaGunGirdi): boolean {
  const net = ucretIcinGuvenliNegatifOlmayanSayi(gun.net_calisma_suresi_dakika ?? 0);
  return (
    gun.gun_tipi === "UBGT_Resmi_Tatil" &&
    gun.hesap_etkisi === "Mesai_Yaz" &&
    net > 0
  );
}

export function uretUbgtFazlaMesaiCakismaUyari(): ComplianceUyari {
  return {
    code: UBGT_FAZLA_MESAI_CAKISMASI_CODE,
    message: UBGT_FAZLA_MESAI_CAKISMASI_MESSAGE,
    level: "UYARI"
  };
}

/**
 * Tam haftalık puantaj verisi varken, UBGT mesai gününe haftalık fazla mesai çakışma uyarısını ekler.
 * Eksik hafta veya çakışma yoksa listeyi olduğu gibi döner.
 */
export function birlestirUbgtFazlaMesaiCakismaUyari(
  mevcut: readonly ComplianceUyari[],
  gun: UbgtFazlaMesaiCakismaGunGirdi,
  haftaGunleri: readonly HaftalikGunNetCalisma[],
  tamHaftaVerisi: boolean
): ComplianceUyari[] {
  if (!tamHaftaVerisi) {
    return [...mevcut];
  }
  if (!isUbgtMesaiCalismaGunu(gun)) {
    return [...mevcut];
  }
  const fazlaDk = hesaplaHaftalikCalismaOzeti(haftaGunleri).fazla_calisma_dakika;
  if (fazlaDk <= 0) {
    return [...mevcut];
  }
  if (complianceUyariKoduVar(mevcut, UBGT_FAZLA_MESAI_CAKISMASI_CODE)) {
    return [...mevcut];
  }
  return [...mevcut, uretUbgtFazlaMesaiCakismaUyari()];
}

/** 18 yaş altı personelde haftalık fazla çalışma (Faz D2 — yalnız uyarı, tutar/blok değiştirmez). */
export const ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE = "ONSEKIZ_YAS_ALTI_FAZLA_CALISMA";

export const ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_MESSAGE =
  "18 yaş altı personel için haftalık fazla çalışma tespit edildi; mevzuat uyumu manuel doğrulanmalıdır.";

/** Fazla çalışma / gece yasağı: yalnız 18 yaş altı (`yas < 18`). Yıllık izin `<=18` ayrı predicate. */
export function isFazlaCalismaGeceYasagiKapsaminda(
  dogumTarihi: string,
  referansTarih: string
): boolean {
  const yas = hesaplaYas(dogumTarihi, referansTarih);
  return yas !== null && yas < 18;
}

export function uretOnsekizYasAltiFazlaCalismaUyari(): ComplianceUyari {
  return {
    code: ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE,
    message: ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_MESSAGE,
    level: "UYARI"
  };
}

export type OnsekizYasAltiFazlaCalismaUyariGirdi = {
  dogum_tarihi?: string;
  referans_tarih: string;
  fazla_calisma_dakika: number;
  tam_hafta_verisi: boolean;
};

/**
 * Tam haftalık puantaj verisi varken, 18 yaş altı personel için haftalık fazla çalışma uyarısını ekler.
 * Doğum tarihi yok/geçersiz, FM yok veya tam hafta eksikse listeyi olduğu gibi döner.
 */
export function birlestirOnsekizYasAltiFazlaCalismaUyari(
  mevcut: readonly ComplianceUyari[],
  girdi: OnsekizYasAltiFazlaCalismaUyariGirdi
): ComplianceUyari[] {
  if (!girdi.tam_hafta_verisi) {
    return [...mevcut];
  }
  if (!girdi.dogum_tarihi?.trim()) {
    return [...mevcut];
  }
  if (girdi.fazla_calisma_dakika <= 0) {
    return [...mevcut];
  }
  if (!isFazlaCalismaGeceYasagiKapsaminda(girdi.dogum_tarihi, girdi.referans_tarih)) {
    return [...mevcut];
  }
  if (complianceUyariKoduVar(mevcut, ONSEKIZ_YAS_ALTI_FAZLA_CALISMA_CODE)) {
    return [...mevcut];
  }
  return [...mevcut, uretOnsekizYasAltiFazlaCalismaUyari()];
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

  if (!isFazlaCalismaGeceYasagiKapsaminda(girdi.dogum_tarihi, girdi.tarih)) {
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

  if (isRaporDayanak(dayanak) && hasPuantajSaati(girdi.giris_saati, girdi.cikis_saati)) {
    uyarilar.push({
      code: "RAPOR_CALISMA_CAKISMASI",
      message:
        "Raporlu personel için çalışma saati girilmiş; kayıt normal çalışma veya mesai sayılmamalıdır.",
      level: "KRITIK"
    });
  }

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
    gec_kalma_dakika: p.gec_kalma_dakika,
    erken_cikis_dakika: p.erken_cikis_dakika,
    gercek_mola_dakika: p.gercek_mola_dakika
  };
}

// ---------------------------------------------------------------------------
// HesapSonucu'ndan GunlukPuantaj'a dönüştürme
// ---------------------------------------------------------------------------

export function hesapSonucuToGunlukPuantaj(
  sonuc: HesapSonucu,
  state?: string,
  options?: {
    kontrol_durumu?: PuantajAmirKontrolDurumu;
    gec_kalma_dakika?: number | null;
    erken_cikis_dakika?: number | null;
    beklenen_giris_saati?: string;
    beklenen_cikis_saati?: string;
  }
): GunlukPuantaj {
  return {
    personel_id: sonuc.personel_id,
    tarih: sonuc.tarih,
    gun_tipi: sonuc.gun_tipi,
    hareket_durumu: sonuc.hareket_durumu,
    dayanak: sonuc.dayanak,
    hesap_etkisi: sonuc.hesap_etkisi,
    beklenen_giris_saati: options?.beklenen_giris_saati,
    beklenen_cikis_saati: options?.beklenen_cikis_saati,
    giris_saati: sonuc.giris_saati,
    cikis_saati: sonuc.cikis_saati,
    gec_kalma_dakika: options?.gec_kalma_dakika ?? undefined,
    erken_cikis_dakika: options?.erken_cikis_dakika ?? undefined,
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
