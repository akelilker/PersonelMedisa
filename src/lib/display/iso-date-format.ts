type IsoDateParts = { y: number; m: number; d: number };

function parseIsoDateOnly(value: string): IsoDateParts | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  if (!match) {
    return null;
  }

  const y = Number(match[1]);
  const m = Number(match[2]);
  const d = Number(match[3]);
  if (!Number.isFinite(y) || m < 1 || m > 12 || d < 1 || d > 31) {
    return null;
  }

  const utcDate = new Date(Date.UTC(y, m - 1, d));
  if (
    utcDate.getUTCFullYear() !== y ||
    utcDate.getUTCMonth() !== m - 1 ||
    utcDate.getUTCDate() !== d
  ) {
    return null;
  }

  return { y, m, d };
}

/**
 * Görünür takvim tarihi ayırıcısının TEK yeri (kullanıcı kararı: gg/aa/yyyy).
 * Noktalı Türkçe biçime (gg.aa.yyyy) geçmek için yalnız bu sabit "." yapılır.
 */
export const DISPLAY_DATE_SEPARATOR = "/";

/** Formats a YYYY-MM-DD calendar date as zero-padded gg/aa/yyyy (e.g. 15/07/2026). */
export function formatIsoDateDetail(value: string | null | undefined): string {
  if (typeof value !== "string" || !value.trim()) {
    return "-";
  }

  const parts = parseIsoDateOnly(value);
  if (!parts) {
    return "-";
  }

  const dd = String(parts.d).padStart(2, "0");
  const mm = String(parts.m).padStart(2, "0");
  return [dd, mm, String(parts.y)].join(DISPLAY_DATE_SEPARATOR);
}

/**
 * Formats an ISO-8601 instant (e.g. 2026-10-12T00:30:05Z) as Istanbul local
 * gg/aa/yyyy HH:MM:SS using the shared separator.
 */
export function formatIsoDateTimeDetail(value: string | null | undefined): string {
  if (typeof value !== "string" || !value.trim()) {
    return "-";
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "-";
  }
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Istanbul",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hourCycle: "h23"
  }).formatToParts(date);
  const pick = (type: Intl.DateTimeFormatPartTypes) => parts.find((p) => p.type === type)?.value ?? "00";
  const datePart = [pick("day"), pick("month"), pick("year")].join(DISPLAY_DATE_SEPARATOR);
  return `${datePart} ${pick("hour")}:${pick("minute")}:${pick("second")}`;
}

/**
 * Tek görünür tarih/saat biçimleyici (onaylı karar: gg/aa/yyyy).
 * Tarih-only (YYYY-MM-DD…) değerler takvim tarihi olarak, diğerleri İstanbul saatiyle
 * biçimlenir. `time: false` → yalnız tarih; `seconds: true` → HH:MM:SS.
 * Ham tr-TR toLocale*String / sayısal tr-TR Intl biçimlemesi kullanılmaz
 * (gg.aa.yyyy üretir) — tests/unit/ui-regression-guards.test.tsx bunu yakalar.
 */
export function formatDisplayDateTime(
  value: string | number | Date | null | undefined,
  options: { time?: boolean; seconds?: boolean } = {}
): string {
  if (value === null || value === undefined || value === "") {
    return "-";
  }
  if (typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value.trim())) {
    return formatIsoDateDetail(value.trim());
  }
  const date =
    value instanceof Date ? value : new Date(typeof value === "string" ? value.trim().replace(" ", "T") : value);
  if (Number.isNaN(date.getTime())) {
    return typeof value === "string" ? value : "-";
  }
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Istanbul",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hourCycle: "h23"
  }).formatToParts(date);
  const pick = (type: Intl.DateTimeFormatPartTypes) => parts.find((p) => p.type === type)?.value ?? "00";
  const datePart = [pick("day"), pick("month"), pick("year")].join(DISPLAY_DATE_SEPARATOR);
  if (options.time === false) {
    return datePart;
  }
  const timePart = options.seconds ? `${pick("hour")}:${pick("minute")}:${pick("second")}` : `${pick("hour")}:${pick("minute")}`;
  return `${datePart} ${timePart}`;
}

/** Yalnız tarih: gg/aa/yyyy. */
export function formatDisplayDate(value: string | number | Date | null | undefined): string {
  if (typeof value === "string" && /^\d{4}-\d{2}-\d{2}/.test(value.trim()) && value.trim().length <= 10) {
    return formatIsoDateDetail(value.trim().slice(0, 10));
  }
  return formatDisplayDateTime(value, { time: false });
}

/** ISO tarih (yyyy-aa-gg) ise gg/aa/yyyy; değilse metni olduğu gibi döndürür (boş → fallback). */
export function formatDisplayDateOrText(value: string | null | undefined, fallback = "-"): string {
  if (typeof value !== "string" || !value.trim()) return fallback;
  const v = value.trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return formatIsoDateDetail(v);
  if (/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(v)) return formatDisplayDateTime(v);
  if (/^\d{4}-\d{2}$/.test(v)) return formatDisplayMonth(v);
  return v;
}

/** Dönem (yyyy-aa) → aa/yyyy. */
export function formatDisplayMonth(value: string | null | undefined): string {
  if (typeof value !== "string" || !/^\d{4}-\d{2}$/.test(value.trim())) return value?.trim() || "-";
  const [y, m] = value.trim().split("-");
  return `${m}${DISPLAY_DATE_SEPARATOR}${y}`;
}

/** Serbest metin içindeki ISO tarihleri (yyyy-aa-gg) gg/aa/yyyy yapar. */
export function formatIsoDatesInText(value: string | null | undefined): string {
  if (typeof value !== "string") return "";
  return value.replace(/\b(\d{4})-(\d{2})-(\d{2})\b/g, (_m, y, m, d) => `${d}${DISPLAY_DATE_SEPARATOR}${m}${DISPLAY_DATE_SEPARATOR}${y}`);
}
