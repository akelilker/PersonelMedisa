export type RevizyonKaynakSecenegi = {
  kaynak_tipi: string;
  kaynak_id: number;
  etkilenen_tarih: string;
  kaynak_turu_label: string;
  mevcut_deger: unknown;
  goruntuleme_etiketi: string;
  uygun_revizyon_tipleri: string[];
};

export type RevizyonAuditKaydi = {
  aksiyon: string;
  onceki_durum: string | null;
  sonraki_durum: string;
  islem_yapan_kullanici_id: number;
  islem_yapan_kullanici_adi?: string | null;
  islem_zamani: string;
  aciklama?: string | null;
};
