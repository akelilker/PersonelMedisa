/** Shared Istanbul clock formatting for personel self-service surfaces. */

const TIME_FMT: Intl.DateTimeFormatOptions = {
  timeZone: "Europe/Istanbul",
  hour: "2-digit",
  minute: "2-digit"
};

export function formatSelfServiceClock(iso: string): string {
  try {
    return new Intl.DateTimeFormat("tr-TR", TIME_FMT).format(new Date(iso));
  } catch {
    return new Date(iso).toLocaleTimeString("tr-TR", { hour: "2-digit", minute: "2-digit" });
  }
}
