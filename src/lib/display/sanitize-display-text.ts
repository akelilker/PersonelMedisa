export function looksLikeRawDisplayLeak(value: string): boolean {
  const trimmed = value.trim();
  if (!trimmed) {
    return true;
  }

  if (trimmed.includes("[object Object]")) {
    return true;
  }

  if (trimmed.startsWith("{") || trimmed.startsWith("[")) {
    return true;
  }

  if (/"tip"\s*:/.test(trimmed)) {
    return true;
  }

  if (/"_personel_belge_kaydi"\s*:/.test(trimmed)) {
    return true;
  }

  return false;
}

export function sanitizeDisplayText(value: unknown): string {
  if (typeof value !== "string") {
    return "-";
  }

  const trimmed = value.trim();
  if (!trimmed || looksLikeRawDisplayLeak(trimmed)) {
    return "-";
  }

  return trimmed;
}
