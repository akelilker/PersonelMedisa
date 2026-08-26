import type { UserRole } from "./auth";

export type KullaniciTipi = "IC_PERSONEL" | "HARICI";
export type KayitDurumu = "AKTIF" | "PASIF";
export type PersonelActivationStatus = "PENDING" | "ACTIVE";
export type UsernameSource = "SICIL_CANONICAL" | "MANUAL" | "SYSTEM" | string;

export type YonetimKullanici = {
  id: number;
  username?: string;
  ad_soyad: string;
  telefon?: string;
  kullanici_tipi: KullaniciTipi;
  rol: UserRole;
  personel_id?: number | null;
  personel_ad_soyad?: string | null;
  /** Canonical DB flag; omitted when schema column absent. Never a credential secret. */
  must_change_password?: boolean;
  activation_required?: boolean;
  activation_status?: PersonelActivationStatus;
  activated_at_utc?: string | null;
  username_source?: UsernameSource;
  sube_ids: number[];
  bolum_ids?: number[];
  birim_ids?: number[];
  varsayilan_sube_id: number | null;
  durum: KayitDurumu;
  notlar?: string;
};

export type PersonelActivationIssue = {
  activation_url: string;
  created_at_utc: string;
  expires_at_utc: string;
  reissued: boolean;
};

export type PersonelHesapOnboardingResult = {
  user: {
    id: number;
    username: string;
    rol?: string;
    durum?: string;
    personel_id?: number | null;
    activation_required?: boolean;
    must_change_password?: boolean;
    username_source?: UsernameSource;
    activated_at_utc?: string | null;
  };
  activation: PersonelActivationIssue;
  message?: string;
};

export type PersonelActivationInvitationMeta = {
  invitation_id?: number;
  created_at_utc: string;
  expires_at_utc: string;
  is_expired: boolean;
  is_valid: boolean;
};

export type PersonelActivationMetaResponse = {
  activation_invitation: PersonelActivationInvitationMeta | null;
};

export type UpsertYonetimKullaniciPayload = {
  username?: string;
  password?: string;
  ad_soyad: string;
  telefon?: string;
  kullanici_tipi: KullaniciTipi;
  rol: UserRole;
  personel_id?: number | null;
  sube_ids: number[];
  bolum_ids?: number[];
  birim_ids?: number[];
  varsayilan_sube_id?: number | null;
  durum: KayitDurumu;
  notlar?: string;
};

export type ActorIdentityStatus = "PENDING" | "VERIFIED" | "REVOKED";

export type YonetimActorIdentityRead = {
  user_id: number | null;
  actor_identity_id: number | null;
  actor_status: ActorIdentityStatus | null;
  personel_id: number | null;
  branch_scope: number[];
  ready: boolean;
  readiness_code?: string | null;
};

export type YonetimSube = {
  id: number;
  kod: string;
  ad: string;
  departman_ids: number[];
  departman_adlari: string[];
  durum: KayitDurumu;
};

export type UpsertYonetimSubePayload = {
  kod: string;
  ad: string;
  departman_ids: number[];
  durum: KayitDurumu;
};

/** Bölüm satırı: üst onay `kapanis_durumu` ile taşınır; bu alanda KAPANDI kullanılmaz. */
export type AylikBolumOnayDurumu = "BOLUM_ONAYINDA" | "BOLUM_ONAYLANDI" | "REVIZE_ISTENDI";

/** Özet üst state: KAPANDI yalnızca genel yönetici üst onayı sonrası (geriye uyumlu string). */
export type AylikOzetAggregateState = AylikBolumOnayDurumu | "KAPANDI";

export type AylikOzetFilters = {
  ay: string;
  sube_id?: number;
  departman_id?: number;
  sadece_revizeli?: boolean;
};

export type AylikOzetRow = {
  personel_id: number;
  ad_soyad: string;
  sicil_no?: string;
  sube: string;
  bolum: string;
  bagli_amir_adi: string;
  devamsizlik_gun: number;
  gec_kalma_adet: number;
  izinli_gelmedi: number;
  izinsiz_gelmedi: number;
  raporlu: number;
  tesvik_tutari: number;
  ceza_kesinti_tutari: number;
  bolum_onay_durumu: AylikBolumOnayDurumu;
  revize_var_mi: boolean;
  son_islem: string;
  kapanis_durumu: "ACIK" | "KAPANDI";
};

export type AylikOzetSummary = {
  toplam_personel: number;
  toplam_devamsizlik_gun: number;
  toplam_gec_kalma: number;
  toplam_izinli_gelmedi: number;
  toplam_izinsiz_gelmedi: number;
  toplam_raporlu: number;
  toplam_tesvik_tutari: number;
  toplam_ceza_kesinti_tutari: number;
};

export type AylikOzetResponse = {
  ay: string;
  state: AylikOzetAggregateState;
  summary: AylikOzetSummary;
  items: AylikOzetRow[];
  pending_bolum_onayi: number;
};
