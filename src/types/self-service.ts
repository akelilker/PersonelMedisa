import type { UserRole } from "./auth";
import type { YillikIzinBakiye } from "./yillik-izin-hak-duzeltme";

export type MePersonelSummary = {
  id: number;
  ad: string;
  soyad: string;
  ad_soyad: string;
  sicil_no: string | null;
  ise_giris_tarihi: string | null;
  /** Canonical GET /me fields. Absent or empty renders as "-" — never invented. */
  tc_kimlik_no?: string | null;
  dogum_tarihi?: string | null;
  telefon?: string | null;
  kan_grubu?: string | null;
  /** Stored on personeller.cinsiyet when present; absent/null → UI shows "-". */
  cinsiyet?: string | null;
  sube_id: number;
  sube_ad: string;
  departman_id: number | null;
  departman_ad: string | null;
  bolum_id: number | null;
  bolum_ad: string | null;
  birim_id: number | null;
  birim_ad: string | null;
  gorev_id: number | null;
  gorev_ad: string | null;
  aktif_durum: string;
};

export type MeCompletenessSummary = {
  is_complete: boolean;
  missing_count: number;
  critical_missing_labels: string[];
};

export type MeIdentity = {
  user_id: number;
  username: string;
  ad_soyad: string;
  rol: UserRole | string;
  personel_id: number;
  personel: MePersonelSummary;
  completeness?: MeCompletenessSummary | null;
  last_qr_event?: MeQrAttendanceEvent | null;
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
  /** Authoritative monthly net minutes. Null when the backend has no net figure. */
  net_calisma_dakika_toplam: number | null;
  /** Ay sonu amir onayı (aylik_bildirim_onaylari, state TAMAMLANDI) bu şube/ay için tamamlandıysa true. */
  aylik_onayli_mi?: boolean;
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

export type MeQrLateEarlyInfo = {
  kind: string;
  message: string;
  delta_dakika: number;
};

export type MeQrEarlyExitConfirm = {
  kind: string;
  message: string;
  delta_dakika: number;
};

export type QrScanLocationCapture = {
  available: boolean;
  latitude?: number;
  longitude?: number;
  accuracy_meters?: number | null;
  error_code?: string;
};

export type MeQrScanResponse = {
  event: MeQrAttendanceEvent | null;
  idempotent: boolean;
  confirmation_required?: boolean;
  early_exit_confirm?: MeQrEarlyExitConfirm | null;
  late_early_info?: MeQrLateEarlyInfo | null;
};

export type MeQrHistoryDayEventStatus = {
  kind: string;
  label: string;
  delta_dakika: number;
};

export type MeQrHistoryPendingCorrection = {
  id: number;
  status: string;
  status_label: string;
  requested_local_time: string;
};

export type MeQrHistoryDayEvent = {
  id: number;
  event_type?: "GIRIS" | "CIKIS" | string;
  time: string;
  occurred_at: string;
  status: MeQrHistoryDayEventStatus | null;
  correction_allowed?: boolean;
  pending_correction?: MeQrHistoryPendingCorrection | null;
};

export type MeQrHistoryDay = {
  date: string;
  has_events: boolean;
  /** Chronological full-day timeline (multi-cycle). */
  events?: MeQrHistoryDayEvent[];
  giris: MeQrHistoryDayEvent | null;
  cikis: MeQrHistoryDayEvent | null;
  status_lines: string[];
};

export type MeQrHareketleriResponse = {
  from: string;
  to: string;
  items: MeQrAttendanceEvent[];
  days?: MeQrHistoryDay[];
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

export type ManagerQrLocationEvent = {
  event_type: QrEventType;
  occurred_at: string;
  status_code: string;
  status_label: string;
  distance_meters: number | null;
  accuracy_meters: number | null;
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
  location_events?: ManagerQrLocationEvent[];
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
