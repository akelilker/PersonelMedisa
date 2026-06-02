import type { RevizyonCorrectionEvent } from "../../types/revizyon-correction";

export function filterActiveCorrections(
  corrections: readonly RevizyonCorrectionEvent[]
): RevizyonCorrectionEvent[] {
  return corrections.filter((correction) => !correction.iptal_edildi_mi);
}

export function getCorrectionOverlayKey(correction: RevizyonCorrectionEvent): string {
  return `${correction.kaynak_tipi}:${correction.kaynak_id}:${correction.etkilenen_tarih}:${correction.correction_tipi}`;
}

export function resolveEffectiveCorrections(
  corrections: readonly RevizyonCorrectionEvent[]
): RevizyonCorrectionEvent[] {
  const active = filterActiveCorrections(corrections);
  const byKey = new Map<string, RevizyonCorrectionEvent>();

  for (const correction of active) {
    const key = getCorrectionOverlayKey(correction);
    const existing = byKey.get(key);

    if (
      !existing ||
      correction.olusturma_zamani.localeCompare(existing.olusturma_zamani) >= 0
    ) {
      byKey.set(key, correction);
    }
  }

  return [...byKey.values()];
}

export function applyCorrectionOverlay<T extends Record<string, unknown>>(
  snapshotSatir: T,
  corrections: readonly RevizyonCorrectionEvent[]
): T & { correction_events: RevizyonCorrectionEvent[] } {
  const effective = resolveEffectiveCorrections(corrections);
  let deltaDakika = 0;

  for (const correction of effective) {
    if (correction.correction_tipi === "BORDRO_ETKI_NOTU") {
      continue;
    }

    deltaDakika += correction.delta_dakika;
  }

  const base = { ...snapshotSatir };
  const toplamNet = base.toplam_net_dakika;
  const next: Record<string, unknown> = { ...base };

  if (typeof toplamNet === "number" && Number.isFinite(toplamNet)) {
    next.toplam_net_dakika = toplamNet + deltaDakika;
  }

  return {
    ...(next as T),
    correction_events: effective
  };
}
