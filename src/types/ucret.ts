export type UcretTuru = "BRUT" | "NET";

export type UcretDurum = "AKTIF" | "IPTAL";

export type UcretKaynak = "MANUEL" | "PERSONEL_KAYDI_MIGRASYON" | "SISTEM";

export type PersonelUcretKaydi = {
  /** Legacy maas'tan türetilen sanal "aktif ücret" kaydında id null olabilir. */
  id: number | null;
  personel_id: number;
  ucret_tutari: number;
  ucret_turu: UcretTuru;
  para_birimi: string;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  durum: UcretDurum;
  guncel_mi: boolean;
  kaynak: UcretKaynak;
  aciklama?: string | null;
  created_at?: string | null;
  created_by?: number | null;
  updated_at?: string | null;
  updated_by?: number | null;
};

export type CreatePersonelUcretPayload = {
  ucret_tutari: number;
  ucret_turu: UcretTuru;
  para_birimi?: string;
  gecerlilik_baslangic: string;
  gecerlilik_bitis?: string | null;
  aciklama?: string;
};

export type UpdatePersonelUcretPayload = Partial<CreatePersonelUcretPayload>;
