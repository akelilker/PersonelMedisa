export type DisiplinLifecycleState =
  | "INCELEME_ADAYI"
  | "IK_INCELEME"
  | "SAVUNMA_BEKLENIYOR"
  | "SAVUNMA_ALINDI"
  | "SAVUNMA_SUNULMADI"
  | "KARAR_BEKLIYOR"
  | "KARAR_VERILDI"
  | "KAPANDI"
  | "ISLEMSIZ_KAPATILDI";

export type DisiplinOlayTuru =
  | "GEC_KALMA"
  | "TAM_GUN_DEVAMSIZLIK"
  | "AYLIK_TEKRARLAYAN_GEC_KALMA";

export type DisiplinNihaiKarar = "NO_ACTION" | "UYARI" | "CEZA";

export type DisiplinVaka = {
  id: number;
  surec_id: number;
  personel_id: number;
  sube_id: number | null;
  tarih: string;
  ay: string;
  olay_turu: DisiplinOlayTuru | string;
  lifecycle_state: DisiplinLifecycleState | string;
  raw_dakika: number | null;
  gunluk_puantaj_id: number | null;
  gunluk_bildirim_id: number | null;
  source_identity: string;
  source_hash: string;
  savunma_talep_tarihi: string | null;
  savunma_deadline_at: string | null;
  savunma_yer: string | null;
  savunma_konu: string | null;
  savunma_isteyen_user_id: number | null;
  savunma_belge_surec_id: number | null;
  savunma_received_at: string | null;
  nihai_karar: DisiplinNihaiKarar | string | null;
  nihai_karar_gerekce: string | null;
  nihai_karar_veren_user_id: number | null;
  nihai_karar_at: string | null;
  created_by: number | null;
  created_at: string;
  updated_at: string;
};

export type DisiplinVakaAudit = {
  id: number;
  disiplin_vaka_id: number;
  action: string;
  from_state: string | null;
  to_state: string | null;
  actor_user_id: number | null;
  detail_json: string | null;
  created_at: string;
};

export type DisiplinVakaGenerateResult = {
  ay: string;
  sube_id: number | null;
  personel_id: number | null;
  created_count: number;
  skipped_count: number;
  items: DisiplinVaka[];
};

export type DisiplinSavunmaTalepPayload = {
  deadline_at: string;
  yer: string;
  konu: string;
};

export type DisiplinSavunmaBelgePayload = {
  belge_surec_id: number;
};

export type DisiplinNihaiKararPayload = {
  nihai_karar: DisiplinNihaiKarar | string;
  gerekce?: string;
};
