/** Client-side shift countdown — mirrors LateEarlyInfoService duration + Istanbul wall clock. */

const ISTANBUL = "Europe/Istanbul";

export type PlannedShiftTimes = {
  beklenen_giris_saati: string | null;
  beklenen_cikis_saati: string | null;
};

export function hhmmToMinutes(value: string | null | undefined): number | null {
  if (value == null) {
    return null;
  }
  const trimmed = value.trim();
  const match = /^(\d{1,2}):(\d{2})$/.exec(trimmed);
  if (!match) {
    return null;
  }
  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (!Number.isFinite(hours) || !Number.isFinite(minutes) || minutes < 0 || minutes > 59) {
    return null;
  }
  return hours * 60 + minutes;
}

/** Matches LateEarlyInfoService::formatDurationHuman */
export function formatDurationHuman(minutes: number): string {
  const total = Math.max(0, Math.floor(minutes));
  if (total < 60) {
    return `${total}dk`;
  }
  const hours = Math.floor(total / 60);
  const rem = total % 60;
  if (rem === 0) {
    return `${hours} Saat`;
  }
  return `${hours} Saat ${rem}dk`;
}

export function istanbulWallClockMinutes(at: Date = new Date()): number {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: ISTANBUL,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false
  }).formatToParts(at);
  const hour = Number(parts.find((p) => p.type === "hour")?.value ?? "0");
  const minute = Number(parts.find((p) => p.type === "minute")?.value ?? "0");
  return hour * 60 + minute;
}

export function hasPlannedShift(planned: PlannedShiftTimes | null | undefined): boolean {
  if (!planned) {
    return false;
  }
  return (
    hhmmToMinutes(planned.beklenen_giris_saati) != null ||
    hhmmToMinutes(planned.beklenen_cikis_saati) != null
  );
}

/** Open shift: minutes until planned exit; null when unknown or past. */
export function mesaiBitimineKalanLabel(
  beklenenCikis: string | null | undefined,
  at: Date = new Date()
): string | null {
  const exitMins = hhmmToMinutes(beklenenCikis);
  if (exitMins === null) {
    return null;
  }
  const delta = exitMins - istanbulWallClockMinutes(at);
  if (delta <= 0) {
    return null;
  }
  return formatDurationHuman(delta);
}

/** Before first scan: only within the last 2 hours before planned entry. */
export function mesaiyeKalanLabel(
  beklenenGiris: string | null | undefined,
  at: Date = new Date()
): string | null {
  const entryMins = hhmmToMinutes(beklenenGiris);
  if (entryMins === null) {
    return null;
  }
  const delta = entryMins - istanbulWallClockMinutes(at);
  if (delta <= 0 || delta > 120) {
    return null;
  }
  return formatDurationHuman(delta);
}
