export type ComplianceUyariSeviye = "BILGI" | "UYARI" | "KRITIK" | string;

export type ComplianceUyari = {
  code: string;
  message: string;
  level?: ComplianceUyariSeviye;
};

export type GunlukPuantajState = "ACIK" | "HESAPLANDI" | "MUHURLENDI" | string;

export type PuantajGunTipi =
  | "Normal_Is_Gunu"
  | "Hafta_Tatili_Pazar"
  | "UBGT_Resmi_Tatil";

export type PuantajHareketDurumu =
  | "Geldi"
  | "Gelmedi"
  | "Gec_Geldi"
  | "Erken_Cikti";

export type PuantajDayanak =
  | "Yok_Izinsiz"
  | "Ucretli_Izinli"
  | "Raporlu_Hastalik"
  | "Raporlu_Is_Kazasi"
  | "Yillik_Izin"
  | "Telafi_Calismasi";

export type PuantajHesapEtkisi =
  | "Kesinti_Yap"
  | "Tam_Yevmiye_Ver"
  | "Mesai_Yaz";

export type GunlukPuantaj = {
  personel_id: number;
  tarih: string;
  gun_tipi?: PuantajGunTipi;
  hareket_durumu?: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  hesap_etkisi?: PuantajHesapEtkisi;
  giris_saati?: string;
  cikis_saati?: string;
  gercek_mola_dakika?: number;
  hesaplanan_mola_dakika?: number;
  net_calisma_suresi_dakika?: number;
  gunluk_brut_sure_dakika?: number;
  hafta_tatili_hak_kazandi_mi?: boolean;
  state?: GunlukPuantajState;
  compliance_uyarilari: ComplianceUyari[];
};

export type UpsertGunlukPuantajPayload = {
  gun_tipi?: PuantajGunTipi;
  hareket_durumu?: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  giris_saati?: string;
  cikis_saati?: string;
  gercek_mola_dakika?: number;
};
