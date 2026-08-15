import type { UserRole } from "./auth";
import type { YillikIzinBakiye } from "./yillik-izin-hak-duzeltme";

export type MePersonelSummary = {
  id: number;
  ad: string;
  soyad: string;
  ad_soyad: string;
  sube_id: number;
  sube_ad: string;
  departman_id: number | null;
  departman_ad: string | null;
  gorev_id: number | null;
  gorev_ad: string | null;
  aktif_durum: string;
};

export type MeIdentity = {
  user_id: number;
  username: string;
  ad_soyad: string;
  rol: UserRole | string;
  personel_id: number;
  personel: MePersonelSummary;
};

export type MePuantajGun = {
  tarih: string;
  gun_tipi: string | null;
  giris_saati: string | null;
  cikis_saati: string | null;
  net_calisma_suresi_dakika: number | null;
  gunluk_brut_sure_dakika: number | null;
  gec_kalma_dakika: number | null;
  erken_cikis_dakika: number | null;
  fazla_calisma_dakika: number | null;
};

export type MePuantajOzet = {
  calisma_gun_adet: number;
  gec_kalma_adet: number;
  gec_kalma_dakika_toplam: number;
  erken_cikis_adet: number;
  erken_cikis_dakika_toplam: number;
  fazla_calisma_dakika_toplam: number;
};

export type MePuantajResponse = {
  personel_id: number;
  from: string;
  to: string;
  items: MePuantajGun[];
  ozet: MePuantajOzet;
};

export type MeYillikIzinBakiye = YillikIzinBakiye;

export type MeFazlaCalismaYillik = {
  personel_id: number;
  yil: number;
  yillik_limit_dakika: number;
  yaklasma_esik_dakika: number;
  kullanilan_dakika: number;
  kalan_dakika: number;
  limit_asildi_mi: boolean;
  limit_yaklasiyor_mu: boolean;
  kapanan_hafta_sayisi: number;
  atlanan_duplicate_hafta_sayisi: number;
  atlanan_eksik_hafta_sayisi: number;
};

export type MeFazlaCalismaResponse = {
  personel_id: number;
  yil: number;
  from: string;
  to: string;
  donem_ozet: {
    fazla_calisma_dakika_toplam: number;
    calisma_gun_adet: number;
  } | null;
  yillik: MeFazlaCalismaYillik;
};

export type QrEventType = "GIRIS" | "CIKIS";

export type MeQrAttendanceEvent = {
  id: number;
  event_type: QrEventType;
  occurred_at: string;
  sube: {
    id: number;
    ad: string;
  };
};

export type MeQrScanResponse = {
  event: MeQrAttendanceEvent;
  idempotent: boolean;
};

export type MeQrHareketleriResponse = {
  from: string;
  to: string;
  items: MeQrAttendanceEvent[];
};

export type MeQrIntervalSube = {
  id: number;
  ad: string;
};

export type MeQrInterval = {
  entry_event_id: number;
  exit_event_id: number;
  entry_at: string;
  exit_at: string;
  entry_local_date: string;
  exit_local_date: string;
  spans_local_midnight: boolean;
  duration_seconds: number;
  sube: MeQrIntervalSube;
};

export type MeQrIntervalAnomaly =
  | {
      type: "MISSING_CIKIS" | "MISSING_GIRIS";
      reason: string;
      event_id: number;
      event_type: QrEventType | string;
      occurred_at: string;
      local_date: string;
      sube: MeQrIntervalSube;
      correction_hint: string;
    }
  | {
      type: "BRANCH_MISMATCH";
      reason: string;
      entry_event_id: number;
      exit_event_id: number;
      occurred_at: string;
      local_date: string;
      entry_sube: MeQrIntervalSube;
      exit_sube: MeQrIntervalSube;
      correction_hint: string;
    };

export type MeQrAraliklariResponse = {
  from: string;
  to: string;
  algorithm_version: string;
  intervals: MeQrInterval[];
  anomalies: MeQrIntervalAnomaly[];
  summary: {
    complete_interval_count: number;
    anomaly_count: number;
    complete_duration_seconds: number;
  };
  source_event_count: number;
  source_max_event_id: number | null;
};

export type ManagerQrAttendanceItem = {
  personel_id: number;
  ad_soyad: string;
  sicil_no: string | null;
  sube_id: number;
  sube: string;
  date_from: string;
  date_to: string;
  first_entry: string | null;
  last_exit: string | null;
  last_movement: string | null;
  last_movement_type: QrEventType | null;
  inside: boolean;
  interval_count: number;
  missing_entry: boolean;
  missing_exit: boolean;
  branch_mismatch: boolean;
  anomalies: string[];
  matched_seconds: number;
  source_event_count: number;
};

export type ManagerQrAttendanceResponse = {
  from: string;
  to: string;
  items: ManagerQrAttendanceItem[];
  total: number;
  limit: number;
  offset: number;
  has_next: boolean;
  algorithm_version: string;
};

export type QrKioskTokenResponse = {
  token: string;
  issued_at: number;
  expires_at: number;
  ttl_seconds: number;
  sube: {
    id: number;
    ad: string;
  };
};

export const SELF_SERVICE_ERROR_CODES = [
  "SELF_SERVICE_BINDING_REQUIRED",
  "SELF_SERVICE_PERSONEL_INACTIVE",
  "SELF_SERVICE_PERSONEL_MISSING",
  "SELF_SERVICE_SCHEMA_NOT_READY",
  "PERSONEL_ALREADY_BOUND",
  "QR_CONFIG_NOT_READY",
  "QR_SCHEMA_NOT_READY",
  "QR_TOKEN_INVALID",
  "QR_TOKEN_EXPIRED",
  "QR_TOKEN_VERSION_UNSUPPORTED",
  "QR_CROSS_BRANCH_DENIED",
  "QR_EVENT_TYPE_INVALID",
  "QR_REQUEST_NONCE_INVALID",
  "QR_IDEMPOTENCY_CONFLICT"
] as const;

export type SelfServiceErrorCode = (typeof SELF_SERVICE_ERROR_CODES)[number];
