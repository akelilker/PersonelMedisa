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

export function formatIsoDateDetail(value: string | null | undefined): string {
  if (typeof value !== "string" || !value.trim()) {
    return "-";
  }

  const parts = parseIsoDateOnly(value);
  if (!parts) {
    return "-";
  }

  return new Intl.DateTimeFormat("tr-TR", { dateStyle: "short", timeZone: "UTC" }).format(
    new Date(Date.UTC(parts.y, parts.m - 1, parts.d))
  );
}

export function formatDetailValue(value: string | null | undefined) {
  if (typeof value !== "string") {
    return "-";
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : "-";
}

export function formatDetailNumber(value: number | null | undefined) {
  return typeof value === "number" ? String(value) : "-";
}

export function formatNullableScalar(value: string | number | boolean | null | undefined | object) {
  if (value === null || value === undefined) {
    return "-";
  }
  if (typeof value === "boolean") {
    return value ? "Evet" : "Hayır";
  }
  if (typeof value === "number") {
    return String(value);
  }
  if (typeof value === "string") {
    return formatDetailValue(value);
  }
  if (typeof value === "object") {
    return JSON.stringify(value);
  }
  return "-";
}

export function formatDateTimeDetail(value: string | null | undefined) {
  const fallback = formatDetailValue(value ?? undefined);
  if (fallback === "-") {
    return fallback;
  }

  const parsed = Date.parse(fallback);
  if (!Number.isFinite(parsed)) {
    return fallback;
  }

  return new Intl.DateTimeFormat("tr-TR", {
    dateStyle: "short",
    timeStyle: "short"
  }).format(new Date(parsed));
}

export function timestampValue(value: string | null | undefined) {
  if (!value) {
    return 0;
  }

  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

export function formatReferenceValue(label?: string, id?: number) {
  if (label) {
    return label;
  }

  return typeof id === "number" ? `#${id}` : "-";
}

export function formatSgkHesaplamaModuLabel(value?: string) {
  if (value === "OTUZ_GUN_STANDART") {
    return "30 gün standart";
  }

  if (value === "TAKVIM_GUNU") {
    return "Takvim günü";
  }

  return "-";
}
