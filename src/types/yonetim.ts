import type { UserRole } from "./auth";

export type KullaniciTipi = "IC_PERSONEL" | "HARICI";
export type KayitDurumu = "AKTIF" | "PASIF";
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

export type PersonelHesapOnboardingUser = {
  id: number;
  username: string;
  rol?: string;
  durum?: string;
  personel_id?: number | null;
  must_change_password?: boolean;
};

/**
 * Canonical yeni hesap create sonucu: template baslangic sifresi + zorunlu ilk giris
 * sifre degisimi. Aktivasyon daveti/linki ve secret tasimaz.
 */
export type PersonelHesapFirstLoginResult = {
  user: PersonelHesapOnboardingUser;
  credential_model?: string;
  message?: string;
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
  can_prepare?: boolean;
  can_approve?: boolean;
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

/** Durable branch-manager responsibility row (not user_subeler access scope). */
export type YonetimSubeSorumluYonetici = {
  id: number;
  username: string;
  ad_soyad: string;
  rol: string;
  durum: string;
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
  /**
   * Durable responsible manager assignment for this branch.
   * Empty = zero managers (valid). Independent of user_subeler / home branch.
   */
  sorumlu_yonetici_user_ids?: number[];
  sorumlu_yoneticiler?: YonetimSubeSorumluYonetici[];
};

export type UpsertYonetimSubePayload = {
  kod: string;
  ad: string;
  departman_ids: number[];
  durum: KayitDurumu;
  sgk_isveren_id?: number | null;
  muhasebe_kisit_aktif?: boolean;
  muhasebe_yetkili_user_ids?: number[];
  sorumlu_yonetici_user_ids?: number[];
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

/**
 * SGK employer catalog row owned by the organisation (sirket -> sgk_isveren ->
 * sube) hierarchy. `sirket` is null only while the employer is still unmapped.
 */
export type YonetimSgkIsveren = {
  id: number;
  kod: string | null;
  ad: string;
  durum: KayitDurumu;
  sirket: YonetimOrgRelation | null;
  /** How many branches are currently attached to this employer. */
  sube_sayisi: number;
};

export type UpsertYonetimSgkIsverenPayload = {
  sirket_id: number;
  kod: string;
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
