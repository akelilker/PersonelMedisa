export type RevizyonCorrectionTipi =
  | "GIRIS_CIKIS_DUZELTME"
  | "MOLA_DUZELTME"
  | "DEVAMSIZLIK_DUZELTME"
  | "SERBEST_ZAMAN_ETKI_DUZELTME"
  | "KAPANIS_HESAP_REVIZYONU"
  | "BORDRO_ETKI_NOTU";

export type RevizyonCorrectionHataKodu =
  | "CORRECTION_ALREADY_EXISTS"
  | "CORRECTION_NOT_ALLOWED_FOR_STATE"
  | "CORRECTION_TARGET_NOT_FOUND"
  | "CORRECTION_SCOPE_DENIED"
  | "CORRECTION_IMMUTABLE_SNAPSHOT"
  | "CORRECTION_RECOMPUTE_REQUIRED"
  | "CORRECTION_FINANCE_SCOPE_DENIED"
  | "INVALID_CORRECTION_PAYLOAD"
  | "CORRECTION_NOT_FOUND";

export type RevizyonCorrectionEvent = {
  id: number;
  revizyon_talebi_id: number;
  personel_id: number;
  hafta_baslangic: string;
  hafta_bitis: string;
  etkilenen_tarih: string;
  kaynak_tipi: string;
  kaynak_id: number;
  correction_tipi: RevizyonCorrectionTipi;
  onceki_deger: string | number | boolean | null;
  yeni_deger: string | number | boolean | null;
  delta_dakika: number;
  delta_gun: number;
  bordro_etki_var_mi: boolean;
  bordro_etki_tipi: string | null;
  aciklama: string | null;
  olusturan_kullanici_id: number;
  olusturma_zamani: string;
  iptal_edildi_mi: boolean;
  iptal_zamani: string | null;
  iptal_eden_kullanici_id: number | null;
  audit_ref: string;
  snapshot_ref: string | null;
};

export type RevizyonCorrectionListFilters = {
  revizyon_talebi_id?: number | string;
  personel_id?: number | string;
  hafta_baslangic?: string;
  hafta_bitis?: string;
};

export type RevizyonCorrectionIptalPayload = {
  aciklama?: string | null;
};

export const REVIZYON_CORRECTION_TIPLERI = [
  "GIRIS_CIKIS_DUZELTME",
  "MOLA_DUZELTME",
  "DEVAMSIZLIK_DUZELTME",
  "SERBEST_ZAMAN_ETKI_DUZELTME",
  "KAPANIS_HESAP_REVIZYONU",
  "BORDRO_ETKI_NOTU"
] as const satisfies readonly RevizyonCorrectionTipi[];

export const REVIZYON_CORRECTION_HATA_KODLARI = [
  "CORRECTION_ALREADY_EXISTS",
  "CORRECTION_NOT_ALLOWED_FOR_STATE",
  "CORRECTION_TARGET_NOT_FOUND",
  "CORRECTION_SCOPE_DENIED",
  "CORRECTION_IMMUTABLE_SNAPSHOT",
  "CORRECTION_RECOMPUTE_REQUIRED",
  "CORRECTION_FINANCE_SCOPE_DENIED",
  "INVALID_CORRECTION_PAYLOAD",
  "CORRECTION_NOT_FOUND"
] as const satisfies readonly RevizyonCorrectionHataKodu[];
