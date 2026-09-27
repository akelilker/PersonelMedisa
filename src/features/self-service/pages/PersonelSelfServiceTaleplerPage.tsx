import { useCallback, useEffect, useState } from "react";
import {
  fetchAttendanceToday,
  type AttendanceBoxEvent,
  type AttendanceTodayResponse
} from "../../../api/attendance-mobile.api";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { LoadingState } from "../../../components/states/LoadingState";
import { AttendanceCorrectionRequestModal } from "../components/AttendanceCorrectionRequestModal";
import { BackgroundlessNoticeModal } from "../components/BackgroundlessNoticeModal";
import { PersonelMobileCapabilityService } from "../personel-mobile-capability";

type CorrectDraft = {
  eventId: number;
  eventType: "GIRIS" | "CIKIS";
  currentTime: string;
};

type SoonItem = {
  id: string;
  label: string;
  testId: string;
};

const SOON_ITEMS: SoonItem[] = [
  { id: "izin", label: "İzin Talebi", testId: "personel-talep-izin" },
  { id: "avans", label: "Avans Talebi", testId: "personel-talep-avans" },
  { id: "oneri", label: "Öneri / Şikâyet", testId: "personel-talep-oneri" }
];

function correctionTarget(
  event: AttendanceBoxEvent | null,
  pending: boolean
): AttendanceBoxEvent | null {
  if (!event || pending || event.correction_allowed !== true) {
    return null;
  }
  return event;
}

export function PersonelSelfServiceTaleplerPage() {
  const [loading, setLoading] = useState(true);
  const [today, setToday] = useState<AttendanceTodayResponse | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [correctDraft, setCorrectDraft] = useState<CorrectDraft | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (shouldPreferDemoApi()) {
      setLoading(false);
      setToday(null);
      setLoadError("Demo modda düzeltme talebi yok.");
      return;
    }
    setLoading(true);
    try {
      setToday(await fetchAttendanceToday());
      setLoadError(null);
    } catch (cause) {
      setToday(null);
      setLoadError(
        isApiRequestError(cause) && cause.code === "SELF_SERVICE_BINDING_REQUIRED"
          ? "Personel bağlantınız yok."
          : "Talepler yüklenemedi. Tekrar deneyin."
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const comingSoon =
    today?.capabilities.coming_soon_message ?? PersonelMobileCapabilityService.MESSAGE_COMING_SOON;
  const correctionEnabled = today?.capabilities.attendance_correct === true;
  const girisTarget = today
    ? correctionTarget(today.giris, today.pending_giris_correction != null)
    : null;
  const cikisTarget = today
    ? correctionTarget(today.cikis, today.pending_cikis_correction != null)
    : null;

  function openCorrection(event: AttendanceBoxEvent) {
    if (!correctionEnabled) {
      setNotice(comingSoon);
      return;
    }
    setCorrectDraft({
      eventId: event.id,
      eventType: event.event_type,
      currentTime: event.display_local_time ?? event.local_time
    });
  }

  return (
    <section className="personel-mobile-shell pm-self-subpage" data-testid="personel-talepler-page">
      <ul className="pm-self-request-list">
        <li>
          <div className="pm-self-request" data-testid="personel-talep-duzeltme">
            <p className="pm-self-request__title">Giriş/Çıkış Düzeltme Talebi</p>
            {loading ? <LoadingState label="Kayıtlar yükleniyor..." /> : null}
            {!loading && loadError ? <p className="self-service-muted">{loadError}</p> : null}
            {!loading && today && !correctionEnabled ? (
              <p className="self-service-muted">{comingSoon}</p>
            ) : null}
            {!loading && today && correctionEnabled && !girisTarget && !cikisTarget ? (
              <p className="self-service-muted" data-testid="personel-talep-duzeltme-empty">
                Düzeltilebilecek bir giriş veya çıkış kaydı yok.
              </p>
            ) : null}
            {!loading && today && correctionEnabled && (girisTarget || cikisTarget) ? (
              <div className="pm-self-request__actions">
                {girisTarget ? (
                  <button
                    type="button"
                    className="self-service-action"
                    data-testid="personel-talep-duzeltme-giris"
                    onClick={() => openCorrection(girisTarget)}
                  >
                    Giriş düzeltme talebi
                  </button>
                ) : null}
                {cikisTarget ? (
                  <button
                    type="button"
                    className="self-service-action"
                    data-testid="personel-talep-duzeltme-cikis"
                    onClick={() => openCorrection(cikisTarget)}
                  >
                    Çıkış düzeltme talebi
                  </button>
                ) : null}
              </div>
            ) : null}
          </div>
        </li>
        {SOON_ITEMS.map((item) => (
          <li key={item.id}>
            <button
              type="button"
              className="pm-self-request pm-self-request--soon"
              data-testid={item.testId}
              disabled
              aria-disabled="true"
            >
              <span>{item.label}</span>
              <span className="pm-self-request__soon">Yakında</span>
            </button>
          </li>
        ))}
      </ul>

      <AttendanceCorrectionRequestModal
        open={correctDraft !== null}
        eventId={correctDraft?.eventId ?? 0}
        eventType={correctDraft?.eventType ?? "GIRIS"}
        initialTime={correctDraft?.currentTime ?? ""}
        onClose={() => setCorrectDraft(null)}
        onSuccess={(message) => {
          setNotice(message);
          void load();
        }}
        onError={(message) => setNotice(message)}
      />

      <BackgroundlessNoticeModal
        open={notice !== null}
        title="Talep"
        body={notice ?? ""}
        onClose={() => setNotice(null)}
        testId="personel-talep-notice"
      />
    </section>
  );
}
