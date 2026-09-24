import type { AttendanceTodayResponse } from "../../../api/attendance-mobile.api";

type AttendanceEvent = NonNullable<AttendanceTodayResponse["giris"]>;

type OwnQrAttendanceBoxesProps = {
  today: Pick<
    AttendanceTodayResponse,
    | "giris"
    | "cikis"
    | "can_scan_giris"
    | "can_scan_cikis"
    | "pending_giris_correction"
    | "pending_cikis_correction"
    | "capabilities"
  >;
  /** Role-independent QR entitlement mirror (`self_service.qr.scan`). */
  qrEnabled: boolean;
  testId?: string;
  /** When false, omit Düzelt actions (display + scan CTAs only). */
  allowCorrection?: boolean;
  onScanGiris: () => void;
  onScanCikis: () => void;
  onCorrectGiris?: (event: AttendanceEvent) => void;
  onCorrectCikis?: (event: AttendanceEvent) => void;
};

/**
 * Own-day GİRİŞ/ÇIKIŞ kutuları — PERSONEL home ve Mavi Yaka BIRIM_AMIRI home ortak owner.
 * Düzeltme opsiyonel; yönetim rolü / route bu bileşenden etkilenmez.
 */
export function OwnQrAttendanceBoxes({
  today,
  qrEnabled,
  testId = "own-attendance-boxes",
  allowCorrection = false,
  onScanGiris,
  onScanCikis,
  onCorrectGiris,
  onCorrectCikis
}: OwnQrAttendanceBoxesProps) {
  const capsQr = Boolean(today.capabilities?.qr_scan);

  return (
    <div className="pm-attendance-grid" data-testid={testId}>
      <div className="pm-attendance-box" data-testid="attendance-box-giris">
        {today.giris ? (
          <>
            <p className="pm-box-label">Giriş Saati</p>
            <p className="pm-box-time">{today.giris.display_local_time ?? today.giris.local_time}</p>
            {today.pending_giris_correction ? (
              <p className="pm-box-pending">Bekliyor</p>
            ) : allowCorrection && onCorrectGiris ? (
              <button
                type="button"
                className="pm-box-action"
                data-testid="giris-duzelt"
                onClick={() => onCorrectGiris(today.giris!)}
              >
                Düzelt
              </button>
            ) : null}
          </>
        ) : qrEnabled ? (
          <button
            type="button"
            className="pm-box-main-action"
            data-testid="giris-scan"
            aria-label="Giriş için kiosk QR okut"
            onClick={onScanGiris}
          >
            GİRİŞ
          </button>
        ) : (
          <div className="pm-box-closed" data-testid="giris-scan-not-entitled">
            <p className="pm-box-label">Giriş</p>
            <p className="self-service-muted">QR bu hesap için kapalı</p>
          </div>
        )}
      </div>

      <div className="pm-attendance-box" data-testid="attendance-box-cikis">
        {today.cikis ? (
          <>
            <p className="pm-box-label">Çıkış Saati</p>
            <p className="pm-box-time">{today.cikis.display_local_time ?? today.cikis.local_time}</p>
            {today.pending_cikis_correction ? (
              <p className="pm-box-pending">Bekliyor</p>
            ) : allowCorrection && onCorrectCikis ? (
              <button
                type="button"
                className="pm-box-action"
                data-testid="cikis-duzelt"
                onClick={() => onCorrectCikis(today.cikis!)}
              >
                Düzelt
              </button>
            ) : null}
          </>
        ) : qrEnabled ? (
          <button
            type="button"
            className="pm-box-main-action"
            data-testid="cikis-scan"
            aria-label="Çıkış için kiosk QR okut"
            disabled={!today.can_scan_cikis && capsQr}
            onClick={onScanCikis}
          >
            ÇIKIŞ
          </button>
        ) : (
          <div className="pm-box-closed" data-testid="cikis-scan-not-entitled">
            <p className="pm-box-label">Çıkış</p>
            <p className="self-service-muted">QR bu hesap için kapalı</p>
          </div>
        )}
      </div>
    </div>
  );
}
