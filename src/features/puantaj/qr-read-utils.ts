import type { ManagerQrAttendanceItem } from "../../types/self-service";

export function istanbulToday(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Istanbul" }).format(new Date());
}

export function istanbulDateDaysAgo(days: number): string {
  const now = new Date();
  now.setDate(now.getDate() - days);
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Istanbul" }).format(now);
}

export function formatQrTime(value: string | null): string {
  if (!value) return "—";
  return new Intl.DateTimeFormat("tr-TR", {
    timeZone: "Europe/Istanbul",
    hour: "2-digit",
    minute: "2-digit"
  }).format(new Date(value));
}

export function qrAttendanceStatus(item: ManagerQrAttendanceItem, today = istanbulToday()): string {
  if (item.branch_mismatch) return "Şube uyuşmazlığı";
  if (item.date_from !== today) {
    if (item.missing_entry) return "Eksik giriş";
    if (item.missing_exit) return "Eksik çıkış";
    return "Tamamlandı";
  }
  if (item.missing_entry || item.missing_exit) return "Eksik okutma";
  return item.inside ? "İçeride" : "Çıktı";
}

export function qrReadErrorMessage(cause: unknown, historical = false): string {
  if (cause instanceof Error && "status" in cause) {
    const status = (cause as { status?: unknown }).status;
    if (status === 401 || status === 403) {
      return historical
        ? "QR giriş / çıkış geçmişini görüntüleme yetkiniz yok."
        : "QR giriş / çıkış bilgilerini görüntüleme yetkiniz yok.";
    }
  }
  return historical ? "QR giriş / çıkış geçmişi alınamadı." : "QR giriş / çıkış bilgileri alınamadı.";
}
