export type OdemeTipi = "KARAR_BEKLIYOR" | "UCRET" | "SERBEST_ZAMAN";

export const ODEME_TIPI_VALUES = [
  "KARAR_BEKLIYOR",
  "UCRET",
  "SERBEST_ZAMAN"
] as const satisfies readonly OdemeTipi[];

export const DEFAULT_ODEME_TIPI: OdemeTipi = "KARAR_BEKLIYOR";

export type FazlaCalismaOdemeTercihi = {
  id?: number;
  snapshot_id: number;
  kapanis_id: number;
  personel_id: number;
  hafta_baslangic: string;
  hafta_bitis: string;
  fazla_calisma_dakika: number;
  odeme_tipi: OdemeTipi;
  secim_zamani?: string;
  secen_kullanici_id?: number;
  onceki_odeme_tipi?: OdemeTipi;
  gerekce?: string;
};

export type PutFazlaCalismaOdemeTercihiPayload = {
  snapshot_id: number;
  odeme_tipi: OdemeTipi;
  gerekce?: string;
};
