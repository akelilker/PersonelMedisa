export type SerbestZamanEventTipi =
  | "SERBEST_ZAMAN_OLUSUM"
  | "SERBEST_ZAMAN_KULLANIM"
  | "SERBEST_ZAMAN_DUZELTME"
  | "SERBEST_ZAMAN_IPTAL";

export const SERBEST_ZAMAN_EVENT_TIPI_VALUES = [
  "SERBEST_ZAMAN_OLUSUM",
  "SERBEST_ZAMAN_KULLANIM",
  "SERBEST_ZAMAN_DUZELTME",
  "SERBEST_ZAMAN_IPTAL"
] as const satisfies readonly SerbestZamanEventTipi[];

export type SerbestZamanHedefEventTipi = "SERBEST_ZAMAN_OLUSUM" | "SERBEST_ZAMAN_KULLANIM";

export type SerbestZamanOlusumEvent = {
  id?: number;
  personel_id: number;
  kaynak_snapshot_id: number;
  kaynak_odeme_tercihi_id: number;
  event_tipi: "SERBEST_ZAMAN_OLUSUM";
  dakika: number;
  event_tarihi: string;
  son_kullanim_tarihi: string;
  aciklama?: string;
  donem_yil?: number | null;
  donem_ay?: number | null;
  donem_kilitli_miydi?: boolean;
};

export type SerbestZamanKullanimEvent = {
  id?: number;
  personel_id: number;
  event_tipi: "SERBEST_ZAMAN_KULLANIM";
  dakika: number;
  event_tarihi: string;
  islem_anahtari: string;
  aciklama?: string;
  donem_yil?: number | null;
  donem_ay?: number | null;
  donem_kilitli_miydi?: boolean;
};

export type SerbestZamanIptalEvent = {
  id?: number;
  personel_id: number;
  event_tipi: "SERBEST_ZAMAN_IPTAL";
  hedef_event_id: number;
  hedef_event_tipi: SerbestZamanHedefEventTipi;
  event_tarihi: string;
  islem_anahtari: string;
  aciklama?: string;
  donem_yil?: number | null;
  donem_ay?: number | null;
  donem_kilitli_miydi?: boolean;
};

export type SerbestZamanDuzeltmeEvent = {
  id?: number;
  personel_id: number;
  event_tipi: "SERBEST_ZAMAN_DUZELTME";
  hedef_event_id: number;
  hedef_event_tipi: SerbestZamanHedefEventTipi;
  yeni_dakika: number;
  event_tarihi: string;
  islem_anahtari: string;
  aciklama: string;
  donem_yil?: number | null;
  donem_ay?: number | null;
  donem_kilitli_miydi?: boolean;
};

export type SerbestZamanHedefEvent = SerbestZamanOlusumEvent | SerbestZamanKullanimEvent;

export type SerbestZamanEvent =
  | SerbestZamanOlusumEvent
  | SerbestZamanKullanimEvent
  | SerbestZamanIptalEvent
  | SerbestZamanDuzeltmeEvent;

export type SerbestZamanAllocationState =
  | "ALLOCATED"
  | "LEGACY_UNALLOCATED"
  | "INVARIANT_BROKEN"
  | "NO_USAGE";

export type SerbestZamanBakiye = {
  personel_id: number;
  toplam_hak_dakika: number;
  kullanilan_dakika: number;
  kalan_dakika: number;
  suresi_dolan_dakika: number;
  event_sayisi: number;
  /** Pack 4A — present when allocation ledger table exists */
  allocation_state?: SerbestZamanAllocationState;
  allocation_policy?: string;
  legacy_unallocated_usage_count?: number;
  lot_based_balance_available?: number | null;
  lot_based_expired_unused?: number | null;
};

export type PostSerbestZamanOlusumPayload = {
  odeme_tercihi_id?: number;
  snapshot_id?: number;
};

export type PostSerbestZamanKullanimPayload = {
  personel_id: number | string;
  dakika: number;
  event_tarihi: string;
  islem_anahtari: string;
  aciklama?: string;
};

export type PostSerbestZamanIptalPayload = {
  personel_id: number | string;
  hedef_event_id: number | string;
  hedef_event_tipi: SerbestZamanHedefEventTipi;
  event_tarihi: string;
  islem_anahtari: string;
  aciklama?: string;
};

export type PostSerbestZamanDuzeltmePayload = {
  personel_id: number | string;
  hedef_event_id: number | string;
  hedef_event_tipi: SerbestZamanHedefEventTipi;
  yeni_dakika: number;
  event_tarihi: string;
  islem_anahtari: string;
  aciklama: string;
};
