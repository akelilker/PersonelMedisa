import { describe, expect, it } from "vitest";
import { resolveInboxNotificationDestination } from "../../src/lib/self-service/inbox-notification-destination";
import type { InboxNotification } from "../../src/api/attendance-mobile.api";

function inbox(partial: Partial<InboxNotification> & Pick<InboxNotification, "kind">): InboxNotification {
  return {
    id: 1,
    title: "t",
    body: "b",
    payload: null,
    related_correction_id: null,
    popup_required: false,
    popup_consumed: false,
    created_at: "2026-01-01T00:00:00Z",
    ...partial
  };
}

describe("resolveInboxNotificationDestination", () => {
  it("routes correction requests to self with correction query", () => {
    const path = resolveInboxNotificationDestination(
      inbox({
        kind: "ATTENDANCE_CORRECTION_REQUEST",
        related_correction_id: 42
      })
    );
    expect(path).toBe("/self?inboxCorrection=42");
  });

  it("routes izin requests to surec detail", () => {
    const path = resolveInboxNotificationDestination(
      inbox({
        kind: "SELF_IZIN_REQUEST",
        payload: { entity_type: "IZIN", surec_id: 9, personel_id: 3 }
      })
    );
    expect(path).toBe("/surecler/9");
  });

  it("routes avans requests to personel card fallback", () => {
    const path = resolveInboxNotificationDestination(
      inbox({
        kind: "SELF_AVANS_REQUEST",
        payload: { entity_type: "AVANS", entity_id: 5, personel_id: 3 }
      })
    );
    expect(path).toBe("/personeller/3");
  });

  it("falls back to /self for unknown kinds", () => {
    expect(resolveInboxNotificationDestination(inbox({ kind: "LATE_ENTRY_INFO" }))).toBe("/self");
  });
});
