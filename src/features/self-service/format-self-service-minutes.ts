/**
 * Display-only dakika → saat/dakika. Does not recompute overtime balances.
 */
export function formatSelfServiceMinutes(totalMinutes: number): string {
  if (!Number.isFinite(totalMinutes)) {
    return "-";
  }
  const sign = totalMinutes < 0 ? "-" : "";
  const abs = Math.abs(Math.trunc(totalMinutes));
  const hours = Math.floor(abs / 60);
  const minutes = abs % 60;
  if (hours === 0) {
    return `${sign}${minutes} dk`;
  }
  if (minutes === 0) {
    return `${sign}${hours} saat`;
  }
  return `${sign}${hours} saat ${minutes} dk`;
}
