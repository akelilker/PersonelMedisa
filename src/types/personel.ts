export type PersonelAktifDurum = "AKTIF" | "PASIF";

export type Personel = {
  id: number;
  tc_kimlik_no: string;
  ad: string;
  soyad: string;
  aktif_durum: PersonelAktifDurum;
  sube_id?: number;
  telefon?: string;
  dogum_tarihi?: string;
  sicil_no?: string;
  dogum_yeri?: string;
  kan_grubu?: string;
  ise_giris_tarihi?: string;
  acil_durum_kisi?: string;
  acil_durum_telefon?: string;
  departman_id?: number;
  bolum_id?: number | null;
  bolum_adi?: string | null;
  birim_id?: number | null;
  birim_adi?: string | null;
  gorev_id?: number;
  pozisyon_id?: number | null;
  pozisyon_adi?: string | null;
  personel_tipi_id?: number;
  bagli_amir_id?: number;
  sgk_isveren_id?: number | null;
  sgk_isveren_adi?: string | null;
  calisma_lokasyonu_id?: number | null;
  calisma_lokasyonu_adi?: string | null;
  sube_adi?: string;
  departman_adi?: string;
  gorev_adi?: string;
  personel_tipi_adi?: string;
  bagli_amir_adi?: string;
  hizmet_suresi?: string;
  toplam_izin_hakki?: number;
  kullanilan_izin?: number;
  kalan_izin?: number;
  sgk_donem?: string;
  sgk_prim_gun?: number;
  sgk_eksik_gun_sayisi?: number;
  sgk_eksik_gun_nedeni_kodu?: string | null;
  sgk_ayin_takvim_gun_sayisi?: number;
  sgk_hesaplama_modu?: string;
  pasiflik_durumu_etiketi?: string | null;
  ucret_tipi_id?: number;
  ucret_tipi_adi?: string;
  /** Canonical net maaş (kullanıcı girdisi). */
  net_maas_tutari?: number;
  /** Legacy read-compat; yeni kayıtlarda net ile aynı değer olabilir. */
  maas_tutari?: number;
  /** Sistem hesaplı brüt; bu sprintte değer üretilmez. */
  brut_maas_tutari?: number | null;
  brut_hesaplama_modeli?: string | null;
  brut_hesaplama_donemi?: string | null;
  model_versiyonu?: string | null;
  prim_kurali_id?: number;
  prim_kurali_adi?: string;
  /** Phase C archive markers (PASIF + arsiv.view). */
  arsiv_modu?: boolean;
  read_only_archive?: boolean;
  policy_note?: string;
  legal_hold_active?: boolean;
  retention_summary?: {
    category?: string;
    trigger_type?: string;
    trigger_date?: string;
    retention_until?: string;
    earliest_destruction_review_date?: string;
    policy_note?: string;
    code?: string;
  } | null;
};
