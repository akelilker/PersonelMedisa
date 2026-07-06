export type Surec = {
  id: number;
  personel_id: number;
  surec_turu: string;
  alt_tur?: string;
  effective_date?: string;
  baslangic_tarihi?: string;
  bitis_tarihi?: string;
  created_at?: string;
  ucretli_mi?: boolean;
  ilk_iki_gun_firma_oder_mi?: boolean | null;
  aciklama?: string;
  state?: string;
};
