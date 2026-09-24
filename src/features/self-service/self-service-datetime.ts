/** Shared Istanbul clock formatting for personel self-service surfaces. */

const TIME_FMT: Intl.DateTimeFormatOptions = {
  timeZone: "Europe/Istanbul",
  hour: "2-digit",
  minute: "2-digit"
};

const DATETIME_FMT: Intl.DateTimeFormatOptions = {
  timeZone: "Europe/Istanbul",
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
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

export function formatSelfServiceDateTime(iso: string): string {
  try {
    return new Intl.DateTimeFormat("tr-TR", DATETIME_FMT).format(new Date(iso));
  } catch {
    return new Date(iso).toLocaleString("tr-TR");
  }
}

export function qrEventTypeLabel(eventType: "GIRIS" | "CIKIS" | string): string {
  return eventType === "GIRIS" ? "GİRİŞ" : eventType === "CIKIS" ? "ÇIKIŞ" : eventType;
}
