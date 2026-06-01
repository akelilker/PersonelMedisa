import type { RevizyonHataKodu, RevizyonTalebiDurumu } from "../../types/revizyon-talebi";

const ALLOWED_TRANSITIONS: Record<RevizyonTalebiDurumu, readonly RevizyonTalebiDurumu[]> = {
  TASLAK: ["ONAY_BEKLIYOR", "IPTAL"],
  ONAY_BEKLIYOR: ["ONAYLANDI", "REDDEDILDI", "IPTAL"],
  ONAYLANDI: [],
  REDDEDILDI: [],
  IPTAL: []
};

export function isAllowedRevizyonTransition(
  from: RevizyonTalebiDurumu,
  to: RevizyonTalebiDurumu
): boolean {
  return ALLOWED_TRANSITIONS[from].includes(to);
}

export function getRevizyonTransitionError(
  from: RevizyonTalebiDurumu,
  to: RevizyonTalebiDurumu
): RevizyonHataKodu | null {
  if (isAllowedRevizyonTransition(from, to)) {
    return null;
  }

  return "INVALID_STATE_TRANSITION";
}

export function assertRevizyonTransition(
  from: RevizyonTalebiDurumu,
  to: RevizyonTalebiDurumu
): { ok: true } | { ok: false; code: "INVALID_STATE_TRANSITION" } {
  if (!isAllowedRevizyonTransition(from, to)) {
    return { ok: false, code: "INVALID_STATE_TRANSITION" };
  }

  return { ok: true };
}
