export type YillikIzinHakDuzeltmeKategori = "DEVIR" | "EK_HAK" | "DUZELTME" | "TERS_KAYIT";

export type YillikIzinHakDuzeltmeKaydi = {
  id: number;
  personel_id: number;
  gun_delta: number;
  kategori: YillikIzinHakDuzeltmeKategori;
  aciklama: string;
  effective_date: string;
  reverses_id: number | null;
  is_reversed: boolean;
  created_by: number | null;
  created_by_display: string | null;
  created_at: string;
};

export type CreateYillikIzinHakDuzeltmePayload = {
  gun_delta: number;
  kategori: Exclude<YillikIzinHakDuzeltmeKategori, "TERS_KAYIT">;
  aciklama: string;
  effective_date: string;
};

export type ReverseYillikIzinHakDuzeltmePayload = {
  aciklama: string;
};

/**
 * Server balance contract (S2C).
 * yasal_hak_gun === birikmis_yasal_hak_gun (cumulative statutory as-of reference).
 * mevcut_yillik_hak_gun = current service-year annual band only.
 */
export type YillikIzinBakiye = {
  personel_id: number;
  contract_version: string;
  referans_tarih?: string | null;
  annual_band_semantic?: string;
  balance_legal_semantic?: string;
  kidem_yil: number;
  yas: number | null;
  yas_istisna_uygulandi: boolean;
  /** Current service-year band (14/20/26). */
  mevcut_yillik_hak_gun: number;
  /** Cumulative statutory accrual as-of reference date. */
  birikmis_yasal_hak_gun: number;
  /**
   * Compatibility alias of birikmis_yasal_hak_gun (NOT current-year band).
   * Prefer birikmis_yasal_hak_gun in new UI.
   */
  yasal_hak_gun: number;
  manuel_duzeltme_gun: number;
  efektif_hak_gun: number;
  kullanilan_gun: number | null;
  ham_kalan_gun: number | null;
  kalan_gun: number | null;
  takvim_dogrulandi_mi: boolean;
  eksik_takvim_tarihleri: string[];
  sayilan_normal_gun: number;
  haric_tutulan_hafta_tatili_gun: number;
  haric_tutulan_ubgt_gun: number;
  /** Effective adjustment count as-of reference date. */
  duzeltme_adet: number;
  hesap_engeli?: string;
};
