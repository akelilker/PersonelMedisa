export const BELGE_TURU_KEYS = ["KIMLIK", "ADRES_BEYANI", "IS_GIRIS_EVRAKLARI", "BANKA_IBAN"] as const;

export type BelgeTuru = (typeof BELGE_TURU_KEYS)[number];

export type BelgeDurum = "VAR" | "YOK";

export type BelgeDurumuItem = {
  belge_turu: BelgeTuru;
  durum: BelgeDurum;
};

export const BELGE_TURU_LABELS: Record<BelgeTuru, string> = {
  KIMLIK: "Kimlik",
  ADRES_BEYANI: "İkametgâh / adres beyanı",
  IS_GIRIS_EVRAKLARI: "İşe giriş evrakları",
  BANKA_IBAN: "Banka / IBAN bilgisi"
};

export function createDefaultBelgeDurumDraft(): Record<BelgeTuru, BelgeDurum> {
  return {
    KIMLIK: "YOK",
    ADRES_BEYANI: "YOK",
    IS_GIRIS_EVRAKLARI: "YOK",
    BANKA_IBAN: "YOK"
  };
}
