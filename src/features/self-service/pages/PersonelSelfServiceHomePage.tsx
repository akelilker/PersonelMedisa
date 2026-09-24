import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  ackInboxPopup,
  createAttendanceCorrection,
  decideAttendanceCorrection,
  fetchAttendanceToday,
  fetchInboxNotifications,
  type AttendanceTodayResponse,
  type InboxNotification
} from "../../../api/attendance-mobile.api";
import { fetchMe } from "../../../api/me.api";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { MeIdentity } from "../../../types/self-service";
import { BackgroundlessNoticeModal } from "../components/BackgroundlessNoticeModal";
import { OwnQrAttendanceBoxes } from "../components/OwnQrAttendanceBoxes";
import { QrKioskModelNote } from "../components/QrKioskModelNote";
import { QrPuantajExpectationNote } from "../components/QrPuantajExpectationNote";
import { SelfServiceQrShortcuts } from "../components/SelfServiceQrShortcuts";
import { PersonelMobileCapabilityService } from "../personel-mobile-capability";
import { formatSelfServiceClock, qrEventTypeLabel } from "../self-service-datetime";

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
  // QR/kart okutma yetkisi rol bağımsızdır (bağlı personel + kanonik mavi yaka).
  // Backend otoritedir; bu UX aynasıdır.
  const qrEnabled = hasPermission("self_service.qr.scan");
  const [loading, setLoading] = useState(true);
  const [today, setToday] = useState<AttendanceTodayResponse | null>(null);
  const [identity, setIdentity] = useState<MeIdentity | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [inboxOpen, setInboxOpen] = useState(false);
  const [inboxItems, setInboxItems] = useState<InboxNotification[]>([]);
  const [notice, setNotice] = useState<NoticeState>(null);
  const [correctDraft, setCorrectDraft] = useState<CorrectDraft | null>(null);
  const [correctTime, setCorrectTime] = useState("");
  const [correctBusy, setCorrectBusy] = useState(false);

  const load = useCallback(async () => {
    if (shouldPreferDemoApi()) {
      setLoading(false);
      setError(null);
      setToday(null);
      setIdentity(null);
      return;
    }
    setLoading(true);
    try {
      const [me, attendance, inbox] = await Promise.all([
        fetchMe(),
        fetchAttendanceToday(),
        fetchInboxNotifications()
      ]);
      setIdentity(me);
      setToday(attendance);
      setInboxItems(inbox.items);
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
  const unreadCount = useMemo(
    () => inboxItems.filter((item) => item.popup_required && !item.popup_consumed).length,
    [inboxItems]
  );

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
        <header className="pm-header">
          <div className="pm-header-accent pm-header-accent--left" aria-hidden="true" />
          <div className="pm-header-main">
            <p className="pm-product-title">PERSONEL YÖN. SİST.</p>
            <p className="pm-page-title">ANASAYFA</p>
          </div>
          <div className="pm-header-accent pm-header-accent--right" aria-hidden="true" />
        </header>
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

  const orgLine = [today.personel.sube_ad, today.personel.bolum_ad, today.personel.birim_ad, today.personel.gorev_ad]
    .filter(Boolean)
    .join(" · ");
  const missingCount = identity?.completeness?.missing_count ?? 0;
  const lastQr = identity?.last_qr_event ?? null;
  const lastQrLabel = lastQr
    ? `${qrEventTypeLabel(lastQr.event_type)} — ${formatSelfServiceClock(lastQr.occurred_at)}`
    : null;
  const hasTodayPunch = Boolean(today.giris || today.cikis);
  const incompleteDay =
    qrEnabled && today.giris && !today.cikis
      ? "Bugün giriş kaydınız var; çıkış için şube kiosk QR’ını okutun."
      : null;

  return (
    <section className="personel-mobile-shell" data-testid="personel-self-service-page">
      <header className="pm-header" data-testid="personel-mobile-header">
        <div className="pm-header-accent pm-header-accent--left" aria-hidden="true" />
        <div className="pm-header-main">
          <p className="pm-product-title">PERSONEL YÖN. SİST.</p>
          <p className="pm-page-title">ANASAYFA</p>
          <p className="pm-user-line">{today.personel.ad_soyad}</p>
          {orgLine ? <p className="pm-org-line">{orgLine}</p> : null}
        </div>
        <div className="pm-header-actions">
          <button
            type="button"
            className="pm-bell"
            aria-label="Bildirimler"
            data-testid="personel-notification-bell"
            onClick={() => setInboxOpen((v) => !v)}
          >
            🔔
            {unreadCount > 0 ? <span className="pm-bell-badge">{unreadCount}</span> : null}
          </button>
        </div>
        <div className="pm-header-accent pm-header-accent--right" aria-hidden="true" />
      </header>

      {inboxOpen ? (
        <div className="pm-inbox" data-testid="personel-notification-inbox" role="region" aria-label="Bildirimler">
          {inboxItems.length === 0 ? (
            <p className="self-service-muted">Bildirim yok.</p>
          ) : (
            inboxItems.map((item) => (
              <article key={item.id} className="pm-inbox-item">
                <strong>{item.title}</strong>
                <p>{item.body}</p>
                <span className="pm-inbox-meta">{new Date(item.created_at).toLocaleString("tr-TR")}</span>
              </article>
            ))
          )}
        </div>
      ) : null}

      <section className="pm-section" data-testid="personel-today-attendance-section" aria-labelledby="personel-today-heading">
        <h2 id="personel-today-heading" className="pm-section-title">
          Bugünkü Giriş / Çıkış
        </h2>
        <OwnQrAttendanceBoxes
          today={today}
          qrEnabled={qrEnabled}
          testId="personel-attendance-boxes"
          allowCorrection
          onScanGiris={() =>
            guardOrRun("qr_scan", () => {
              navigate("/self/qr-okut?event=GIRIS");
            })
          }
          onScanCikis={() =>
            guardOrRun("qr_scan", () => {
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
              setCorrectTime(event.display_local_time ?? event.local_time);
            })
          }
          onCorrectCikis={(event) =>
            guardOrRun("attendance_correct", () => {
              setCorrectDraft({
                eventId: event.id,
                eventType: "CIKIS",
                currentTime: event.display_local_time ?? event.local_time
              });
              setCorrectTime(event.display_local_time ?? event.local_time);
            })
          }
        />
        {qrEnabled && !hasTodayPunch ? (
          <p className="self-service-muted" data-testid="personel-today-empty" role="status">
            Bugün henüz giriş/çıkış kaydı yok. Kiosk ekranındaki QR’ı okutarak başlayın.
          </p>
        ) : null}
        {incompleteDay ? (
          <div className="self-service-home__warnings" role="status" data-testid="personel-incomplete-day-warning">
            <p>{incompleteDay}</p>
          </div>
        ) : null}
      </section>

      {qrEnabled ? (
        <>
          <QrKioskModelNote />
          <QrPuantajExpectationNote />
        </>
      ) : (
        <div className="pm-secondary-card" data-testid="personel-qr-closed-notice" role="status">
          <p>
            QR giriş/çıkış bu personel için henüz açık değil. Öz servis özetiniz görüntülenmeye devam eder.
          </p>
        </div>
      )}

      {missingCount > 0 ? (
        <div className="pm-secondary-card" role="status" data-testid="self-missing-info-warning">
          <p>
            Eksik bilgileriniz var ({missingCount}). Profilinizi tamamlamak için yöneticinizle iletişime geçin.
          </p>
        </div>
      ) : null}

      {lastQrLabel ? (
        <div className="pm-secondary-card" data-testid="self-last-qr-event">
          <p className="pm-box-label">Son QR hareketi</p>
          <p>{lastQrLabel}</p>
        </div>
      ) : qrEnabled ? (
        <div className="pm-secondary-card" data-testid="self-last-qr-empty" role="status">
          <p className="pm-box-label">Son QR hareketi</p>
          <p className="self-service-muted">Henüz kayıtlı QR hareketi yok.</p>
        </div>
      ) : null}

      <SelfServiceQrShortcuts />

      <footer className="pm-footer" data-testid="personel-mobile-footer">
        <div className="pm-footer-accent pm-footer-accent--left" aria-hidden="true" />
        <span>PersonelMedisa</span>
        <div className="pm-footer-accent pm-footer-accent--right" aria-hidden="true" />
      </footer>

      {correctDraft ? (
        <BackgroundlessNoticeModal
          open
          title={`${correctDraft.eventType === "GIRIS" ? "Giriş" : "Çıkış"} Düzeltme`}
          body={`Mevcut saat: ${correctDraft.currentTime}. Yeni saati girin.`}
          primaryLabel={correctBusy ? "Gönderiliyor..." : "Gönder"}
          secondaryLabel="Vazgeç"
          onSecondary={() => setCorrectDraft(null)}
          onPrimary={() => {
            if (!correctTime) return;
            void (async () => {
              setCorrectBusy(true);
              try {
                const result = await createAttendanceCorrection({
                  source_event_id: correctDraft.eventId,
                  requested_local_time: correctTime
                });
                setCorrectDraft(null);
                setNotice({
                  title: "Düzeltme Talebi",
                  body: result.message || "Düzeltme talebiniz Yöneticinize iletildi."
                });
                await load();
              } catch (cause) {
                const message =
                  isApiRequestError(cause) && cause.code === "MOBILE_CAPABILITY_PENDING_SCOPE"
                    ? COMING_SOON
                    : "Düzeltme talebi oluşturulamadı. Tekrar deneyin.";
                setNotice({ title: "Düzeltme Talebi", body: message });
              } finally {
                setCorrectBusy(false);
              }
            })();
          }}
          onClose={() => setCorrectDraft(null)}
          testId="attendance-correct-modal"
        >
          <label className="pm-correct-label">
            Yeni saat
            <input
              type="time"
              required
              value={correctTime}
              onChange={(e) => setCorrectTime(e.target.value)}
              data-testid="attendance-correct-time"
            />
          </label>
        </BackgroundlessNoticeModal>
      ) : null}

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
