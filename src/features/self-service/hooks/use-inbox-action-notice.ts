import {
  ackInboxPopup,
  decideAttendanceCorrection,
  type InboxNotification
} from "../../../api/attendance-mobile.api";

type InboxActionNotice = {
  title: string;
  body: string;
  infoTooltip?: string;
  primaryLabel?: string;
  secondaryLabel?: string;
  onPrimary?: () => void | Promise<void>;
  onSecondary?: () => void | Promise<void>;
  notificationId?: number;
  kind?: string;
};

export function buildInboxActionNotice(
  popup: InboxNotification,
  reload: () => Promise<void>
): InboxActionNotice {
  const isCorrection =
    popup.kind === "ATTENDANCE_CORRECTION_REQUEST" || popup.kind === "ATTENDANCE_CORRECTION_REMINDER";
  const correctionId = popup.related_correction_id;

  return {
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
            await reload();
          }
        : undefined,
    onSecondary:
      isCorrection && correctionId
        ? async () => {
            await decideAttendanceCorrection(correctionId, "REDDET");
            await ackInboxPopup(popup.id);
            await reload();
          }
        : undefined
  };
}
