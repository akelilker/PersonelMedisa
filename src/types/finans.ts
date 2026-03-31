export type FinansDurum = "AKTIF" | "IPTAL" | string;

export type FinansKalem = {
  id: number;
  personel_id: number;
  donem: string;
  kalem_turu: string;
  tutar: number;
  aciklama?: string;
  state?: FinansDurum;
};

export type CreateFinansKalemPayload = {
  personel_id: number;
  donem: string;
  kalem_turu: string;
  tutar: number;
  aciklama?: string;
};

export type UpdateFinansKalemPayload = Partial<CreateFinansKalemPayload>;
