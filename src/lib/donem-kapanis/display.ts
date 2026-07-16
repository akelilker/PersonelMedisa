import type { DonemKapanisSeverity } from "../../api/donem-kapanis.api";

export const SEVERITY_LABELS: Record<DonemKapanisSeverity, string> = {
  BLOCKER: "Engelleyici",
  WARNING: "Uyarı",
  INFO: "Bilgi"
};

export const SEVERITY_ICONS: Record<DonemKapanisSeverity, string> = {
  BLOCKER: "⛔",
  WARNING: "⚠",
  INFO: "ℹ"
};

export function formatSeverityLabel(severity: DonemKapanisSeverity): string {
  return SEVERITY_LABELS[severity] ?? severity;
}

export function severityClassName(severity: DonemKapanisSeverity): string {
  switch (severity) {
    case "BLOCKER":
      return "kapanis-severity kapanis-severity--blocker";
    case "WARNING":
      return "kapanis-severity kapanis-severity--warning";
    default:
      return "kapanis-severity kapanis-severity--info";
  }
}

export function mapActionRouteToAppPath(route: string): string {
  const normalized = route.trim();
  if (!normalized || normalized === "/") {
    return "/";
  }
  if (normalized.startsWith("/personelmedisa")) {
    return normalized.replace("/personelmedisa", "") || "/";
  }
  return normalized.startsWith("/") ? normalized : `/${normalized}`;
}

export function currentMonthParts() {
  const now = new Date();
  return {
    ay: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`,
    yil: now.getFullYear(),
    ayNum: now.getMonth() + 1
  };
}

export function parseAyValue(ay: string): { yil: number; ay: number } | null {
  const match = /^(\d{4})-(\d{2})$/.exec(ay.trim());
  if (!match) {
    return null;
  }
  const yil = Number.parseInt(match[1], 10);
  const ayNum = Number.parseInt(match[2], 10);
  if (Number.isNaN(yil) || Number.isNaN(ayNum) || ayNum < 1 || ayNum > 12) {
    return null;
  }
  return { yil, ay: ayNum };
}
