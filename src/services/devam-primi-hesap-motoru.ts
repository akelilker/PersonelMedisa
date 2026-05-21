import type { GunlukPuantaj } from "../types/puantaj";

// ---------------------------------------------------------------------------
// Devam primi eligibility — ultra dar pilot (tutar dışı karar motoru)
// Owner: yalnızca bu dosya. Bordro / finans / SGK değildir.
// ---------------------------------------------------------------------------

export type DevamPrimiEligibilityGirdi = {
  personel_id: number;
  yil: number;
  ay: number;
  prim_kurali_id?: number;
  gunluk_kayitlar: GunlukPuantaj[];
};

export type DevamPrimiEligibilitySonuc = {
  personel_id: number;
  donem: string;
  prim_kurali_id?: number;
  hak_kazandi_mi: boolean;
  kesildi_mi: boolean;
  kesinti_nedeni?: string;
  manuel_inceleme_gerekli_mi: boolean;
  uygulanan_kural?: string;
  aciklama?: string;
};

const KESINTI_NEDENI_HASTALIK = "1_gun_hastalik_raporu";
const UYGULANAN_KURAL_HASTALIK = "aylik_tam_gun_raporlu_hastalik_kesinti";

function parsePuantajYearMonth(tarih: string | undefined) {
  if (typeof tarih !== "string") {
    return null;
  }

  const match = tarih.trim().match(/^(\d{4})-(\d{2})-/);
  if (!match) {
    return null;
  }

  const yil = Number.parseInt(match[1], 10);
  const ay = Number.parseInt(match[2], 10);
  if (!Number.isInteger(yil) || !Number.isInteger(ay) || ay < 1 || ay > 12) {
    return null;
  }

  return { yil, ay };
}

function filterKayitlarByDonem(
  kayitlar: GunlukPuantaj[],
  yil: number,
  ay: number
): GunlukPuantaj[] {
  return kayitlar.filter((kayit) => {
    const parsed = parsePuantajYearMonth(kayit.tarih);
    return parsed != null && parsed.yil === yil && parsed.ay === ay;
  });
}

function isTamGunHastalikRaporu(kayit: GunlukPuantaj): boolean {
  return kayit.hareket_durumu === "Gelmedi" && kayit.dayanak === "Raporlu_Hastalik";
}

function hasIsKazasiKaydi(kayitlar: GunlukPuantaj[]): boolean {
  return kayitlar.some((kayit) => kayit.dayanak === "Raporlu_Is_Kazasi");
}

export function hesaplaDevamPrimiEligibility(
  girdi: DevamPrimiEligibilityGirdi
): DevamPrimiEligibilitySonuc {
  const donem = `${girdi.yil}-${String(girdi.ay).padStart(2, "0")}`;
  const donemKayitlari = filterKayitlarByDonem(girdi.gunluk_kayitlar, girdi.yil, girdi.ay);

  const primKuraliEksik = girdi.prim_kurali_id == null;
  const isKazasiVar = hasIsKazasiKaydi(donemKayitlari);
  const hastalikTamGunVar = donemKayitlari.some(isTamGunHastalikRaporu);

  const manuel_inceleme_gerekli_mi = primKuraliEksik || isKazasiVar;
  const kesildi_mi = hastalikTamGunVar;
  const hak_kazandi_mi = !kesildi_mi;

  const sonuc: DevamPrimiEligibilitySonuc = {
    personel_id: girdi.personel_id,
    donem,
    prim_kurali_id: girdi.prim_kurali_id,
    hak_kazandi_mi,
    kesildi_mi,
    manuel_inceleme_gerekli_mi
  };

  if (kesildi_mi) {
    sonuc.kesinti_nedeni = KESINTI_NEDENI_HASTALIK;
    sonuc.uygulanan_kural = UYGULANAN_KURAL_HASTALIK;
    sonuc.aciklama =
      "Aylik donemde en az bir tam gun Raporlu_Hastalik + Gelmedi kaydi var; devam primi eligibility kesildi.";
  } else if (primKuraliEksik) {
    sonuc.aciklama = "prim_kurali_id tanimli degil; otomatik devam primi karari uretilmedi.";
  } else if (isKazasiVar) {
    sonuc.aciklama =
      "Donemde Raporlu_Is_Kazasi kaydi var; otomatik kesinti uygulanmadi, manuel inceleme gerekir.";
  } else {
    sonuc.aciklama = "Otomatik kesinti kosulu olusmadi; devam primi eligibility hak kazandi.";
  }

  return sonuc;
}
