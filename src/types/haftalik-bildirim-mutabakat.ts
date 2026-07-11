import type { Bildirim } from "./bildirim";

export type HaftalikBildirimMutabakatCounts = {
  toplam: number;
  taslak: number;
  gonderildi: number;
  duzeltme_istendi: number;
  haftalik_mutabakata_alindi: number;
  iptal: number;
};

export type HaftalikBildirimMutabakatOzet = {
  hafta_baslangic: string;
  hafta_bitis: string;
  sube_id: number;
  birim_amiri_user_id: number | null;
  counts: HaftalikBildirimMutabakatCounts;
  onaylanabilir_mi: boolean;
  blok_nedeni: string | null;
  mevcut_mutabakat_id: number | null;
};

export type HaftalikBildirimMutabakat = {
  id: number;
  sube_id: number;
  birim_amiri_user_id: number;
  hafta_baslangic: string;
  hafta_bitis: string;
  state: "TAMAMLANDI";
  onaylayan_user_id: number;
  onaylandi_at: string | null;
  created_at: string;
  updated_at: string;
};

export type HaftalikBildirimMutabakatDetail = {
  mutabakat: HaftalikBildirimMutabakat;
  gunluk_bildirimler: Bildirim[];
  counts: { toplam: number; baglanan: number };
  baglanan_kayit_sayisi?: number;
};
