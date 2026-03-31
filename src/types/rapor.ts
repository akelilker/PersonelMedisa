export type RaporTipi =
  | "personel-ozet"
  | "izin"
  | "devamsizlik"
  | "tesvik"
  | "ceza"
  | "ekstra-prim"
  | "is-kazasi"
  | "bildirim";

export type RaporAktiflik = "aktif" | "pasif" | "tum";

export type RaporFiltreleri = {
  personel_id?: number;
  departman_id?: number;
  baslangic_tarihi?: string;
  bitis_tarihi?: string;
  aktiflik?: RaporAktiflik;
};

export type RaporSatiri = Record<string, unknown>;

export type RaporSonuc = {
  rows: RaporSatiri[];
  total: number | null;
};
