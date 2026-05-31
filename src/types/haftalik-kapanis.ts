import type { ComplianceUyari } from "./puantaj";

export type HaftalikKapanisPayload = {
  hafta_baslangic: string;
  hafta_bitis: string;
  departman_id?: number;
};

export type HaftalikKapanisState = "KAPANDI";

export type HaftalikKapanisSnapshotSatir = {
  snapshot_id?: number;
  kapanis_id?: number;
  personel_id: number;
  departman_id?: number;
  hafta_baslangic: string;
  hafta_bitis: string;
  yil?: number;
  hafta_no?: number;
  state: HaftalikKapanisState;
  kaynak_versiyon?: string;

  toplam_net_dakika: number;
  normal_calisma_dakika: number;
  fazla_calisma_dakika: number;
  fazla_surelerle_calisma_dakika: number;

  tam_hafta_verisi: boolean;

  compliance_uyarilari: ComplianceUyari[];
  compliance_uyari_sayisi: number;
  kritik_uyari_var_mi: boolean;

  hesaplama_zamani?: string;
  kaynak_gun_sayisi?: number;
  notlar?: string[];
};

export type HaftalikKapanisSonuc = {
  id?: number;
  kapanis_id?: number;
  hafta_baslangic?: string;
  hafta_bitis?: string;
  departman_id?: number;
  state?: HaftalikKapanisState;
  personel_sayisi?: number;
  snapshot_satir_sayisi?: number;
  snapshot_satirlari: HaftalikKapanisSnapshotSatir[];
};
