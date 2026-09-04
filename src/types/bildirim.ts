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

/** Header + detail owner for a completed daily attendance submission. */
export type GunlukTamamlamaHeaderItem = {
  id: number;
  kind?: "gunluk_tamamlama";
  sube_id: number;
  sube_adi?: string | null;
  birim_amiri_user_id: number;
  tarih: string;
  state: string;
  tamamlayan_user_id: number;
  tamamlayan_ad_soyad: string;
  tamamlandi_at: string | null;
  created_at?: string | null;
  okundu_mi?: boolean;
  toplam_personel?: number | null;
};

export type GunlukTamamlamaKayit = {
  bildirim_id: number;
  personel_id: number;
  ad_soyad: string;
  bildirim_turu: string;
  dakika: number | null;
  baslangic_saati: string | null;
  bitis_saati: string | null;
  aciklama: string | null;
  departman_adi: string | null;
};

export type GunlukTamamlamaKategori = {
  tur: string;
  label: string;
  count: number;
  kayitlar: GunlukTamamlamaKayit[];
};

export type GunlukTamamlamaOzetCounts = {
  toplam_personel: number;
  gec_gelen: number;
  gelmeyen: number;
  izinli: number;
  raporlu: number;
  izinli_raporlu: number;
  erken_cikan: number;
  gorevde: number;
  diger: number;
  bildirim_satiri: number;
};

export type GunlukTamamlamaDetail = {
  submission: GunlukTamamlamaHeaderItem;
  scope_label: string;
  ozet: GunlukTamamlamaOzetCounts;
  kategoriler: GunlukTamamlamaKategori[];
  kayitlar: GunlukTamamlamaKayit[];
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

/** BIRIM_AMIRI operational home — OrgScope unit roster for today. */
export type BirimAmiriGunlukDurumOzet = {
  toplam_personel: number;
  geldi: number;
  gelmedi: number;
  gec_geldi: number;
  izinli_raporlu: number;
  erken_cikti: number;
  gorevde: number;
};

export type BirimAmiriGunlukDurumPersonel = {
  personel_id: number;
  ad_soyad: string;
  durum: string;
  durum_label: string;
  gec_kalma_dakika: number | null;
  erken_cikis_dakika: number | null;
  giris_saati: string | null;
  cikis_saati: string | null;
};

export type BirimAmiriGunlukDurum = {
  tarih: string;
  ozet: BirimAmiriGunlukDurumOzet;
  tamamlandi_mi: boolean;
  tamamlama: GunlukBildirimTamamlama | null;
  personeller: BirimAmiriGunlukDurumPersonel[];
};

/** IK / GENEL morning operations overview — branch → unit → person. */
export type BugunPersonelDurumuStatusCounts = {
  toplam: number;
  geldi: number;
  gec_geldi: number;
  gelmedi: number;
  izinli: number;
  raporlu: number;
  gorevde: number;
  erken_cikti: number;
};

export type BugunPersonelDurumuPerson = {
  personel_id: number;
  ad_soyad: string;
  durum: string;
  durum_label: string;
  gec_kalma_dakika: number | null;
  erken_cikis_dakika: number | null;
  giris_saati: string | null;
  cikis_saati: string | null;
  aciklama: string | null;
  alt_tur: string | null;
  detail_line: string;
  group: "PLANNED" | "ACTUAL" | "ATTENTION" | string;
};

export type BugunPersonelDurumuBildirim = {
  status: string;
  status_label: string;
  tamamlandi_mi: boolean;
  tamamlandi_at: string | null;
  tamamlayan_user_id: number | null;
  completion_id: number | null;
};

export type BugunPersonelDurumuUnit = {
  birim_id: number | null;
  birim_adi: string;
  bolum_id: number | null;
  bolum_adi: string | null;
  counts: BugunPersonelDurumuStatusCounts;
  bildirim: BugunPersonelDurumuBildirim;
  personeller: BugunPersonelDurumuPerson[];
};

export type BugunPersonelDurumuBranch = {
  sube_id: number;
  sube_adi: string;
  counts: BugunPersonelDurumuStatusCounts;
  birim_bildirim: { tamamlanan: number; toplam: number };
  units: BugunPersonelDurumuUnit[];
};

export type BugunPersonelDurumu = {
  tarih: string;
  timezone: string;
  workday_start: string;
  on_time_deadline: string;
  server_now: string;
  attention_count: number;
  branches: BugunPersonelDurumuBranch[];
};
