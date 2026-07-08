// ---------------------------------------------------------------------------
// Hastalık raporu gün politikası resolver (S63-1)
// Saf domain fonksiyonu; puantaj hook / kesinti kartı bağlaması yok.
// ---------------------------------------------------------------------------

export type HastalikRaporSureci = {
  id?: number | string;
  personel_id: number;
  surec_turu: string;
  alt_tur?: string | null;
  baslangic_tarihi: string;
  bitis_tarihi?: string | null;
  state?: string | null;
  ilk_iki_gun_firma_oder_mi?: boolean | null;
};

export type HastalikRaporUcretPolicy =
  | "YOK"
  | "KESINTI_ADAYI"
  | "UCRET_KORUNUR"
  | "POLITIKA_INCELEMESI";

export type HastalikRaporGunuCozumu = {
  eslesme_var_mi: boolean;
  coklu_eslesme_mi: boolean;
  manuel_inceleme_gerekli_mi: boolean;
  surec_id: number | string | null;
  gun_sirasi: number | null;
  ilk_iki_gun_mu: boolean;
  firma_oder_mi: boolean | null;
  ucret_policy: HastalikRaporUcretPolicy;
};

const YOK_COZUM: HastalikRaporGunuCozumu = {
  eslesme_var_mi: false,
  coklu_eslesme_mi: false,
  manuel_inceleme_gerekli_mi: false,
  surec_id: null,
  gun_sirasi: null,
  ilk_iki_gun_mu: false,
  firma_oder_mi: null,
  ucret_policy: "YOK"
};

function parseUtcDayMs(value: string): number | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) return null;

  const y = Number.parseInt(match[1], 10);
  const m = Number.parseInt(match[2], 10);
  const d = Number.parseInt(match[3], 10);
  if (m < 1 || m > 12 || d < 1 || d > 31) return null;

  const ms = Date.UTC(y, m - 1, d);
  const check = new Date(ms);
  if (
    Number.isNaN(ms) ||
    check.getUTCFullYear() !== y ||
    check.getUTCMonth() !== m - 1 ||
    check.getUTCDate() !== d
  ) {
    return null;
  }

  return ms;
}

const MS_PER_UTC_DAY = 24 * 60 * 60 * 1000;

function hesaplaTakvimGunSirasi(baslangicTarihi: string, tarih: string): number | null {
  const basMs = parseUtcDayMs(baslangicTarihi);
  const hedefMs = parseUtcDayMs(tarih);
  if (basMs === null || hedefMs === null || hedefMs < basMs) {
    return null;
  }

  return Math.floor((hedefMs - basMs) / MS_PER_UTC_DAY) + 1;
}

function normalizeText(value: string | null | undefined): string {
  return (value ?? "").trim();
}

function isIptalSurec(surec: HastalikRaporSureci): boolean {
  return normalizeText(surec.state).toUpperCase() === "IPTAL";
}

function isHastalikRaporSureci(surec: HastalikRaporSureci): boolean {
  return (
    normalizeText(surec.surec_turu) === "RAPOR" &&
    normalizeText(surec.alt_tur) === "Raporlu_Hastalik"
  );
}

function isIsKazasiSureci(surec: HastalikRaporSureci): boolean {
  const altTur = normalizeText(surec.alt_tur);
  return altTur === "Raporlu_Is_Kazasi" || altTur === "IS_KAZASI";
}

function tarihSurecAraligindaMi(surec: HastalikRaporSureci, tarih: string): boolean {
  const basMs = parseUtcDayMs(surec.baslangic_tarihi);
  const hedefMs = parseUtcDayMs(tarih);
  if (basMs === null || hedefMs === null) {
    return false;
  }

  const bitisRaw = surec.bitis_tarihi;
  const bitMs =
    bitisRaw === null || bitisRaw === undefined || normalizeText(bitisRaw) === ""
      ? basMs
      : parseUtcDayMs(bitisRaw);

  if (bitMs === null) {
    return false;
  }

  return hedefMs >= basMs && hedefMs <= bitMs;
}

function cozumleTekEslesme(
  surec: HastalikRaporSureci,
  tarih: string
): HastalikRaporGunuCozumu {
  const gun_sirasi = hesaplaTakvimGunSirasi(surec.baslangic_tarihi, tarih);
  if (gun_sirasi === null) {
    return {
      ...YOK_COZUM,
      manuel_inceleme_gerekli_mi: true,
      ucret_policy: "POLITIKA_INCELEMESI"
    };
  }

  const ilk_iki_gun_mu = gun_sirasi <= 2;
  const firmaOderRaw = surec.ilk_iki_gun_firma_oder_mi;

  if (!ilk_iki_gun_mu) {
    return {
      eslesme_var_mi: true,
      coklu_eslesme_mi: false,
      manuel_inceleme_gerekli_mi: true,
      surec_id: surec.id ?? null,
      gun_sirasi,
      ilk_iki_gun_mu: false,
      firma_oder_mi: typeof firmaOderRaw === "boolean" ? firmaOderRaw : null,
      ucret_policy: "POLITIKA_INCELEMESI"
    };
  }

  if (firmaOderRaw === true) {
    return {
      eslesme_var_mi: true,
      coklu_eslesme_mi: false,
      manuel_inceleme_gerekli_mi: false,
      surec_id: surec.id ?? null,
      gun_sirasi,
      ilk_iki_gun_mu: true,
      firma_oder_mi: true,
      ucret_policy: "UCRET_KORUNUR"
    };
  }

  if (firmaOderRaw === false) {
    return {
      eslesme_var_mi: true,
      coklu_eslesme_mi: false,
      manuel_inceleme_gerekli_mi: false,
      surec_id: surec.id ?? null,
      gun_sirasi,
      ilk_iki_gun_mu: true,
      firma_oder_mi: false,
      ucret_policy: "KESINTI_ADAYI"
    };
  }

  return {
    eslesme_var_mi: true,
    coklu_eslesme_mi: false,
    manuel_inceleme_gerekli_mi: true,
    surec_id: surec.id ?? null,
    gun_sirasi,
    ilk_iki_gun_mu: true,
    firma_oder_mi: null,
    ucret_policy: "POLITIKA_INCELEMESI"
  };
}

/**
 * Personel ve takvim günü için aktif Raporlu_Hastalik sürecini çözer.
 * İlk 2 gün = baslangic_tarihi (1) ve ertesi takvim günü (2).
 */
export function cozumleHastalikRaporGunu(
  surecler: HastalikRaporSureci[],
  input: {
    personelId: number;
    tarih: string;
  }
): HastalikRaporGunuCozumu {
  if (parseUtcDayMs(input.tarih) === null) {
    return {
      ...YOK_COZUM,
      manuel_inceleme_gerekli_mi: true,
      ucret_policy: "POLITIKA_INCELEMESI"
    };
  }

  const eslesenler = surecler.filter((surec) => {
    if (surec.personel_id !== input.personelId) return false;
    if (isIptalSurec(surec)) return false;
    if (isIsKazasiSureci(surec)) return false;
    if (!isHastalikRaporSureci(surec)) return false;
    return tarihSurecAraligindaMi(surec, input.tarih);
  });

  if (eslesenler.length === 0) {
    return YOK_COZUM;
  }

  if (eslesenler.length > 1) {
    return {
      eslesme_var_mi: true,
      coklu_eslesme_mi: true,
      manuel_inceleme_gerekli_mi: true,
      surec_id: null,
      gun_sirasi: null,
      ilk_iki_gun_mu: false,
      firma_oder_mi: null,
      ucret_policy: "POLITIKA_INCELEMESI"
    };
  }

  return cozumleTekEslesme(eslesenler[0], input.tarih);
}
