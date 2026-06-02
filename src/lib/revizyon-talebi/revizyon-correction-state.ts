import type {
  RevizyonCorrectionEvent,
  RevizyonCorrectionHataKodu
} from "../../types/revizyon-correction";
import type { RevizyonTalebi } from "../../types/revizyon-talebi";

export function canProduceCorrection(talep: RevizyonTalebi): boolean {
  return getProduceCorrectionError(talep) === null;
}

export function getProduceCorrectionError(
  talep: RevizyonTalebi
): RevizyonCorrectionHataKodu | null {
  if (talep.durum !== "ONAYLANDI") {
    return "CORRECTION_NOT_ALLOWED_FOR_STATE";
  }

  if (talep.correction_event_id != null) {
    return "CORRECTION_ALREADY_EXISTS";
  }

  return null;
}

export function canCancelCorrection(correction: RevizyonCorrectionEvent): boolean {
  return getCancelCorrectionError(correction) === null;
}

export function getCancelCorrectionError(
  correction: RevizyonCorrectionEvent
): RevizyonCorrectionHataKodu | null {
  if (correction.iptal_edildi_mi) {
    return "CORRECTION_NOT_FOUND";
  }

  return null;
}
