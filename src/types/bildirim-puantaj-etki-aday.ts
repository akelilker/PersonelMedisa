export type BildirimPuantajEtkiAdayState =
  | "HAZIR"
  | "INCELEME_GEREKLI"
  | "UYGULANDI"
  | "YOK_SAYILDI";

export type BildirimPuantajEtkiUygulamaModu = "OTOMATIK" | "MANUEL" | "CAKISMA_COZUM";

export type BildirimPuantajEtkiConflictClass =
  | "AYNI_ADAY_PUANTAJI"
  | "BASKA_ADAY_KAYNAGI"
  | "MANUEL_KAYNAK"
  | "RESMI_SUREC_DAYANAK"
  | "MUHURLU_PUANTAJ"
  | "AMIR_KONTROL_EDILMIS"
  | "LEGACY_BELIRSIZ";

export type BildirimPuantajEtkiConflictKararTuru =
  | "MEVCUT_PUANTAJI_KORU"
  | "ADAY_ETKISIYLE_REVIZE_ET";

export type BildirimPuantajEtkiPuantajOzet = {
  id: number | null;
  personel_id: number;
  tarih: string;
  state: string;
  gun_tipi: string | null;
  hareket_durumu: string | null;
  dayanak: string | null;
  hesap_etkisi: string | null;
  durumu_bildirdi_mi: boolean | null;
  durum_bildirim_aciklamasi: string | null;
  beklenen_giris_saati: string | null;
  beklenen_cikis_saati: string | null;
  giris_saati: string | null;
  cikis_saati: string | null;
  gec_kalma_dakika: number | null;
  erken_cikis_dakika: number | null;
  gercek_mola_dakika: number | null;
  hesaplanan_mola_dakika: number | null;
  net_calisma_suresi_dakika: number | null;
  gunluk_brut_sure_dakika: number | null;
  hafta_tatili_hak_kazandi_mi: boolean | null;
  kontrol_durumu: string;
  kaynak: string | null;
  aciklama: string | null;
  muhur_id: number | null;
  updated_at: string | null;
};

export type BildirimPuantajEtkiCakismaCozumOzet = {
  id: number;
  aday_id: number;
  puantaj_id: number | null;
  conflict_class: BildirimPuantajEtkiConflictClass | string;
  karar_turu: BildirimPuantajEtkiConflictKararTuru | string;
  gerekce: string;
  request_hash: string;
  sonuc_hash: string;
  karar_veren_user_id: number;
  karar_zamani: string;
};

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
  mevcut_puantaj: BildirimPuantajEtkiPuantajOzet | null;
  current_puantaj_hash: string | null;
  conflict_class: BildirimPuantajEtkiConflictClass | string | null;
  conflict_default_karar: BildirimPuantajEtkiConflictKararTuru | string | null;
  conflict_revise_allowed: boolean;
  conflict_risk: string | null;
  revize_onizleme: Record<string, unknown> | null;
  cakisma_cozum: BildirimPuantajEtkiCakismaCozumOzet | null;
};

export type BildirimPuantajEtkiAdayConflictResolvePayload = {
  expected_state: "HAZIR" | "INCELEME_GEREKLI";
  karar_turu: BildirimPuantajEtkiConflictKararTuru;
  gerekce: string;
  expected_puantaj_id: number;
  expected_puantaj_hash: string;
};

export type BildirimPuantajEtkiAdayConflictResolveResult = {
  aday: BildirimPuantajEtkiAdayDetail;
  puantaj: BildirimPuantajEtkiPuantajOzet | null;
  conflict_class: BildirimPuantajEtkiConflictClass | string | null;
  karar_turu: BildirimPuantajEtkiConflictKararTuru | string | null;
  cakisma_cozum: BildirimPuantajEtkiCakismaCozumOzet | null;
  onceki_ozet: Record<string, unknown> | null;
  sonraki_ozet: Record<string, unknown> | null;
  idempotent: boolean;
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
