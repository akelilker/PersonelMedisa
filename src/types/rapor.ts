import type { PaginationMeta } from "./api";

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
  page?: number;
  limit?: number;
};

export type RaporSatiri = Record<string, unknown>;

export type RaporKolonu = {
  key: string;
  label: string;
};

export type RaporSonuc = {
  rows: RaporSatiri[];
  total: number | null;
  pagination: PaginationMeta;
};
