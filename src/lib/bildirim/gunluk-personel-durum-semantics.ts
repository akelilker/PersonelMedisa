/** Shared daily person status evidence gates (IK dashboard + BIRIM_AMIRI home). */

export function parseHhMmToMinutes(value: string | null | undefined): number | null {
  if (!value) return null;
  const m = /^(\d{1,2}):(\d{2})/.exec(value.trim());
  if (!m) return null;
  const hour = Number(m[1]);
  const minute = Number(m[2]);
  if (hour > 23 || minute > 59) return null;
  return hour * 60 + minute;
}

export function resolveLateMinutes(
  girisSaati: string | null | undefined,
  storedDakika: number | null | undefined = null
): number | null {
  if (storedDakika != null && storedDakika > 0) {
    return Math.trunc(storedDakika);
  }
  const entry = parseHhMmToMinutes(girisSaati);
  const start = parseHhMmToMinutes("08:30");
  if (entry == null || start == null) return null;
  const diff = entry - start;
  return diff > 0 ? diff : null;
}
