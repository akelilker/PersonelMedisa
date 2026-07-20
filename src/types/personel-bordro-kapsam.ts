export type BordroKapsamDurum = "DAHIL" | "HARIC";

export type BordroKapsamNedenKodu =
  | "DEMO_TEST_VERISI"
  | "BORDRO_DISI_STATU"
  | "HARICI_BORDRO"
  | "DIGER_ONAYLI_NEDEN";

export type BordroKapsamState = "TASLAK" | "ONAY_BEKLIYOR" | "ONAYLANDI" | "IPTAL";

export type PersonelBordroKapsamKaydi = {
  id: number;
  personel_id: number;
  sube_id: number;
  durum: BordroKapsamDurum;
  neden_kodu: BordroKapsamNedenKodu;
  aciklama: string;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  state: BordroKapsamState;
  hazirlayan_id?: number | null;
  onaylayan_id?: number | null;
  onay_zamani?: string | null;
  iptal_eden_id?: number | null;
  iptal_zamani?: string | null;
  iptal_nedeni?: string | null;
  parent_kapsam_id?: number | null;
  created_by?: number | null;
  created_at?: string | null;
  updated_by?: number | null;
  updated_at?: string | null;
  sicil_no?: string | null;
  ad?: string | null;
  soyad?: string | null;
  ad_soyad?: string | null;
};

export type CreatePersonelBordroKapsamPayload = {
  durum: BordroKapsamDurum;
  neden_kodu: BordroKapsamNedenKodu;
  aciklama: string;
  gecerlilik_baslangic: string;
  gecerlilik_bitis?: string | null;
  dry_run_hash: string;
  yil?: number;
  ay?: number;
  direkt_onayla?: boolean;
};

export type PersonelBordroKapsamDryRunResult = {
  ok: boolean;
  contract_version: string;
  write_performed: boolean;
  dry_run_hash: string;
  personel: {
    id: number;
    sicil_no: string;
    ad_soyad: string;
    sube_id: number;
  };
  proposed: {
    durum: BordroKapsamDurum;
    neden_kodu: BordroKapsamNedenKodu;
    aciklama: string;
    gecerlilik_baslangic: string;
    gecerlilik_bitis: string | null;
  };
  donem: {
    baslangic: string;
    bitis: string;
  };
  effects: {
    currently_excluded: boolean;
    would_exclude_from_new_snapshot: boolean;
    muhur_satiri_var_mi: boolean;
    muhur_satir_sayisi: number;
    existing_snapshot_unchanged: boolean;
    existing_snapshot: {
      id: number;
      snapshot_hash: string;
      source_hash: string;
      revision_no: number;
    } | null;
    source_hash_would_change: boolean;
    explicit_snapshot_revision_required: boolean;
    carryover_blocker_suppressed: boolean;
    net_maas_blocker_suppressed: boolean;
    candidate_item_excluded: boolean;
  };
  warnings: string[];
};
