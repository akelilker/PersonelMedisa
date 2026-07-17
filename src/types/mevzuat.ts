export type MevzuatDegerTipi = "SAYISAL" | "METIN";

export type MevzuatDurum = "AKTIF" | "IPTAL";

export type MevzuatParametresi = {
  id: number;
  parametre_kodu: string;
  deger_tipi: MevzuatDegerTipi;
  sayisal_deger: number | null;
  metin_deger: string | null;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  birim?: string | null;
  aciklama?: string | null;
  kaynak_referansi?: string | null;
  durum: MevzuatDurum;
  created_at?: string | null;
  created_by?: number | null;
  updated_at?: string | null;
  updated_by?: number | null;
};

export type CreateMevzuatParametresiPayload = {
  parametre_kodu: string;
  deger_tipi: MevzuatDegerTipi;
  sayisal_deger?: number | null;
  metin_deger?: string | null;
  gecerlilik_baslangic: string;
  gecerlilik_bitis?: string | null;
  birim?: string;
  aciklama?: string;
  kaynak_referansi?: string;
};

export type UpdateMevzuatParametresiPayload = Partial<CreateMevzuatParametresiPayload>;
