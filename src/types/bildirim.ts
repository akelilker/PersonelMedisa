export type Bildirim = {
  id: number;
  tarih?: string;
  departman_id?: number;
  personel_id?: number;
  bildirim_turu: string;
  aciklama?: string;
  state?: string;
  okundu_mi?: boolean;
  created_by?: number | null;
  updated_by?: number | null;
  submitted_at?: string | null;
  correction_requested_by?: number | null;
  correction_reason?: string | null;
  haftalik_mutabakat_id?: number | null;
  personel_ad_soyad?: string | null;
  sicil_no?: string | null;
  gorev_adi?: string | null;
  departman_adi?: string | null;
  sube_adi?: string | null;
  amir_user_id?: number | null;
  alt_tur?: string | null;
  baslangic_saati?: string | null;
  bitis_saati?: string | null;
  dakika?: number | null;
  sube_id?: number;
};

export type BirimAmiriSecenegi = {
  user_id: number;
  ad_soyad: string;
  sube_id: number;
};

export type GunlukBildirimTamamlama = {
  id: number;
  tamamlandi_at: string | null;
  tamamlayan_user_id: number;
  state: string;
};

export type GunlukOzetCounts = {
  toplam_personel: number;
  bildirim_girilen: number;
  eksik_bildirim: number;
  sorunlu_personel: number;
  taslak: number;
  gonderildi: number;
  duzeltme_istendi: number;
  tamamlandi_mi: boolean;
};

export type GunlukOzetPersonel = {
  personel_id: number;
  ad_soyad: string;
  sicil_no: string | null;
  gorev_adi: string | null;
  departman_adi: string | null;
  bildirim_id: number | null;
  bildirim_turu: string | null;
  bildirim_state: string | null;
  son_islem_at: string | null;
  durum_label: string;
};

export type GunlukOzet = {
  tarih: string;
  sube_id: number;
  sube_adi: string;
  birim_amiri_user_id: number;
  birim_amiri_adi: string;
  ozet: GunlukOzetCounts;
  tamamlama: GunlukBildirimTamamlama | null;
  personeller: GunlukOzetPersonel[];
};
