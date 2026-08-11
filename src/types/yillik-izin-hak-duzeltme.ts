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

export type YillikIzinBakiye = {
  personel_id: number;
  contract_version: string;
  kidem_yil: number;
  yas: number | null;
  yas_istisna_uygulandi: boolean;
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
  duzeltme_adet: number;
  hesap_engeli?: string;
};
