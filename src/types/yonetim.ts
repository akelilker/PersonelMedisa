import type { UserRole } from "./auth";

export type KullaniciTipi = "IC_PERSONEL" | "HARICI";
export type KayitDurumu = "AKTIF" | "PASIF";
export type PersonelActivationStatus = "PENDING" | "ACTIVE";

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
  sube_ids: number[];
  bolum_ids?: number[];
  birim_ids?: number[];
  sirket_ids?: number[];
  /** Payroll-employer grants (user_sgk_isverenler); not a physical branch list. */
  sgk_isveren_ids?: number[];
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
  /**
   * Sirket kapsami. IK_PERSONELI icin dogrudan islem yapabilecegi sirketler,
   * MUHASEBE icin canli sube cozumlemesi ureten sirket grant'i.
   */
  sirket_ids?: number[];
  /**
   * SGK/bordro isveren kapsami. Fiziksel sube yetkisi degildir; yalniz
   * personeller.sgk_isveren_id eksenindeki islemler icin.
   */
  sgk_isveren_ids?: number[];
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

export type YonetimOrgRelation = {
  id: number;
  kod: string | null;
  ad: string;
};

export type YonetimSubeMuhasebeYetkili = {
  id: number;
  username: string;
  ad_soyad: string;
  rol: string;
  durum: string;
  /** false when the stored ACL row survived but the user is no longer eligible. */
  eligible: boolean;
};

export type YonetimSube = {
  id: number;
  kod: string;
  /** Short branch name, e.g. "Ankara". Company detail screens show this. */
  ad: string;
  /**
   * Shared display name, e.g. "Medisa Ankara". Read-only and derived by the
   * backend read model — never concatenated in the frontend and never sent on
   * a write payload.
   */
  tam_ad: string;
  sirket: YonetimOrgRelation | null;
  sgk_isveren: YonetimOrgRelation | null;
  departman_ids: number[];
  departman_adlari: string[];
  durum: KayitDurumu;
  /**
   * Branch accounting visibility. Relation presence enables the restriction;
   * empty selected ids means unrestricted MUHASEBE grant behaviour.
   */
  muhasebe_kisit_aktif?: boolean;
  muhasebe_yetkili_user_ids?: number[];
  muhasebe_yetkilileri?: YonetimSubeMuhasebeYetkili[];
};

export type UpsertYonetimSubePayload = {
  kod: string;
  ad: string;
  departman_ids: number[];
  durum: KayitDurumu;
  sgk_isveren_id?: number | null;
  muhasebe_kisit_aktif?: boolean;
  muhasebe_yetkili_user_ids?: number[];
};

export type YonetimSirket = {
  id: number;
  kod: string;
  ad: string;
  durum: KayitDurumu;
  sube_sayisi: number;
};

export type UpsertYonetimSirketPayload = {
  kod?: string;
  ad: string;
  durum: KayitDurumu;
};

export type OrganizasyonReadiness = {
  schema_ready: boolean;
  data_ready: boolean;
  counts: {
    sirket_count: number;
    sube_count: number;
    unmapped_sube_count: number;
    unmapped_sgk_isveren_count: number;
    orphan_sube_sirket_count: number;
    orphan_lokasyon_sube_count: number;
    sube_sgk_sirket_mismatch_count: number;
  };
  blockers: string[];
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
