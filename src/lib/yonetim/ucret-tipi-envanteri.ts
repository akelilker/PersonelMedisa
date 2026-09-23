import {
  displayUcretTipiModeliLabel,
  resolveUcretTipiModeli,
  type UcretTipiModeli
} from "../display/ucret-tipi-display";
import type { Personel } from "../../types/personel";

/**
 * Ücret Tipi Envanteri — salt okunur özet.
 *
 * Tek kaynak: `personeller.ucret_tipi_id` (canonical read API'nin döndürdüğü alan).
 * Personel Kartı'ndaki Mavi/Beyaz statüsünden ücret tipi TÜRETİLMEZ; statü yalnız
 * kişi bazında bilgi olarak gösterilir.
 */

export type UcretTipiEnvanteriDurum = "TANIMLI" | "EKSIK" | "GECERSIZ";

export const UCRET_TIPI_ENVANTERI_BUCKET_KEYS = [
  "SAATLIK",
  "GUNLUK",
  "MAKTU_AYLIK",
  "EKSIK",
  "GECERSIZ"
] as const;

export type UcretTipiEnvanteriBucketKey = (typeof UCRET_TIPI_ENVANTERI_BUCKET_KEYS)[number];

/** Görünen etiketler; ücret tipi etiketleri canonical owner'dan gelir. */
export const UCRET_TIPI_ENVANTERI_BUCKET_LABELS: Record<UcretTipiEnvanteriBucketKey, string> = {
  SAATLIK: displayUcretTipiModeliLabel("SAATLIK"),
  GUNLUK: displayUcretTipiModeliLabel("GUNLUK"),
  MAKTU_AYLIK: displayUcretTipiModeliLabel("MAKTU_AYLIK"),
  EKSIK: "Eksik",
  GECERSIZ: "Geçersiz"
};

export const UCRET_TIPI_ENVANTERI_DURUM_LABELS: Record<UcretTipiEnvanteriDurum, string> = {
  TANIMLI: "Tanımlı",
  EKSIK: "Eksik",
  GECERSIZ: "Geçersiz"
};

export type UcretTipiEnvanteriSatiri = {
  personelId: number;
  adSoyad: string;
  /** personeller.personel_tipi_id → personel_tipleri.ad (ör. Mavi Yaka / Beyaz Yaka). */
  statu: string | null;
  /** Canonical ücret modeli; eksik/geçersizde null. */
  ucretTipiModeli: UcretTipiModeli | null;
  /** Görünen etiket; geçersizde ham id `#<id>` olarak gösterilir. */
  ucretTipiEtiketi: string;
  durum: UcretTipiEnvanteriDurum;
};

export type UcretTipiEnvanteriSonuc = {
  toplam: number;
  sayaclar: Record<UcretTipiEnvanteriBucketKey, number>;
  satirlar: UcretTipiEnvanteriSatiri[];
};

function emptySayaclar(): Record<UcretTipiEnvanteriBucketKey, number> {
  return { SAATLIK: 0, GUNLUK: 0, MAKTU_AYLIK: 0, EKSIK: 0, GECERSIZ: 0 };
}

function toAdSoyad(personel: Personel): string {
  const name = [personel.ad, personel.soyad].filter(Boolean).join(" ").trim();
  return name || `Personel #${personel.id}`;
}

function toOptionalId(value: number | undefined): number | null {
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

/**
 * Aktif personel listesinden ücret tipi dağılımını üretir.
 * Hiçbir alan yazılmaz; yalnız okunan `ucret_tipi_id` sınıflandırılır.
 */
export function buildUcretTipiEnvanteri(personeller: readonly Personel[]): UcretTipiEnvanteriSonuc {
  const sayaclar = emptySayaclar();
  const satirlar = personeller.map((personel): UcretTipiEnvanteriSatiri => {
    const ucretTipiId = toOptionalId(personel.ucret_tipi_id);
    const model = resolveUcretTipiModeli(ucretTipiId);
    const durum: UcretTipiEnvanteriDurum =
      ucretTipiId === null ? "EKSIK" : model === null ? "GECERSIZ" : "TANIMLI";

    if (model !== null) {
      sayaclar[model] += 1;
    } else if (ucretTipiId === null) {
      sayaclar.EKSIK += 1;
    } else {
      sayaclar.GECERSIZ += 1;
    }

    return {
      personelId: personel.id,
      adSoyad: toAdSoyad(personel),
      statu: personel.personel_tipi_adi?.trim() || null,
      ucretTipiModeli: model,
      ucretTipiEtiketi:
        model !== null ? displayUcretTipiModeliLabel(model) : ucretTipiId === null ? "-" : `#${ucretTipiId}`,
      durum
    };
  });

  return { toplam: personeller.length, sayaclar, satirlar };
}
