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
