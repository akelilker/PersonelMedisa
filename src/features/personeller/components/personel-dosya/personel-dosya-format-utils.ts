export { formatIsoDateDetail } from "../../../../lib/display/iso-date-format";

export function formatDetailValue(value: string | null | undefined) {
  if (typeof value !== "string") {
    return "-";
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : "-";
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
    return "—";
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

export function formatReferenceValue(
  label?: string | null,
  id?: number | null,
  kisaKod?: string | null
) {
  const name = typeof label === "string" ? label.trim() : "";
  const code = typeof kisaKod === "string" ? kisaKod.trim() : "";

  if (code && name) {
    return `${code} — ${name}`;
  }

  if (name) {
    return name;
  }

  // Numeric DB ids stay internal — never surface as user-facing labels.
  void id;
  return "-";
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
