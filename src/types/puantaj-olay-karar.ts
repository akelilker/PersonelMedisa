export type PuantajOlayTuru = "GEC_KALMA" | "ERKEN_CIKIS";

export type PuantajOlayKararDegeri =
  | "BEKLIYOR"
  | "KESINTI_UYGULA"
  | "TOLERANS_UYGULA"
  | "OFFICIAL_PROCESS_REQUIRED";

export type PuantajOlayKarar = {
  id: number;
  personel_id: number;
  tarih: string;
  gunluk_puantaj_id: number | null;
  gunluk_bildirim_id: number | null;
  olay_turu: PuantajOlayTuru | string;
  raw_dakika: number;
  durumu_bildirdi_mi: number | null;
  karar: PuantajOlayKararDegeri | string;
  karar_veren_user_id: number | null;
  karar_at: string | null;
  gerekce: string | null;
  source_hash: string;
  created_at: string;
  updated_at: string;
};

export type PuantajOlayKararUpsertPayload = {
  personel_id: number;
  tarih: string;
  olay_turu: PuantajOlayTuru | string;
  raw_dakika: number;
  karar: PuantajOlayKararDegeri | string;
  gerekce?: string;
  durumu_bildirdi_mi?: number | boolean | null;
  gunluk_puantaj_id?: number;
  gunluk_bildirim_id?: number;
};
