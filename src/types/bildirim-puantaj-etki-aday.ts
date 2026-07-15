export type BildirimPuantajEtkiAdayState =
  | "HAZIR"
  | "INCELEME_GEREKLI"
  | "UYGULANDI"
  | "YOK_SAYILDI";

export type BildirimPuantajEtkiUygulamaModu = "OTOMATIK" | "MANUEL";

export type BildirimPuantajEtkiManualKararTuru =
  | "DEVAMSIZLIK_GUN"
  | "GEC_KALMA_DAKIKA"
  | "ERKEN_CIKIS_DAKIKA"
  | "GOREVDE_CALISILMIS_GUN";

export type BildirimPuantajEtkiAdayListItem = {
  id: number;
  genel_yonetici_bildirim_onayi_id: number;
  gunluk_bildirim_id: number;
  personel_id: number;
  sube_id: number;
  birim_amiri_user_id: number;
  ay: string;
  tarih: string;
  bildirim_turu: string;
  etki_turu: string;
  etki_miktari: number | null;
  etki_birimi: string | null;
  state: BildirimPuantajEtkiAdayState;
  conflict_code: string | null;
  source_priority: string;
  created_at: string;
  karar_veren_user_id: number | null;
  karar_zamani: string | null;
  uygulanan_puantaj_id: number | null;
  uygulama_modu: BildirimPuantajEtkiUygulamaModu;
  manuel_karar_turu: BildirimPuantajEtkiManualKararTuru | null;
  manuel_karar_miktari: number | null;
};

export type BildirimPuantajEtkiConflictDetail = Record<string, unknown> | null;

export type BildirimPuantajEtkiAdayDetail = BildirimPuantajEtkiAdayListItem & {
  aylik_bildirim_onayi_id: number;
  bildirim_alt_tur: string | null;
  bildirim_dakika: number | null;
  bildirim_aciklama: string | null;
  bildirim_created_at: string;
  bildirim_updated_at: string;
  conflict_detail: BildirimPuantajEtkiConflictDetail;
  resmi_surec_id: number | null;
  resmi_surec_turu: string | null;
  resmi_surec_alt_tur: string | null;
  ucretli_mi_snapshot: boolean | null;
  mevcut_puantaj_id: number | null;
  source_snapshot: Record<string, unknown> | null;
  source_hash: string | null;
  projection_version: string | null;
  updated_at: string;
  karar_gerekcesi: string | null;
  onceki_puantaj_snapshot: Record<string, unknown> | null;
  sonraki_puantaj_snapshot: Record<string, unknown> | null;
  uygulama_hash: string | null;
};

export type BildirimPuantajEtkiAdayCounts = {
  toplam: number;
  hazir: number;
  inceleme_gerekli: number;
  uygulandi: number;
  yok_sayildi: number;
};

export type BildirimPuantajEtkiAdayOzet = {
  context: {
    genel_yonetici_bildirim_onayi_id: number;
    ay: string | null;
    ay_baslangic: string | null;
    ay_bitis: string | null;
    sube_id: number | null;
    birim_amiri_user_id: number | null;
    aylik_bildirim_onayi_id: number | null;
    onaylandi_at: string | null;
  };
  genel_yonetici_bildirim_onayi: {
    id: number;
    state: string;
    onaylandi_at: string | null;
  } | null;
  kaynak_bildirim_sayisi: number;
  aday_sayilari: BildirimPuantajEtkiAdayCounts;
  muhur_durumu: "MUHURLENDI" | "ACIK";
  hazirlanabilir_mi: boolean;
  blok_nedeni: string | null;
};

export type BildirimPuantajEtkiAdayDismissPayload = {
  expected_state: "HAZIR" | "INCELEME_GEREKLI";
  gerekce: string;
};

export type BildirimPuantajEtkiAdayDismissResult = {
  id: number;
  state: BildirimPuantajEtkiAdayState;
  karar_veren_user_id: number | null;
  karar_zamani: string | null;
  karar_gerekcesi: string | null;
  uygulanan_puantaj_id: number | null;
  idempotent: boolean;
};

export type BildirimPuantajEtkiAdayApplyPayload = {
  expected_state: "HAZIR";
};

export type BildirimPuantajEtkiAdayApplyResult = {
  id: number;
  state: BildirimPuantajEtkiAdayState;
  karar_veren_user_id: number | null;
  karar_zamani: string | null;
  uygulanan_puantaj_id: number | null;
  onceki_puantaj_snapshot: Record<string, unknown> | null;
  sonraki_puantaj_snapshot: Record<string, unknown> | null;
  uygulama_hash: string | null;
  uygulama_modu: BildirimPuantajEtkiUygulamaModu;
  manuel_karar_turu: BildirimPuantajEtkiManualKararTuru | null;
  manuel_karar_miktari: number | null;
  idempotent: boolean;
};

export type BildirimPuantajEtkiAdayManualApplyPayload = {
  expected_state: "INCELEME_GEREKLI";
  karar_etki_turu: BildirimPuantajEtkiManualKararTuru;
  etki_miktari: number | null;
  gerekce: string;
};

export type BildirimPuantajEtkiAdayManualApplyResult = BildirimPuantajEtkiAdayApplyResult & {
  karar_gerekcesi: string | null;
};
