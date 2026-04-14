import type { UserRole } from "./auth";

export type KullaniciTipi = "IC_PERSONEL" | "HARICI";
export type KayitDurumu = "AKTIF" | "PASIF";

export type YonetimKullanici = {
  id: number;
  ad_soyad: string;
  telefon?: string;
  kullanici_tipi: KullaniciTipi;
  rol: UserRole;
  personel_id?: number | null;
  personel_ad_soyad?: string | null;
  sube_ids: number[];
  varsayilan_sube_id: number | null;
  durum: KayitDurumu;
  notlar?: string;
};

export type UpsertYonetimKullaniciPayload = {
  ad_soyad: string;
  telefon?: string;
  kullanici_tipi: KullaniciTipi;
  rol: UserRole;
  personel_id?: number | null;
  sube_ids: number[];
  varsayilan_sube_id?: number | null;
  durum: KayitDurumu;
  notlar?: string;
};

export type YonetimSube = {
  id: number;
  kod: string;
  ad: string;
  departman_ids: number[];
  departman_adlari: string[];
  durum: KayitDurumu;
};

export type UpsertYonetimSubePayload = {
  kod: string;
  ad: string;
  departman_ids: number[];
  durum: KayitDurumu;
};

/** Bölüm satırı: üst onay `kapanis_durumu` ile taşınır; bu alanda KAPANDI kullanılmaz. */
export type AylikBolumOnayDurumu = "BOLUM_ONAYINDA" | "BOLUM_ONAYLANDI" | "REVIZE_ISTENDI";

/** Özet üst state: KAPANDI yalnızca genel yönetici üst onayı sonrası (geriye uyumlu string). */
export type AylikOzetAggregateState = AylikBolumOnayDurumu | "KAPANDI";

export type AylikOzetFilters = {
  ay: string;
  sube_id?: number;
  departman_id?: number;
  sadece_revizeli?: boolean;
};

export type AylikOzetRow = {
  personel_id: number;
  ad_soyad: string;
  sicil_no?: string;
  sube: string;
  bolum: string;
  birim_amiri: string;
  devamsizlik_gun: number;
  gec_kalma_adet: number;
  izinli_gelmedi: number;
  izinsiz_gelmedi: number;
  raporlu: number;
  tesvik_tutari: number;
  ceza_kesinti_tutari: number;
  bolum_onay_durumu: AylikBolumOnayDurumu;
  revize_var_mi: boolean;
  son_islem: string;
  kapanis_durumu: "ACIK" | "KAPANDI";
};

export type AylikOzetSummary = {
  toplam_personel: number;
  toplam_devamsizlik_gun: number;
  toplam_gec_kalma: number;
  toplam_izinli_gelmedi: number;
  toplam_izinsiz_gelmedi: number;
  toplam_raporlu: number;
  toplam_tesvik_tutari: number;
  toplam_ceza_kesinti_tutari: number;
};

export type AylikOzetResponse = {
  ay: string;
  state: AylikOzetAggregateState;
  summary: AylikOzetSummary;
  items: AylikOzetRow[];
  pending_bolum_onayi: number;
};
