import type { InboxNotification } from "../../api/attendance-mobile.api";

function readEntityId(notification: InboxNotification): number | null {
  const payload = notification.payload;
  if (!payload) {
    return null;
  }
  const raw =
    payload.entity_id ?? payload.surec_id ?? payload.correction_id ?? notification.related_correction_id;
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : null;
}

function readPersonelId(notification: InboxNotification): number | null {
  const payload = notification.payload;
  const raw = payload?.personel_id;
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : null;
}

/**
 * Canonical in-app destination for personel/manager inbox notifications.
 * Safe fallback: /self for bound self-service users, otherwise home.
 */
export function resolveInboxNotificationDestination(
  notification: InboxNotification,
  options?: { selfServiceHome?: boolean }
): string {
  const selfHome = options?.selfServiceHome ?? true;
  const fallback = selfHome ? "/self" : "/";
  const kind = notification.kind;
  const entityId = readEntityId(notification);
  const personelId = readPersonelId(notification);

  if (
    kind === "ATTENDANCE_CORRECTION_REQUEST" ||
    kind === "ATTENDANCE_CORRECTION_REMINDER"
  ) {
    const correctionId = notification.related_correction_id ?? entityId;
    if (correctionId) {
      return `/self?inboxCorrection=${correctionId}`;
    }
    return fallback;
  }

  if (kind === "ATTENDANCE_CORRECTION_APPROVED" || kind === "ATTENDANCE_CORRECTION_REJECTED") {
    const correctionId = notification.related_correction_id ?? entityId;
    if (correctionId) {
      return `/self/talepler?correctionId=${correctionId}`;
    }
    return fallback;
  }

  const entityType = String(notification.payload?.entity_type ?? "").toUpperCase();

  if (kind === "SELF_IZIN_REQUEST" || entityType === "IZIN") {
    const surecId = Number(notification.payload?.surec_id ?? entityId);
    if (Number.isFinite(surecId) && surecId > 0) {
      return `/surecler/${surecId}`;
    }
  }

  if (kind === "SELF_RAPOR_REQUEST" || entityType === "RAPOR") {
    const surecId = Number(notification.payload?.surec_id ?? entityId);
    if (Number.isFinite(surecId) && surecId > 0) {
      return `/surecler/${surecId}`;
    }
  }

  if (kind === "SELF_AVANS_REQUEST" || entityType === "AVANS") {
    if (personelId) {
      return `/personeller/${personelId}`;
    }
  }

  return fallback;
}
