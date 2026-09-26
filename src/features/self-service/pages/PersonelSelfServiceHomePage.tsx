import { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  ackInboxPopup,
  decideAttendanceCorrection,
  fetchAttendanceToday,
  fetchInboxNotifications,
  type AttendanceTodayResponse
} from "../../../api/attendance-mobile.api";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { AttendanceCorrectionRequestModal } from "../components/AttendanceCorrectionRequestModal";
import { BackgroundlessNoticeModal } from "../components/BackgroundlessNoticeModal";
import { OwnQrAttendanceBoxes } from "../components/OwnQrAttendanceBoxes";
import { PersonelMobileCapabilityService } from "../personel-mobile-capability";
import { primeQrCamera } from "../qr/qr-scanner";

type NoticeState =
  | null
  | {
      title: string;
      body: string;
      infoTooltip?: string;
      primaryLabel?: string;
      secondaryLabel?: string;
      onPrimary?: () => void;
      onSecondary?: () => void;
      notificationId?: number;
      kind?: string;
    };

type CorrectDraft = {
  eventId: number;
  eventType: "GIRIS" | "CIKIS";
  currentTime: string;
};

const COMING_SOON = PersonelMobileCapabilityService.MESSAGE_COMING_SOON;

export function PersonelSelfServiceHomePage() {
  const navigate = useNavigate();
  const { hasPermission } = useRoleAccess();
  const qrEnabled = hasPermission("self_service.qr.scan");
  const [loading, setLoading] = useState(true);
  const [today, setToday] = useState<AttendanceTodayResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<NoticeState>(null);
  const [correctDraft, setCorrectDraft] = useState<CorrectDraft | null>(null);

  const load = useCallback(async () => {
    if (shouldPreferDemoApi()) {
      setLoading(false);
      setError(null);
      setToday(null);
      return;
    }
    setLoading(true);
    try {
      const [attendance, inbox] = await Promise.all([
        fetchAttendanceToday(),
        fetchInboxNotifications()
      ]);
      setToday(attendance);
      const popup = inbox.pending_popups[0] ?? null;
      if (popup) {
        const isCorrection =
          popup.kind === "ATTENDANCE_CORRECTION_REQUEST" || popup.kind === "ATTENDANCE_CORRECTION_REMINDER";
        const correctionId = popup.related_correction_id;
        setNotice({
          title: popup.title,
          body: popup.body,
          infoTooltip:
            popup.kind === "LATE_ENTRY_INFO" || popup.kind === "EARLY_EXIT_INFO" ? "Bilgi Amaçlıdır." : undefined,
          notificationId: popup.id,
          kind: popup.kind,
          primaryLabel: isCorrection ? "Onayla" : undefined,
          secondaryLabel: isCorrection ? "Reddet" : undefined,
          onPrimary:
            isCorrection && correctionId
              ? async () => {
                  await decideAttendanceCorrection(correctionId, "ONAYLA");
                  await ackInboxPopup(popup.id);
                  setNotice(null);
                  await load();
                }
              : undefined,
          onSecondary:
            isCorrection && correctionId
              ? async () => {
                  await decideAttendanceCorrection(correctionId, "REDDET");
                  await ackInboxPopup(popup.id);
                  setNotice(null);
                  await load();
                }
              : undefined
        });
      }
      setError(null);
    } catch (cause) {
      if (isApiRequestError(cause) && cause.code === "SELF_SERVICE_BINDING_REQUIRED") {
        setError("unbound");
      } else if (isApiRequestError(cause) && cause.code === "SELF_SERVICE_PERSONEL_INACTIVE") {
        setError("inactive");
      } else {
        setError("Özet yüklenemedi. Tekrar deneyin.");
      }
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const caps = today?.capabilities;
  const comingSoon = caps?.coming_soon_message ?? COMING_SOON;

  function guardOrRun(capability: "qr_scan" | "attendance_correct", action: () => void) {
    if (!caps || !caps[capability]) {
      setNotice({ title: "Bilgi", body: comingSoon });
      return;
    }
    action();
  }

  async function closeNotice() {
    if (notice?.notificationId) {
      try {
        await ackInboxPopup(notice.notificationId);
      } catch {
        // keep closing
      }
    }
    setNotice(null);
    void load();
  }

  if (loading) {
    return <LoadingState label="Personel paneli yükleniyor..." />;
  }

  if (shouldPreferDemoApi()) {
    return (
      <section className="personel-mobile-shell" data-testid="personel-self-service-page">
        <p className="self-service-muted">Demo modda personel eşlemesi yok.</p>
      </section>
    );
  }

  if (error === "unbound") {
    return (
      <section className="states-page" data-testid="personel-unbound-page">
        <h2>Personel bağlantısı yok</h2>
        <p>
          Hesabınız bir personel kaydına bağlı değil. QR giriş/çıkış ve öz servis özeti bu yüzden
          kapalıdır. Yöneticiniz hesabınızı bağladıktan sonra bu ekran açılır.
        </p>
      </section>
    );
  }

  if (error === "inactive") {
    return (
      <section className="states-page" data-testid="personel-inactive-page">
        <h2>Personel hesabınız aktif değil</h2>
        <p>Aktif personel kaydı olmadan öz servis özeti ve QR giriş/çıkış kullanılamaz.</p>
      </section>
    );
  }

  if (error || !today) {
    return (
      <section className="states-page state-error" data-testid="personel-self-service-error">
        <h2>Özet yüklenemedi</h2>
        <p>{error ?? "Özet yüklenemedi. Tekrar deneyin."}</p>
        <button type="button" className="self-service-action" onClick={() => void load()}>
          Tekrar dene
        </button>
      </section>
    );
  }

  return (
    <section className="personel-mobile-shell self-home-page" data-testid="personel-self-service-page">
      <section className="pm-section" data-testid="personel-today-attendance-section">
        <OwnQrAttendanceBoxes
          today={today}
          qrEnabled={qrEnabled}
          testId="personel-attendance-boxes"
          allowCorrection
          onScanGiris={() =>
            guardOrRun("qr_scan", () => {
              primeQrCamera();
              navigate("/self/qr-okut?event=GIRIS");
            })
          }
          onScanCikis={() =>
            guardOrRun("qr_scan", () => {
              primeQrCamera();
              navigate("/self/qr-okut?event=CIKIS");
            })
          }
          onCorrectGiris={(event) =>
            guardOrRun("attendance_correct", () => {
              setCorrectDraft({
                eventId: event.id,
                eventType: "GIRIS",
                currentTime: event.display_local_time ?? event.local_time
              });
            })
          }
          onCorrectCikis={(event) =>
            guardOrRun("attendance_correct", () => {
              setCorrectDraft({
                eventId: event.id,
                eventType: "CIKIS",
                currentTime: event.display_local_time ?? event.local_time
              });
            })
          }
        />
      </section>

      {!qrEnabled ? (
        <div className="pm-callout" data-testid="personel-qr-closed-notice" role="status">
          <p>
            QR giriş/çıkış bu personel için henüz açık değil. Öz servis özetiniz görüntülenmeye devam eder.
          </p>
        </div>
      ) : null}

      <AttendanceCorrectionRequestModal
        open={correctDraft !== null}
        eventId={correctDraft?.eventId ?? 0}
        eventType={correctDraft?.eventType ?? "GIRIS"}
        initialTime={correctDraft?.currentTime ?? ""}
        onClose={() => setCorrectDraft(null)}
        onSuccess={(message) => {
          setNotice({ title: "Düzeltme Talebi", body: message });
          void load();
        }}
        onError={(message) => {
          setNotice({ title: "Düzeltme Talebi", body: message });
        }}
      />

      <BackgroundlessNoticeModal
        open={notice !== null}
        title={notice?.title ?? ""}
        body={notice?.body ?? ""}
        infoTooltip={notice?.infoTooltip}
        primaryLabel={notice?.primaryLabel}
        secondaryLabel={notice?.secondaryLabel}
        onPrimary={
          notice?.onPrimary
            ? () => {
                void Promise.resolve(notice.onPrimary?.()).catch(() => undefined);
              }
            : undefined
        }
        onSecondary={
          notice?.onSecondary
            ? () => {
                void Promise.resolve(notice.onSecondary?.()).catch(() => undefined);
              }
            : undefined
        }
        onClose={() => void closeNotice()}
        testId="personel-notice-modal"
      />
    </section>
  );
}
