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

function PencilIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
    </svg>
  );
}

function canShowCorrection(
  allowCorrection: boolean,
  pending: boolean,
  event: AttendanceEvent | null,
  onCorrect?: (event: AttendanceEvent) => void
): event is AttendanceEvent {
  return (
    allowCorrection &&
    !pending &&
    event != null &&
    event.correction_allowed === true &&
    onCorrect != null
  );
}

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
            <p className="pm-box-label">GİRİŞ</p>
            <p className="pm-box-time">{today.giris.display_local_time ?? today.giris.local_time}</p>
            {today.giris.status?.label ? (
              <p className="pm-box-status" data-testid="giris-status-label">
                {today.giris.status.label}
              </p>
            ) : null}
            {today.pending_giris_correction ? (
              <p className="pm-box-pending">Bekliyor</p>
            ) : null}
            {canShowCorrection(
              allowCorrection,
              Boolean(today.pending_giris_correction),
              today.giris,
              onCorrectGiris
            ) ? (
              <button
                type="button"
                className="pm-box-pencil"
                data-testid="giris-duzelt"
                aria-label="Giriş Saati Düzeltme Talebi"
                onClick={() => onCorrectGiris!(today.giris!)}
              >
                <PencilIcon />
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
            <p className="pm-box-label">ÇIKIŞ</p>
            <p className="pm-box-time">{today.cikis.display_local_time ?? today.cikis.local_time}</p>
            {today.cikis.status?.label ? (
              <p className="pm-box-status" data-testid="cikis-status-label">
                {today.cikis.status.label}
              </p>
            ) : null}
            {today.pending_cikis_correction ? (
              <p className="pm-box-pending">Bekliyor</p>
            ) : null}
            {canShowCorrection(
              allowCorrection,
              Boolean(today.pending_cikis_correction),
              today.cikis,
              onCorrectCikis
            ) ? (
              <button
                type="button"
                className="pm-box-pencil"
                data-testid="cikis-duzelt"
                aria-label="Çıkış Saati Düzeltme Talebi"
                onClick={() => onCorrectCikis!(today.cikis!)}
              >
                <PencilIcon />
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
