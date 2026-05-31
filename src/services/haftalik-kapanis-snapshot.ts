import type { HaftalikKapanisSnapshotSatir } from "../types/haftalik-kapanis";
import type { ComplianceUyari } from "../types/puantaj";
import type {
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajHesapEtkisi
} from "../types/puantaj";
import {
  birlestirMazeretsizDevamsizlikAdayUyariari,
  birlestirOnsekizYasAltiFazlaCalismaUyari,
  birlestirUbgtFazlaMesaiCakismaUyari,
  hesaplaHaftalikCalismaOzeti,
  type HaftalikGunNetCalisma,
  type MazeretsizDevamsizlikKayitGirdi,
  type UbgtFazlaMesaiCakismaGunGirdi
} from "./puantaj-hesap-motoru";

export const HAFTALIK_KAPANIS_KAYNAK_VERSIYON_A2 = "A2_MOTOR_V1";

const HAFTA_GUN_SAYISI = 7;

export type HaftalikKapanisPuantajGun = {
  tarih: string;
  gun_tipi?: PuantajGunTipi;
  hareket_durumu?: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  hesap_etkisi?: PuantajHesapEtkisi;
  net_calisma_suresi_dakika?: number;
  hafta_tatili_hak_kazandi_mi?: boolean;
  compliance_uyarilari?: ComplianceUyari[];
};

export type HaftalikKapanisSnapshotPersonel = {
  id: number;
  departman_id?: number;
  dogum_tarihi?: string | null;
};

export type BuildHaftalikKapanisSnapshotParams = {
  kapanis_id: number;
  hafta_baslangic: string;
  hafta_bitis: string;
  departman_id?: number;
  personeller: readonly HaftalikKapanisSnapshotPersonel[];
  resolvePuantaj: (
    personelId: number,
    tarih: string
  ) => HaftalikKapanisPuantajGun | null | undefined;
};

export type HaftalikKapanisSnapshotSonuc = {
  snapshot_satirlari: HaftalikKapanisSnapshotSatir[];
  snapshot_satir_sayisi: number;
  personel_sayisi: number;
};

function parseGGAATarih(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) {
    return null;
  }

  const y = Number.parseInt(match[1], 10);
  const m = Number.parseInt(match[2], 10);
  const day = Number.parseInt(match[3], 10);
  if (m < 1 || m > 12 || day < 1 || day > 31) {
    return null;
  }

  const d = new Date(y, m - 1, day);
  if (Number.isNaN(d.getTime())) {
    return null;
  }
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

/** Pazartesi başlangıcından itibaren 7 takvim günü (hafta_bitis A2’de doğrulanmaz; hafta_baslangic esas). */
export function listHaftaTarihleri(haftaBaslangic: string): string[] {
  const m1 = /^(\d{4})-(\d{2})-(\d{2})$/.exec(haftaBaslangic.trim());
  if (!m1) {
    return [];
  }

  const d0 = new Date(Number(m1[1]), Number(m1[2]) - 1, Number(m1[3]));
  const out: string[] = [];
  for (let i = 0; i < HAFTA_GUN_SAYISI; i++) {
    const cur = new Date(d0.getFullYear(), d0.getMonth(), d0.getDate() + i);
    out.push(formatGGAATarih(cur));
  }
  return out;
}

/**
 * ISO 8601 hafta numarası (Pazartesi başlangıçlı hafta).
 * Geçersiz tarih → null.
 */
export function hesaplaIsoHaftaNo(tarih: string): { yil: number; hafta_no: number } | null {
  const date = parseGGAATarih(tarih);
  if (!date) {
    return null;
  }

  const utc = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
  const day = utc.getUTCDay() || 7;
  utc.setUTCDate(utc.getUTCDate() + 4 - day);
  const yil = utc.getUTCFullYear();
  const yilBasi = new Date(Date.UTC(yil, 0, 1));
  const hafta_no = Math.ceil(((utc.getTime() - yilBasi.getTime()) / 86400000 + 1) / 7);
  return { yil, hafta_no };
}

function guvenliNetDakika(value: number | undefined): number {
  if (value === undefined || value === null || Number.isNaN(value)) {
    return 0;
  }
  return value < 0 ? 0 : value;
}

function isKritikComplianceLevel(level: string | undefined): boolean {
  if (!level) {
    return false;
  }
  const normalized = level.trim().toUpperCase();
  return normalized === "KRITIK" || normalized === "CRITICAL";
}

function kritikUyariVarMi(uyarilar: readonly ComplianceUyari[]): boolean {
  return uyarilar.some((uyari) => isKritikComplianceLevel(uyari.level));
}

function toMazeretsizGirdi(gun: HaftalikKapanisPuantajGun): MazeretsizDevamsizlikKayitGirdi {
  return {
    hareket_durumu: gun.hareket_durumu,
    dayanak: gun.dayanak,
    hafta_tatili_hak_kazandi_mi: gun.hafta_tatili_hak_kazandi_mi
  };
}

function toUbgtGunGirdi(gun: HaftalikKapanisPuantajGun): UbgtFazlaMesaiCakismaGunGirdi {
  return {
    gun_tipi: gun.gun_tipi,
    hesap_etkisi: gun.hesap_etkisi,
    net_calisma_suresi_dakika: guvenliNetDakika(gun.net_calisma_suresi_dakika)
  };
}

function toHaftalikNetSatirlari(gunler: readonly HaftalikKapanisPuantajGun[]): HaftalikGunNetCalisma[] {
  return gunler.map((gun) => ({
    net_calisma_suresi_dakika: guvenliNetDakika(gun.net_calisma_suresi_dakika)
  }));
}

function uretHaftalikSnapshotCompliance(
  gunler: readonly HaftalikKapanisPuantajGun[],
  tamHaftaVerisi: boolean,
  fazlaCalismaDakika: number,
  personel: HaftalikKapanisSnapshotPersonel,
  referansTarih: string
): ComplianceUyari[] {
  let compliance: ComplianceUyari[] = [];

  for (const gun of gunler) {
    const mevcut = gun.compliance_uyarilari ?? [];
    compliance = [...compliance, ...mevcut];
    compliance = birlestirMazeretsizDevamsizlikAdayUyariari(compliance, toMazeretsizGirdi(gun));
  }

  if (!tamHaftaVerisi) {
    return compliance;
  }

  const haftalikNet = toHaftalikNetSatirlari(gunler);
  for (const gun of gunler) {
    compliance = birlestirUbgtFazlaMesaiCakismaUyari(
      compliance,
      toUbgtGunGirdi(gun),
      haftalikNet,
      true
    );
  }

  compliance = birlestirOnsekizYasAltiFazlaCalismaUyari(compliance, {
    dogum_tarihi: personel.dogum_tarihi ?? undefined,
    referans_tarih: referansTarih,
    fazla_calisma_dakika: fazlaCalismaDakika,
    tam_hafta_verisi: true
  });

  return compliance;
}

function uretSnapshotNotlar(tamHaftaVerisi: boolean, kaynakGunSayisi: number): string[] | undefined {
  if (tamHaftaVerisi) {
    return undefined;
  }

  return [
    `Eksik haftalık puantaj günü (${kaynakGunSayisi}/${HAFTA_GUN_SAYISI}); UBGT ve 18 yaş altı haftalık uyarıları üretilmedi.`
  ];
}

function buildPersonelSnapshotSatir(
  params: BuildHaftalikKapanisSnapshotParams,
  personel: HaftalikKapanisSnapshotPersonel,
  index: number
): HaftalikKapanisSnapshotSatir {
  const { kapanis_id, hafta_baslangic, hafta_bitis, departman_id, resolvePuantaj } = params;
  const tarihler = listHaftaTarihleri(hafta_baslangic);
  const gunler: HaftalikKapanisPuantajGun[] = [];

  for (const tarih of tarihler) {
    const kayit = resolvePuantaj(personel.id, tarih);
    if (kayit != null) {
      gunler.push({ ...kayit, tarih: kayit.tarih ?? tarih });
    }
  }

  const kaynak_gun_sayisi = gunler.length;
  const tam_hafta_verisi = kaynak_gun_sayisi === HAFTA_GUN_SAYISI;
  const ozet = hesaplaHaftalikCalismaOzeti(toHaftalikNetSatirlari(gunler));
  const referansTarih = gunler[gunler.length - 1]?.tarih ?? hafta_bitis;
  const compliance_uyarilari = uretHaftalikSnapshotCompliance(
    gunler,
    tam_hafta_verisi,
    ozet.fazla_calisma_dakika,
    personel,
    referansTarih
  );
  const iso = hesaplaIsoHaftaNo(hafta_baslangic);
  const yilFromBaslangic = /^(\d{4})-/.exec(hafta_baslangic.trim());
  const yil = iso?.yil ?? (yilFromBaslangic ? Number.parseInt(yilFromBaslangic[1], 10) : undefined);

  return {
    snapshot_id: kapanis_id * 1000 + index + 1,
    kapanis_id,
    personel_id: personel.id,
    departman_id: departman_id ?? personel.departman_id,
    hafta_baslangic,
    hafta_bitis,
    yil,
    hafta_no: iso?.hafta_no,
    state: "KAPANDI",
    kaynak_versiyon: HAFTALIK_KAPANIS_KAYNAK_VERSIYON_A2,
    toplam_net_dakika: ozet.toplam_net_dakika,
    normal_calisma_dakika: ozet.normal_calisma_dakika,
    fazla_calisma_dakika: ozet.fazla_calisma_dakika,
    fazla_surelerle_calisma_dakika: 0,
    tam_hafta_verisi,
    compliance_uyarilari,
    compliance_uyari_sayisi: compliance_uyarilari.length,
    kritik_uyari_var_mi: kritikUyariVarMi(compliance_uyarilari),
    hesaplama_zamani: new Date().toISOString(),
    kaynak_gun_sayisi,
    notlar: uretSnapshotNotlar(tam_hafta_verisi, kaynak_gun_sayisi)
  };
}

export function buildHaftalikKapanisSnapshot(
  params: BuildHaftalikKapanisSnapshotParams
): HaftalikKapanisSnapshotSonuc {
  const snapshot_satirlari = params.personeller.map((personel, index) =>
    buildPersonelSnapshotSatir(params, personel, index)
  );

  return {
    snapshot_satirlari,
    snapshot_satir_sayisi: snapshot_satirlari.length,
    personel_sayisi: params.personeller.length
  };
}
