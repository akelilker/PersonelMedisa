import { useEffect, useState } from "react";
import { isApiRequestError } from "../../../api/api-client";
import { createAttendanceCorrection } from "../../../api/attendance-mobile.api";
import { BackgroundlessNoticeModal } from "./BackgroundlessNoticeModal";

const COMING_SOON =
  "Yapım Aşamasındadır. Onay Bekleyen Kapsamlar Tamamlandığında Kullanıma Açılacaktır.";

export type AttendanceCorrectionEventType = "GIRIS" | "CIKIS";

type Props = {
  open: boolean;
  eventId: number;
  eventType: AttendanceCorrectionEventType;
  initialTime: string;
  onClose: () => void;
  onSuccess: (message: string) => void;
  onError: (message: string) => void;
};

function correctionQuestion(eventType: AttendanceCorrectionEventType): string {
  return eventType === "GIRIS"
    ? "Giriş Saatinizle İlgili Düzeltme Talebi Oluşturulsun mu?"
    : "Çıkış Saatinizle İlgili Düzeltme Talebi Oluşturulsun mu?";
}

/**
 * Canonical PERSONEL correction request modal — Home + History share this owner.
 * Raw QR is never edited; only createAttendanceCorrection request is created.
 */
export function AttendanceCorrectionRequestModal({
  open,
  eventId,
  eventType,
  initialTime,
  onClose,
  onSuccess,
  onError
}: Props) {
  const [correctTime, setCorrectTime] = useState(initialTime);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (open) {
      setCorrectTime(initialTime);
      setBusy(false);
    }
  }, [open, initialTime]);

  if (!open) {
    return null;
  }

  return (
    <BackgroundlessNoticeModal
      open
      title="Düzeltme Talebi"
      body={correctionQuestion(eventType)}
      primaryLabel={busy ? "Gönderiliyor..." : "Evet"}
      secondaryLabel="Hayır"
      onSecondary={onClose}
      onPrimary={() => {
        if (!correctTime || busy) return;
        void (async () => {
          setBusy(true);
          try {
            const result = await createAttendanceCorrection({
              source_event_id: eventId,
              requested_local_time: correctTime
            });
            onClose();
            onSuccess(result.message || "Düzeltme Talebiniz Amirinize İletildi.");
          } catch (cause) {
            let message = "Düzeltme talebi oluşturulamadı. Tekrar deneyin.";
            if (isApiRequestError(cause) && cause.code === "MOBILE_CAPABILITY_PENDING_SCOPE") {
              message = COMING_SOON;
            } else if (isApiRequestError(cause) && cause.code === "CORRECTION_WINDOW_CLOSED") {
              message =
                cause.message || "Bu Kayıt İçin Düzeltme Talebi Süresi Doldu. Amirinizle Görüşün.";
            }
            onError(message);
          } finally {
            setBusy(false);
          }
        })();
      }}
      onClose={onClose}
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
  );
}
