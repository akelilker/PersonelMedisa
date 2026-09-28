import type { AttendanceTodayResponse } from "../../../api/attendance-mobile.api";
import {
  hasPlannedShift,
  isGirisSaatiGecti,
  mesaiBitimineKalanLabel,
  mesaiyeKalanLabel
} from "../attendance-shift-countdown";
import { useIstanbulMinuteClock } from "../hooks/useIstanbulMinuteClock";

type AttendanceEvent = NonNullable<AttendanceTodayResponse["giris"]>;

type OwnQrAttendanceBoxesProps = {
  today: Pick<
    AttendanceTodayResponse,
    | "giris"
    | "cikis"
    | "can_scan_giris"
    | "can_scan_cikis"
    | "next_action"
    | "pending_giris_correction"
    | "pending_cikis_correction"
    | "capabilities"
    | "planned_shift"
    | "izinli_bugun"
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

function BoxClock({ event }: { event: AttendanceEvent | null }) {
  if (!event) {
    return null;
  }
  return (
    <p className="pm-box-time">{event.display_local_time ?? event.local_time}</p>
  );
}

function CountdownBlock({ title, value }: { title: string; value: string }) {
  return (
    <>
      <p className="pm-box-countdown-title">{title}</p>
      <p className="pm-box-time" data-testid="pm-box-countdown-value">
        {value}
      </p>
    </>
  );
}

/**
 * Own-day GİRİŞ/ÇIKIŞ kutuları — PERSONEL home ve Mavi Yaka BIRIM_AMIRI home ortak owner.
 * Multi-cycle: kartlar daima görünür; yalnızca backend next_action / can_scan_* actionable yapar.
 * Shift countdown client-side (planned_shift + Istanbul minute clock).
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
  const minuteTick = useIstanbulMinuteClock();
  const now = new Date(minuteTick);
  const planned = today.planned_shift ?? null;
  const shiftKnown = hasPlannedShift(planned);

  const girisActionable = qrEnabled && today.can_scan_giris;
  const cikisActionable = qrEnabled && today.can_scan_cikis;

  const girisCountdown =
    shiftKnown && girisActionable
      ? mesaiyeKalanLabel(planned?.beklenen_giris_saati, now)
      : null;
  const cikisCountdown =
    shiftKnown && cikisActionable
      ? mesaiBitimineKalanLabel(planned?.beklenen_cikis_saati, now)
      : null;

  // İşe geç kalındı (bilgilendirme): bugün giriş yok, planlı giriş saati geçmiş, izinli değil.
  const girisGec =
    !today.giris &&
    isGirisSaatiGecti(planned?.beklenen_giris_saati, now) &&
    !today.izinli_bugun;

  return (
    <div className="pm-attendance-grid" data-testid={testId}>
      <div
        className={[
          "pm-attendance-box",
          girisActionable ? "pm-attendance-box--actionable" : "pm-attendance-box--idle-giris"
        ].join(" ")}
        data-testid="attendance-box-giris"
      >
        {!qrEnabled ? (
          <div className="pm-box-closed" data-testid="giris-scan-not-entitled">
            <p className="pm-box-label">Giriş</p>
            <p className="self-service-muted">QR bu hesap için kapalı</p>
          </div>
        ) : (
          <>
            <button
              type="button"
              className="pm-box-main-action pm-box-main-action--with-time"
              data-testid="giris-scan"
              aria-label="Giriş için kiosk QR okut"
              disabled={!girisActionable}
              onClick={girisActionable ? onScanGiris : undefined}
            >
              <span className="pm-box-label">GİRİŞ</span>
              {girisGec ? (
                <p className="pm-box-late-warning" data-testid="giris-late-warning">
                  İşe Geç Kaldınız.
                </p>
              ) : girisCountdown ? (
                <CountdownBlock title="Mesaiye Kalan Süre" value={girisCountdown} />
              ) : (
                <BoxClock event={today.giris} />
              )}
            </button>
            {!girisActionable && today.giris?.status?.label ? (
              <p className="pm-box-status" data-testid="giris-status-label">
                {today.giris.status.label}
              </p>
            ) : null}
            {!girisActionable && today.pending_giris_correction ? (
              <p className="pm-box-pending">Bekliyor</p>
            ) : null}
            {!girisActionable &&
            canShowCorrection(
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
        )}
      </div>

      <div
        className={[
          "pm-attendance-box",
          cikisActionable ? "pm-attendance-box--actionable" : "pm-attendance-box--idle-cikis"
        ].join(" ")}
        data-testid="attendance-box-cikis"
      >
        {!qrEnabled ? (
          <div className="pm-box-closed" data-testid="cikis-scan-not-entitled">
            <p className="pm-box-label">Çıkış</p>
            <p className="self-service-muted">QR bu hesap için kapalı</p>
          </div>
        ) : (
          <>
            <button
              type="button"
              className="pm-box-main-action pm-box-main-action--with-time"
              data-testid="cikis-scan"
              aria-label="Çıkış için kiosk QR okut"
              disabled={!cikisActionable}
              onClick={cikisActionable ? onScanCikis : undefined}
            >
              <span className="pm-box-label">ÇIKIŞ</span>
              {cikisCountdown ? (
                <CountdownBlock title="Mesai Bitimine Kalan" value={cikisCountdown} />
              ) : (
                <BoxClock event={today.cikis} />
              )}
            </button>
            {!cikisActionable && today.cikis?.status?.label ? (
              <p className="pm-box-status" data-testid="cikis-status-label">
                {today.cikis.status.label}
              </p>
            ) : null}
            {!cikisActionable && today.pending_cikis_correction ? (
              <p className="pm-box-pending">Bekliyor</p>
            ) : null}
            {!cikisActionable &&
            canShowCorrection(
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
        )}
      </div>
    </div>
  );
}
