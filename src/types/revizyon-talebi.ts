export type RevizyonTalebiDurumu =
  | "TASLAK"
  | "ONAY_BEKLIYOR"
  | "ONAYLANDI"
  | "REDDEDILDI"
  | "IPTAL";

export type RevizyonTipi =
  | "PUANTAJ_GIRIS_CIKIS_DUZELTME"
  | "MOLA_DUZELTME"
  | "DEVAMSIZLIK_DUZELTME"
  | "SUREC_GEC_GIRIS"
  | "SERBEST_ZAMAN_ETKI_DUZELTME"
  | "KAPANIS_HESAP_REVIZYONU"
  | "BORDRO_ETKI_NOTU";

export type RevizyonHataKodu =
  | "PERIOD_NOT_CLOSED"
  | "PERIOD_LOCKED"
  | "REVISION_ALREADY_EXISTS"
  | "INVALID_STATE_TRANSITION"
  | "UNAUTHORIZED_REVISION_REQUEST"
  | "UNAUTHORIZED_REVISION_APPROVAL"
  | "REVISION_SCOPE_DENIED"
  | "FINANCE_EFFECT_ACCESS_DENIED"
  | "TARGET_NOT_FOUND"
  | "SNAPSHOT_IMMUTABLE"
  | "INVALID_BODY"
  | "NOT_FOUND";

export type RevizyonJsonDeger =
  | string
  | number
  | boolean
  | null
  | RevizyonJsonDeger[]
  | { [key: string]: RevizyonJsonDeger };

export type RevizyonTalebi = {
  id: number;
  personel_id: number;
  hafta_baslangic: string;
  hafta_bitis: string;
  etkilenen_tarih: string;
  kaynak_tipi: string;
  kaynak_id: number;
  revizyon_tipi: RevizyonTipi;
  onceki_deger: RevizyonJsonDeger;
  talep_edilen_deger: RevizyonJsonDeger;
  gerekce: string;
  talep_eden_kullanici_id: number;
  talep_zamani: string;
  durum: RevizyonTalebiDurumu;
  karar_veren_kullanici_id?: number | null;
  karar_zamani?: string | null;
  karar_notu?: string | null;
  bordro_etki_var_mi: boolean;
  bordro_etki_notu?: string | null;
  correction_event_id?: number | null;
};

export type PostRevizyonTalebiPayload = {
  personel_id: number | string;
  hafta_baslangic: string;
  hafta_bitis: string;
  etkilenen_tarih: string;
  kaynak_tipi: string;
  kaynak_id: number | string;
  revizyon_tipi: RevizyonTipi;
  onceki_deger: RevizyonJsonDeger;
  talep_edilen_deger: RevizyonJsonDeger;
  gerekce: string;
  bordro_etki_var_mi?: boolean;
  bordro_etki_notu?: string | null;
};

export type RevizyonTalebiKararPayload = {
  karar_notu?: string | null;
};

export type RevizyonTalebiListFilters = {
  personel_id?: number | string;
  durum?: RevizyonTalebiDurumu;
  hafta_baslangic?: string;
  hafta_bitis?: string;
};

export const REVIZYON_TALEBI_DURUMLARI = [
  "TASLAK",
  "ONAY_BEKLIYOR",
  "ONAYLANDI",
  "REDDEDILDI",
  "IPTAL"
] as const satisfies readonly RevizyonTalebiDurumu[];

export const REVIZYON_TIPLERI = [
  "PUANTAJ_GIRIS_CIKIS_DUZELTME",
  "MOLA_DUZELTME",
  "DEVAMSIZLIK_DUZELTME",
  "SUREC_GEC_GIRIS",
  "SERBEST_ZAMAN_ETKI_DUZELTME",
  "KAPANIS_HESAP_REVIZYONU",
  "BORDRO_ETKI_NOTU"
] as const satisfies readonly RevizyonTipi[];

export const REVIZYON_HATA_KODLARI = [
  "PERIOD_NOT_CLOSED",
  "PERIOD_LOCKED",
  "REVISION_ALREADY_EXISTS",
  "INVALID_STATE_TRANSITION",
  "UNAUTHORIZED_REVISION_REQUEST",
  "UNAUTHORIZED_REVISION_APPROVAL",
  "REVISION_SCOPE_DENIED",
  "FINANCE_EFFECT_ACCESS_DENIED",
  "TARGET_NOT_FOUND",
  "SNAPSHOT_IMMUTABLE",
  "INVALID_BODY",
  "NOT_FOUND"
] as const satisfies readonly RevizyonHataKodu[];
