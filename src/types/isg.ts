export type IsgMakineDurum = "aktif" | "arizali" | "pasif";

export type MakineBakimDurumuOzet = "guncel" | "gecikmis" | "eksik_veri";

export type IsgMakineListItem = {
  id: number;
  ad: string;
  tip: string;
  konum: string | null;
  durum: IsgMakineDurum;
  subeId: number | null;
  subeAdi: string | null;
  sonBakim: string | null;
  sonrakiBakim: string | null;
  bakimPeriyotGun: number | null;
  gecikmeGun: number | null;
  uyariDurumu: MakineBakimDurumuOzet;
};

export type IsgMakineDetail = {
  id: number;
  ad: string;
  tip: string;
  konum: string | null;
  durum: IsgMakineDurum;
  subeId: number | null;
  subeAdi: string | null;
  bakimPeriyotGun: number | null;
};

export type IsgBakimKaydi = {
  id: number;
  makineId: number | null;
  bakimTarihi: string | null;
  yapan: string | null;
  notlar: string | null;
};

export type ListIsgMakinelerParams = {
  search?: string;
  durum?: IsgMakineDurum | "tum";
  tip?: string;
  sube_id?: number;
  page?: number;
  limit?: number;
};
