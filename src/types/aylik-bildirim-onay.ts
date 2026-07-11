import type { HaftalikBildirimMutabakat } from "./haftalik-bildirim-mutabakat";

export type AylikBildirimOnayCounts = {
  toplam_bildirim: number;
  mutabakata_alinan: number;
  mutabakatli_hafta: number;
  eksik_hafta: number;
  taslak: number;
  duzeltme_istendi: number;
  gonderildi: number;
};

export type AylikBildirimOnayHafta = {
  hafta_baslangic: string;
  hafta_bitis: string;
  mutabakat_id: number | null;
  state: string | null;
  bildirim_sayisi: number;
  mutabakata_alinan_sayisi: number;
  eksik_mi: boolean;
  blok_nedeni: string | null;
};

export type AylikBildirimOnay = {
  id: number;
  sube_id: number;
  birim_amiri_user_id: number;
  ay: string;
  ay_baslangic: string;
  ay_bitis: string;
  state: "TAMAMLANDI";
  onaylayan_user_id: number;
  onaylandi_at: string | null;
  aciklama: string | null;
  created_at: string;
  updated_at: string;
};

export type AylikBildirimOnayOzet = {
  ay: string;
  ay_baslangic: string;
  ay_bitis: string;
  sube_id: number;
  birim_amiri_user_id: number | null;
  haftalar: AylikBildirimOnayHafta[];
  counts: AylikBildirimOnayCounts;
  onaylanabilir_mi: boolean;
  blok_nedeni: string | null;
  mevcut_onay_id: number | null;
};

export type AylikBildirimOnayDetail = {
  onay: AylikBildirimOnay;
  haftalar: AylikBildirimOnayHafta[];
  haftalik_mutabakatlar: HaftalikBildirimMutabakat[];
  counts: AylikBildirimOnayCounts;
};

export type AylikBildirimOnayPayload = {
  ay: string;
  aciklama?: string | null;
};
