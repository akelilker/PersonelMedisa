export type ComplianceUyariSeviye = "BILGI" | "UYARI" | "KRITIK" | string;

export type ComplianceUyari = {
  code: string;
  message: string;
  level?: ComplianceUyariSeviye;
};

export type GunlukPuantajState = "ACIK" | "HESAPLANDI" | "MUHURLENDI" | string;

export type GunlukPuantaj = {
  personel_id: number;
  tarih: string;
  giris_saati?: string;
  cikis_saati?: string;
  gercek_mola_dakika?: number;
  hesaplanan_mola_dakika?: number;
  net_calisma_suresi_dakika?: number;
  gunluk_brut_sure_dakika?: number;
  state?: GunlukPuantajState;
  compliance_uyarilari: ComplianceUyari[];
};

export type UpsertGunlukPuantajPayload = {
  giris_saati: string;
  cikis_saati: string;
  gercek_mola_dakika?: number;
};
