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

/** Formats a YYYY-MM-DD calendar date as zero-padded tr-TR (e.g. 15.07.2026). */
export function formatIsoDateDetail(value: string | null | undefined): string {
  if (typeof value !== "string" || !value.trim()) {
    return "-";
  }

  const parts = parseIsoDateOnly(value);
  if (!parts) {
    return "-";
  }

  return new Intl.DateTimeFormat("tr-TR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    timeZone: "UTC"
  }).format(new Date(Date.UTC(parts.y, parts.m - 1, parts.d)));
}
