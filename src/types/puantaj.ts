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
  | "Telafi_Calismasi"
  | "Gorevde_Calisma";

export type PuantajHesapEtkisi =
  | "Tam_Yevmiye_Ver"
  | "Yevmiye_Kes"
  | "Ucretli_Izin"
  | "Raporlu"
  | "Mesai_Yaz"
  | "Telafi";

/** Birim amirinin günlük kayda baktığına dair görünürlük (zorunlu onay kapısı değil). */
export type PuantajAmirKontrolDurumu = "BEKLIYOR" | "AMIR_KONTROL_ETTI";

export type GunlukPuantaj = {
  personel_id: number;
  tarih: string;
  gun_tipi?: PuantajGunTipi;
  hareket_durumu?: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  durumu_bildirdi_mi?: boolean | null;
  durum_bildirim_aciklamasi?: string;
  hesap_etkisi?: PuantajHesapEtkisi;
  beklenen_giris_saati?: string;
  beklenen_cikis_saati?: string;
  giris_saati?: string;
  cikis_saati?: string;
  gec_kalma_dakika?: number;
  erken_cikis_dakika?: number;
  gercek_mola_dakika?: number;
  hesaplanan_mola_dakika?: number;
  net_calisma_suresi_dakika?: number;
  gunluk_brut_sure_dakika?: number;
  hafta_tatili_hak_kazandi_mi?: boolean;
  ubgt_gun_kapsami?: string;
  tatil_gun_kapsami?: string;
  yarim_gun_tatil_interval_dakika?: number;
  ht_ubgt_ayni_gun_mi?: boolean;
  gun_siniflandirmalari?: string[];
  tatil_takvim_id?: number | null;
  tatil_turu?: string | null;
  tatil_interval_baslangic?: string | null;
  tatil_interval_bitis?: string | null;
  tatil_siniflandirma_durumu?:
    | "DOGRULANDI"
    | "BILINMIYOR"
    | "CAKISMA"
    | "KAYNAK_EKSIK"
    | null;
  tatil_snapshot_hash?: string | null;
  tatil_kaynak_referansi?: string | null;
  tatil_donemi_brut_calisma_dakika?: number | null;
  tatil_donemi_ara_dinlenme_dakika?: number | null;
  tatil_donemi_net_calisma_dakika?: number | null;
  state?: GunlukPuantajState;
  kontrol_durumu?: PuantajAmirKontrolDurumu;
  compliance_uyarilari: ComplianceUyari[];
};

export type UpsertGunlukPuantajPayload = {
  gun_tipi?: PuantajGunTipi;
  hareket_durumu?: PuantajHareketDurumu;
  dayanak?: PuantajDayanak;
  durumu_bildirdi_mi?: boolean | null;
  durum_bildirim_aciklamasi?: string | null;
  beklenen_giris_saati?: string;
  beklenen_cikis_saati?: string;
  giris_saati?: string;
  cikis_saati?: string;
  gec_kalma_dakika?: number;
  erken_cikis_dakika?: number;
  gercek_mola_dakika?: number;
  kontrol_durumu?: PuantajAmirKontrolDurumu;
};
