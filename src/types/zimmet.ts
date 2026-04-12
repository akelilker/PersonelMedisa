export type ZimmetUrunTuru =
  | "AYAKKABI"
  | "KASK"
  | "KULAKLIK"
  | "MASKE"
  | "TELEFON"
  | "DIGER";

export type ZimmetTeslimDurumu = "YENI" | "IKINCI_EL" | "ARIZALI";

export type ZimmetKayitDurumu = "AKTIF" | "IADE_EDILDI";

export type Zimmet = {
  id: number;
  personel_id: number;
  urun_turu: ZimmetUrunTuru | string;
  teslim_tarihi: string;
  teslim_eden?: string;
  aciklama?: string;
  teslim_durumu: ZimmetTeslimDurumu | string;
  zimmet_durumu: ZimmetKayitDurumu | string;
  iade_tarihi?: string;
};

export type CreateZimmetPayload = {
  personel_id: number;
  urun_turu: ZimmetUrunTuru | string;
  teslim_tarihi: string;
  teslim_eden: string;
  aciklama?: string;
  teslim_durumu: ZimmetTeslimDurumu | string;
};

export const ZIMMET_URUN_TURU_OPTIONS = [
  { value: "AYAKKABI", label: "Ayakkabi" },
  { value: "KASK", label: "Kask" },
  { value: "KULAKLIK", label: "Kulaklik" },
  { value: "MASKE", label: "Maske" },
  { value: "TELEFON", label: "Telefon" },
  { value: "DIGER", label: "Diger" }
] as const;

export const ZIMMET_TESLIM_DURUMU_OPTIONS = [
  { value: "YENI", label: "Yeni" },
  { value: "IKINCI_EL", label: "Ikinci El" },
  { value: "ARIZALI", label: "Arizali" }
] as const;
