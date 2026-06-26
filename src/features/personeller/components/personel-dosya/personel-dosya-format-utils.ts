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

export function formatNullableScalar(value: string | number | boolean | null | undefined) {
  if (typeof value === "boolean") {
    return value ? "Evet" : "Hayır";
  }
  if (typeof value === "number") {
    return String(value);
  }
  if (typeof value === "string") {
    return formatDetailValue(value);
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
